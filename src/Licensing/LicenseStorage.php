<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

use DateTimeImmutable;
use V3R\Core\Licensing\Storage\KeyValueStoreInterface;
use V3R\Core\Licensing\Storage\WordPressOptionStore;
use V3R\Core\Licensing\Storage\WordPressTransientStore;

/**
 * Decide onde e como o estado de licença persiste localmente: options do
 * WordPress para o estado corrente (precisa sobreviver a reinício de cron e
 * a falha de rede — ver docs/api-contract.md §5), transient só para o
 * marcador do cache de 12h.
 *
 * DECISÃO (ver contrato da fatia 2a — comentário completo em
 * Storage\WordPressOptionStore): a chave de licença fica em wp_options, em
 * texto pleno, porque a própria biblioteca precisa dela para as chamadas
 * seguintes. Quem tem acesso ao banco tem a chave — não há criptografia
 * caseira que resolva isso sem também dar à própria biblioteca a chave que
 * decifraria, o que não protegeria nada.
 *
 * Nunca grava a chave em log/exceção/debug: onde a chave precisar aparecer
 * fora desta classe, sempre via Support\LicenseKeyMasker::mask().
 */
class LicenseStorage {

	/**
	 * Janela do cache de validação (docs/api-contract.md §5): no máximo uma
	 * chamada de /validate a cada 12h por site, fora de refresh forçado.
	 */
	public const CACHE_TTL_SECONDS = 12 * 3600;

	/** @var string */
	private $optionName;

	/** @var string */
	private $transientName;

	/** @var KeyValueStoreInterface */
	private $options;

	/** @var KeyValueStoreInterface */
	private $cache;

	public function __construct(
		string $productSlug,
		?KeyValueStoreInterface $options = null,
		?KeyValueStoreInterface $cache = null
	) {
		// Prefixo por produto: dois plugins da casa no mesmo WordPress
		// precisam de options/transients distintos, cada um com sua própria
		// cópia (prefixada via Strauss) desta biblioteca.
		$this->optionName    = 'v3r_core_license_' . $productSlug;
		$this->transientName = 'v3r_core_license_cache_' . $productSlug;
		$this->options       = $options ?? new WordPressOptionStore();
		$this->cache         = $cache ?? new WordPressTransientStore();
	}

	/**
	 * Lê o estado persistido. Devolve LicenseState::neutral() quando nunca
	 * houve ativação, ou quando o dado salvo está corrompido/incompleto —
	 * nunca lança exceção por dado local ruim (nada trava o site por isso).
	 */
	public function load( string $productSlug ): LicenseState {
		$raw = $this->options->get( $this->optionName );

		if ( ! is_array( $raw ) ) {
			return LicenseState::neutral( $productSlug );
		}

		return $this->fromStorageArray( $raw, $productSlug );
	}

	/**
	 * Persiste o estado corrente. Chamado sempre que o LicenseManager
	 * conclui um contato com o servidor (bem-sucedido ou não) que muda o
	 * estado local.
	 */
	public function save( LicenseState $state ): void {
		$this->options->set( $this->optionName, $this->toStorageArray( $state ) );
	}

	/**
	 * Remove todo o estado local (usado por deactivate() bem-sucedido).
	 */
	public function clear(): void {
		$this->options->delete( $this->optionName );
		$this->clearValidationCache();
	}

	/**
	 * Verdadeiro quando o cache de 12h ainda está fresco — LicenseManager
	 * usa isto para decidir se pula a chamada de rede em refresh(force=false).
	 */
	public function hasFreshValidationCache(): bool {
		return null !== $this->cache->get( $this->transientName );
	}

	/**
	 * Marca "acabamos de validar com sucesso" pelos próximos 12h.
	 */
	public function markValidationCacheFresh(): void {
		$this->cache->set( $this->transientName, time(), self::CACHE_TTL_SECONDS );
	}

	/**
	 * Limpa o cache de 12h — usado em refresh forçado e em qualquer troca
	 * manual de chave, para o próximo ciclo não achar que já validou.
	 */
	public function clearValidationCache(): void {
		$this->cache->delete( $this->transientName );
	}

	public function getOptionName(): string {
		return $this->optionName;
	}

	public function getTransientName(): string {
		return $this->transientName;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function toStorageArray( LicenseState $state ): array {
		return array(
			'key'              => $state->getKey(),
			'status'           => $state->getStatus(),
			'expires_at'       => $this->formatDate( $state->getExpiresAt() ),
			'activations_used' => $state->getActivationsUsed(),
			'activations_max'  => $state->getActivationsMax(),
			'last_checked_at'  => $this->formatDate( $state->getLastCheckedAt() ),
			'grace_until'      => $this->formatDate( $state->getGraceUntil() ),
			'product_slug'     => $state->getProductSlug(),
		);
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	private function fromStorageArray( array $raw, string $productSlug ): LicenseState {
		return new LicenseState(
			isset( $raw['key'] ) && is_string( $raw['key'] ) ? $raw['key'] : '',
			isset( $raw['status'] ) && is_string( $raw['status'] ) ? $raw['status'] : LicenseStatus::INACTIVE,
			$this->parseDate( $raw['expires_at'] ?? null ),
			isset( $raw['activations_used'] ) ? (int) $raw['activations_used'] : 0,
			isset( $raw['activations_max'] ) && null !== $raw['activations_max'] ? (int) $raw['activations_max'] : null,
			$this->parseDate( $raw['last_checked_at'] ?? null ),
			$this->parseDate( $raw['grace_until'] ?? null ),
			isset( $raw['product_slug'] ) && is_string( $raw['product_slug'] ) ? $raw['product_slug'] : $productSlug
		);
	}

	private function formatDate( ?DateTimeImmutable $date ): ?string {
		return null === $date ? null : $date->format( DATE_ATOM );
	}

	/**
	 * @param mixed $value
	 */
	private function parseDate( $value ): ?DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat( DATE_ATOM, $value );

		return false === $parsed ? null : $parsed;
	}
}
