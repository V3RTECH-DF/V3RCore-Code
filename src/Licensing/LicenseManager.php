<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

use DateTimeImmutable;
use V3R\Core\Updater\UpdateGate;

/**
 * Orquestra ativação, validação periódica e cache do estado de licença.
 * Decide QUANDO chamar o servidor (política de cache de 12h, refresh
 * forçado) e o que fazer quando ele falha (grace period) — não decide o
 * formato de rede (ApiClientInterface) nem se o site recebe update
 * (V3R\Core\Updater\UpdateGate).
 *
 * Regra central, não negociável (docs/api-contract.md §6/§7): uma resposta
 * que NÃO é uma confirmação assinada de "expired"/"revoked"/"invalid" é
 * tratada, para efeito de grace period, como "não sei se ainda vale" —
 * timeout, 5xx, JSON malformado, assinatura inválida E qualquer erro de
 * negócio do protocolo (invalid_key, domain_not_activated, product_mismatch,
 * rate_limited) entram todos no mesmo caminho de grace period. Só um
 * payload validamente assinado dizendo "expired"/"revoked"/"invalid" é
 * tratado como "sei que não vale mais" (suspende sem grace). Essa distinção
 * é conservadora de propósito: qualquer ambiguidade sobre o estado real da
 * licença nunca pode custar a atualização do site antes do prazo de graça.
 */
class LicenseManager {

	/** @var string */
	private $productSlug;

	/** @var ApiClientInterface */
	private $apiClient;

	/** @var LicenseStorage */
	private $storage;

	/** @var string */
	private $pluginVersion;

	/** @var callable */
	private $siteUrlProvider;

	/** @var callable */
	private $wpVersionProvider;

	/**
	 * @param string             $productSlug
	 * @param ApiClientInterface $apiClient
	 * @param LicenseStorage     $storage
	 * @param string             $pluginVersion
	 * @param callable|null      $siteUrlProvider   Sem argumentos, devolve string. Default: site_url() do WordPress, ou "" fora dele.
	 * @param callable|null      $wpVersionProvider Sem argumentos, devolve string. Default: get_bloginfo('version'), ou "" fora do WordPress.
	 */
	public function __construct(
		string $productSlug,
		ApiClientInterface $apiClient,
		LicenseStorage $storage,
		string $pluginVersion,
		?callable $siteUrlProvider = null,
		?callable $wpVersionProvider = null
	) {
		$this->productSlug       = $productSlug;
		$this->apiClient         = $apiClient;
		$this->storage           = $storage;
		$this->pluginVersion     = $pluginVersion;
		$this->siteUrlProvider   = $siteUrlProvider ?? static function (): string {
			return function_exists( 'site_url' ) ? (string) site_url() : '';
		};
		$this->wpVersionProvider = $wpVersionProvider ?? static function (): string {
			return function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
		};
	}

	/**
	 * Estado corrente, lido do cache local — nunca bate na rede aqui
	 * (ver docs/api-contract.md, política de cache).
	 */
	public function getState(): LicenseState {
		return $this->storage->load( $this->productSlug );
	}

	/**
	 * Exposto para o UpdateChecker (fatia 2) montar o PucFactory sem
	 * precisar reconstruir o client.
	 */
	public function getApiClient(): ApiClientInterface {
		return $this->apiClient;
	}

	public function getStorage(): LicenseStorage {
		return $this->storage;
	}

	public function getProductSlug(): string {
		return $this->productSlug;
	}

	/**
	 * Ativa a licença para este site. Em sucesso, persiste o novo estado e
	 * marca o cache de 12h como fresco (o próprio activate já é um contato
	 * bem-sucedido). Em falha (de qualquer natureza — comunicação ou erro de
	 * negócio do servidor), NÃO altera o estado local: nada foi confirmado,
	 * então nada muda. A exceção sobe para o chamador decidir o que mostrar.
	 *
	 * @throws ApiException Repassada tal como veio do ApiClientInterface.
	 */
	public function activate( string $licenseKey, ?DateTimeImmutable $now = null ): LicenseState {
		$now = $now ?? new DateTimeImmutable();

		$response = $this->apiClient->activate(
			array(
				'license_key'    => $licenseKey,
				'product_slug'   => $this->productSlug,
				'site_url'       => $this->currentSiteUrl(),
				'plugin_version' => $this->pluginVersion,
				'php_version'    => PHP_VERSION,
				'wp_version'     => $this->currentWpVersion(),
			)
		);

		$state = $this->stateFromSignedPayload( $licenseKey, $response, $now );

		$this->storage->save( $state );
		$this->storage->markValidationCacheFresh();

		return $state;
	}

