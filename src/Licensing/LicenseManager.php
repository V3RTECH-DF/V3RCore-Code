<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

/**
 * Orquestra ativação, validação periódica e cache do estado de licença.
 * Decide QUANDO chamar o servidor (política de cache de 12h, refresh
 * forçado) e o que fazer quando ele falha (grace period) — não decide o
 * formato de rede (ApiClientInterface) nem se o site recebe update
 * (V3R\Core\Updater\UpdateGate).
 *
 * TODO(fatia-2): implementar ativação/desativação/validação de verdade,
 * usando ApiClientInterface + SignatureVerifier + LicenseStorage. Nesta
 * fatia, devolve sempre o estado neutro (nunca ativado) — o plugin
 * consumidor continua funcionando normalmente.
 */
class LicenseManager {

	/** @var string */
	private $productSlug;

	/** @var ApiClientInterface */
	private $apiClient;

	/** @var LicenseStorage */
	private $storage;

	public function __construct( string $productSlug, ApiClientInterface $apiClient, LicenseStorage $storage ) {
		$this->productSlug = $productSlug;
		$this->apiClient   = $apiClient;
		$this->storage     = $storage;
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

	/**
	 * TODO(fatia-2): ativar a licença ($licenseKey) para este site.
	 *
	 * @throws \LogicException Sempre, nesta fatia — lógica de rede ainda não existe.
	 */
	public function activate( string $licenseKey ): LicenseState {
		throw new \LogicException( 'não implementado' );
	}

	/**
	 * TODO(fatia-2): liberar a cota deste site.
	 *
	 * @throws \LogicException Sempre, nesta fatia — lógica de rede ainda não existe.
	 */
	public function deactivate(): LicenseState {
		throw new \LogicException( 'não implementado' );
	}

	/**
	 * TODO(fatia-2): revalidar contra o servidor, respeitando o cache de
	 * 12h a menos que $force seja verdadeiro.
	 *
	 * @throws \LogicException Sempre, nesta fatia — lógica de rede ainda não existe.
	 */
	public function refresh( bool $force = false ): LicenseState {
		throw new \LogicException( 'não implementado' );
	}
}
