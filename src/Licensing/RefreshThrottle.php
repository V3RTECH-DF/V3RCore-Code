<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

use DateTimeImmutable;
use V3R\Core\Licensing\Storage\KeyValueStoreInterface;
use V3R\Core\Licensing\Storage\WordPressTransientStore;

/**
 * Throttle local de `POST .../license/refresh` (docs/api-contract.md
 * §8.8.1): no máximo um refresh de verdade por minuto, por produto, por
 * instalação. Cortesia com o servidor de licenças e proteção do próprio
 * cliente contra a própria cota de rate limiting — nunca controle de
 * acesso, e nunca produz um dos códigos de erro de §8.9.
 *
 * Independente do cache de 12h de LicenseStorage (esse é sobre a política
 * do protocolo externo, §5; este é sobre a rajada de cliques no protocolo
 * interno, §8) — propositalmente uma classe à parte.
 */
final class RefreshThrottle {

	public const WINDOW_SECONDS = 60;

	/** @var string */
	private $key;

	/** @var KeyValueStoreInterface */
	private $store;

	public function __construct( string $productSlug, ?KeyValueStoreInterface $store = null ) {
		$this->key   = 'v3r_core_license_refresh_throttle_' . $productSlug;
		$this->store = $store ?? new WordPressTransientStore();
	}

	/**
	 * Segundos restantes até um novo refresh de verdade ser permitido, ou
	 * null quando não há throttle em curso (a chamada pode contatar o
	 * servidor agora).
	 */
	public function secondsRemaining( ?DateTimeImmutable $now = null ): ?int {
		$now = $now ?? new DateTimeImmutable();

		$lastAttempt = $this->store->get( $this->key );

		if ( ! is_int( $lastAttempt ) ) {
			return null;
		}

		$remaining = self::WINDOW_SECONDS - ( $now->getTimestamp() - $lastAttempt );

		return $remaining > 0 ? $remaining : null;
	}

	/**
	 * Marca "acabamos de tentar um refresh de verdade agora" — chamado
	 * antes da chamada externa sair, para até uma falha de rede também
	 * contar dentro da janela (evita retentativa imediata em rajada).
	 */
	public function markAttempt( ?DateTimeImmutable $now = null ): void {
		$now = $now ?? new DateTimeImmutable();

		$this->store->set( $this->key, $now->getTimestamp(), self::WINDOW_SECONDS );
	}
}
