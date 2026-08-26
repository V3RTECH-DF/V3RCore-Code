<?php
declare(strict_types=1);

namespace V3R\Core\Licensing\Storage;

/**
 * Implementação de produção do KeyValueStoreInterface para dado persistente
 * (wp_options) — usado para o estado corrente da licença, que precisa
 * sobreviver a reinício de cron e a falha de rede.
 *
 * DECISÃO REGISTRADA (ver contrato da fatia 2a): a chave de licença em texto
 * pleno é persistida aqui, em wp_options. Isso significa que qualquer
 * processo com acesso ao banco do site (outro plugin malicioso com
 * privilégio suficiente, um dump de backup, um DBA) pode lê-la. Não há como
 * evitar isso sem criptografia — e criptografia caseira aqui não protegeria
 * nada de verdade: a própria biblioteca precisa decifrar a chave a cada
 * chamada ao servidor, então a "chave que protege a chave" teria que estar
 * acessível ao mesmo processo, no mesmo lugar. Isso não é diferente de
 * qualquer outro segredo de aplicação que o próprio site precisa usar em
 * runtime (ex.: uma API key de terceiro salva em options por um plugin
 * qualquer) — a superfície de proteção real é a segurança do banco e do
 * host, não uma ofuscação no PHP.
 */
final class WordPressOptionStore implements KeyValueStoreInterface {

	/**
	 * @return mixed
	 */
	public function get( string $key ) {
		$value = get_option( $key, null );

		return false === $value ? null : $value;
	}

	/**
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $expirationSeconds Ignorado — options não expiram.
	 */
	public function set( string $key, $value, int $expirationSeconds = 0 ): void {
		update_option( $key, $value, false );
	}

	public function delete( string $key ): void {
		delete_option( $key );
	}
}
