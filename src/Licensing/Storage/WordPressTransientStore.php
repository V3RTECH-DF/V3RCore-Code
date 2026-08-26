<?php
declare(strict_types=1);

namespace V3R\Core\Licensing\Storage;

/**
 * Implementação de produção do KeyValueStoreInterface sobre get_transient()/
 * set_transient() — usado só para o marcador do cache de 12h descrito em
 * docs/api-contract.md §5 (nunca para o estado propriamente dito, que é
 * persistente e vive em WordPressOptionStore).
 */
final class WordPressTransientStore implements KeyValueStoreInterface {

	/**
	 * @return mixed
	 */
	public function get( string $key ) {
		$value = get_transient( $key );

		return false === $value ? null : $value;
	}

	/**
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $expirationSeconds
	 */
	public function set( string $key, $value, int $expirationSeconds = 0 ): void {
		set_transient( $key, $value, $expirationSeconds );
	}

	public function delete( string $key ): void {
		delete_transient( $key );
	}
}
