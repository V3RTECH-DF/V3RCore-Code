<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Licensing\Storage;

use V3R\Core\Licensing\Storage\KeyValueStoreInterface;

/**
 * KeyValueStoreInterface em memória, para teste — simula expiração de
 * transient de verdade via um "relógio" injetável (sem depender de sleep()
 * em teste).
 */
final class InMemoryKeyValueStore implements KeyValueStoreInterface {

	/** @var array<string, mixed> */
	private $values = array();

	/** @var array<string, int> Timestamp de expiração (0 = nunca), por chave. */
	private $expiresAt = array();

	/** @var callable */
	private $clock;

	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? static function (): int {
			return time();
		};
	}

	/**
	 * @return mixed
	 */
	public function get( string $key ) {
		if ( ! array_key_exists( $key, $this->values ) ) {
			return null;
		}

		$expiresAt = $this->expiresAt[ $key ] ?? 0;

		if ( 0 !== $expiresAt && $expiresAt <= ( $this->clock )() ) {
			unset( $this->values[ $key ], $this->expiresAt[ $key ] );

			return null;
		}

		return $this->values[ $key ];
	}

	/**
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $expirationSeconds
	 */
	public function set( string $key, $value, int $expirationSeconds = 0 ): void {
		$this->values[ $key ]    = $value;
		$this->expiresAt[ $key ] = 0 === $expirationSeconds ? 0 : ( $this->clock )() + $expirationSeconds;
	}

	public function delete( string $key ): void {
		unset( $this->values[ $key ], $this->expiresAt[ $key ] );
	}
}
