<?php
/**
 * Stubs mínimos de `admin_url()`, `wp_safe_redirect()`, `wp_unslash()` e
 * `sanitize_text_field()` — só o suficiente para testar
 * `Admin\Nav\LegacyRedirects` (V3RCore-Code#35) sem WordPress carregado.
 *
 * `admin_url()` aqui é o ponto que prova subdiretório: o valor devolvido
 * é controlável por `$GLOBALS['v3r_core_test_admin_url_prefix']`, para um
 * teste simular `https://example.com/wp/wp-admin/` (WordPress instalado
 * em `/wp`) e provar que `LegacyRedirects` usou esta função — nunca um
 * caminho `/wp-admin/` escrito à mão, que quebraria nesse cenário.
 *
 * `wp_safe_redirect()` só grava os argumentos recebidos, sem enviar
 * cabeçalho nenhum (não há resposta HTTP real em PHPUnit).
 */

declare(strict_types=1);

if ( ! function_exists( 'admin_url' ) ) {
	$GLOBALS['v3r_core_test_admin_url_prefix'] = 'https://example.test/wp-admin/';

	function admin_url( string $path = '', string $scheme = 'admin' ): string {
		return $GLOBALS['v3r_core_test_admin_url_prefix'] . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	$GLOBALS['v3r_core_test_safe_redirects'] = array();

	function wp_safe_redirect( string $location, int $status = 302 ): bool {
		$GLOBALS['v3r_core_test_safe_redirects'][] = $location;

		return true;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( preg_replace( '/<[^>]*>/', '', $value ) ?? $value );
	}
}