	/**
	 * Libera a cota deste site no servidor e limpa o estado local. Se o
	 * servidor recusar (ex.: domain_not_activated) ou não responder, o
	 * estado local É PRESERVADO — desativar sem confirmação do servidor
	 * deixaria a cota presa lá e o site "achando" que está livre.
	 *
	 * @throws ApiException Repassada tal como veio do ApiClientInterface.
	 */
	public function deactivate(): LicenseState {
		$current = $this->getState();

		$this->apiClient->deactivate(
			array(
				'license_key'  => $current->getKey(),
				'product_slug' => $this->productSlug,
				'site_url'     => $this->currentSiteUrl(),
			)
		);

		$this->storage->clear();

		return LicenseState::neutral( $this->productSlug );
	}

	/**
	 * Revalida contra o servidor, respeitando o cache de 12h a menos que
	 * $force seja verdadeiro (docs/api-contract.md §5). Nunca lança: falha
	 * de comunicação e erro de negócio ambíguo entram em grace period;
	 * confirmação assinada de expired/revoked/invalid suspende o update
	 * imediatamente, sem grace — em nenhum dos casos o site trava.
	 */
	public function refresh( bool $force = false, ?DateTimeImmutable $now = null ): LicenseState {
		$now = $now ?? new DateTimeImmutable();

		$current = $this->getState();

		if ( LicenseStatus::INACTIVE === $current->getStatus() ) {
			// Nunca houve ativação — não há o que revalidar.
			return $current;
		}

		if ( ! $force && $this->storage->hasFreshValidationCache() ) {
			return $current;
		}

		try {
			$response = $this->apiClient->validate(
				array(
					'license_key'  => $current->getKey(),
					'product_slug' => $this->productSlug,
					'site_url'     => $this->currentSiteUrl(),
				)
			);
		} catch ( ApiException $exception ) {
			return $this->applyCommunicationFailure( $current, $now );
		}

		$state = $this->stateFromSignedPayload( $current->getKey(), $response, $now );

		$this->storage->save( $state );
		$this->storage->markValidationCacheFresh();

		return $state;
	}

	/**
	 * Constrói o novo LicenseState a partir de um envelope { payload,
	 * signature } já verificado pelo ApiClientInterface (se chegou até
	 * aqui, a assinatura já é válida — ApiClientInterface nunca devolve uma
	 * resposta com assinatura ruim, ele lança ApiException antes).
	 *
	 * @param string               $licenseKey
	 * @param array<string, mixed> $response
	 * @param DateTimeImmutable    $now
	 */
	private function stateFromSignedPayload( string $licenseKey, array $response, DateTimeImmutable $now ): LicenseState {
		$payload = isset( $response['payload'] ) && is_array( $response['payload'] ) ? $response['payload'] : array();

		$status = isset( $payload['status'] ) && is_string( $payload['status'] ) ? $payload['status'] : LicenseStatus::INVALID;

		$expiresAt = isset( $payload['expires_at'] ) && is_string( $payload['expires_at'] )
			? $this->parseIso8601( $payload['expires_at'] )
			: null;

		$activationsUsed = isset( $payload['activations_used'] ) ? (int) $payload['activations_used'] : 0;

		$activationsMax = isset( $payload['activations_max'] ) && null !== $payload['activations_max']
			? (int) $payload['activations_max']
			: null;

		// Contato bem-sucedido e assinado: zera o grace period, qualquer que
		// seja o status confirmado (docs/api-contract.md §6).
		return new LicenseState(
			$licenseKey,
			$status,
			$expiresAt,
			$activationsUsed,
			$activationsMax,
			$now,
			null,
			$this->productSlug
		);
	}

