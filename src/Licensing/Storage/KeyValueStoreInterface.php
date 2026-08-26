<?php
declare(strict_types=1);

namespace V3R\Core\Licensing\Storage;

/**
 * Abstração mínima sobre um par chave/valor persistente, só para permitir
 * testar LicenseStorage sem WordPress carregado. Implementações de produção
 * usam get_option()/update_option() (persistente) ou get_transient()/
 * set_transient() (com expiração, para o cache de 12h de
 * docs/api-contract.md §5); testes injetam uma implementação em memória.
 */
interface KeyValueStoreInterface {

	/**
	 * @return mixed Null quando a chave não existe (ou expirou, no caso de transient).
	 */
	public function get( string $key );

	/**
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $expirationSeconds 0 = sem expiração (irrelevante para options).
	 */
	public function set( string $key, $value, int $expirationSeconds = 0 ): void;

	public function delete( string $key ): void;
}