	/**
	 * Falha de comunicação (ou erro de negócio ambíguo — ver o comentário de
	 * classe): mantém o último estado conhecido e entra/permanece em grace
	 * period, contado a partir do último contato bem-sucedido e assinado
	 * (docs/api-contract.md §6). Sem nunca ter havido contato bem-sucedido,
	 * não há grace a conceder — o estado permanece como está.
	 */
	private function applyCommunicationFailure( LicenseState $current, DateTimeImmutable $now ): LicenseState {
		$lastCheckedAt = $current->getLastCheckedAt();

		if ( null === $lastCheckedAt ) {
			return $current;
		}

		$graceUntil = $lastCheckedAt->modify( '+' . UpdateGate::GRACE_PERIOD_DAYS . ' days' );

		$updated = $current->withGraceUntil( $graceUntil );

		$this->storage->save( $updated );

		return $updated;
	}

	/**
	 * GET /update-check (docs/api-contract.md §2.4): consulta metadados da
	 * versão disponível. Não decide SE o site recebe essa atualização — é
	 * do V3R\Core\Updater\UpdateGate, chamado pelo integrador ANTES desta
	 * função (ver Updater\UpdateMetadataResolver). Site sem ativação local
	 * não tem chave de licença para enviar: devolve null sem contatar o
	 * servidor, em vez de mandar `license_key` vazia.
	 *
	 * BUG CORRIGIDO (validação ao vivo, fatia 2b): $installedVersion é
	 * SEMPRE obrigatório e SEMPRE vai em `plugin_version` — nunca em
	 * `version`. `plugin_version` é "o que está instalado agora" (o que o
	 * servidor usa para decidir se há novidade); `version`/$requestedVersion
	 * é "peça exatamente esta versão" (rollback explícito, §2.4) — os dois
	 * nomes antigos ($version único, ambíguo) faziam a chamada de rotina do
	 * WordPress mandar a versão instalada também como pedido de rollback, o
	 * servidor entendia "quero exatamente a que já tenho" e respondia
	 * update_available=false, mesmo com release mais nova publicada.
	 *
	 * NUNCA use $this->pluginVersion (fixado na construção do Bootstrap)
	 * aqui — só o chamador (Updater\PucBridge, via
	 * PucBridge::getInstalledVersion(), que lê o cabeçalho real do arquivo
	 * do plugin) sabe a versão de verdade instalada neste momento. As duas
	 * podem divergir se o hospedeiro atualizar o plugin sem atualizar o
	 * valor passado ao Bootstrap.
	 *
	 * @param string      $installedVersion Versão REALMENTE instalada agora — vai em `plugin_version`.
	 * @param string|null $requestedVersion Versão específica a fixar (rollback explícito, §2.4) — vai em
	 *                                      `version`. Ausente (o caso normal, "há algo mais novo?") não
	 *                                      manda esse campo — nunca preenchido com $installedVersion.
	 * @return array<string, mixed>|null Envelope { payload, signature } já
	 *                                    com assinatura verificada, ou null
	 *                                    quando não há licença ativada.
	 *
	 * @throws ApiException Repassada tal como veio do ApiClientInterface.
	 */
	public function checkForUpdate( string $installedVersion, ?string $requestedVersion = null ): ?array {
		$current = $this->getState();

		if ( LicenseStatus::INACTIVE === $current->getStatus() ) {
			return null;
		}

		$query = array(
			'product_slug'   => $this->productSlug,
			'license_key'    => $current->getKey(),
			'site_url'       => $this->currentSiteUrl(),
			'plugin_version' => $installedVersion,
		);

		if ( null !== $requestedVersion ) {
			$query['version'] = $requestedVersion;
		}

		return $this->apiClient->checkUpdate( $query );
	}

	private function currentSiteUrl(): string {
		return ( $this->siteUrlProvider )();
	}

	private function currentWpVersion(): string {
		return ( $this->wpVersionProvider )();
	}

	private function parseIso8601( string $value ): ?DateTimeImmutable {
		$parsed = DateTimeImmutable::createFromFormat( DateTimeImmutable::ATOM, $value );

		if ( false !== $parsed ) {
			return $parsed;
		}

		// Aceita também variações válidas de ISO 8601 que createFromFormat
		// com o formato ATOM exato pode rejeitar (ex.: offset "Z").
		try {
			return new DateTimeImmutable( $value );
		} catch ( \Exception $exception ) {
			return null;
		}
	}
}
