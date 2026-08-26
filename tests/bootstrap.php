<?php
/**
 * Bootstrap dos testes: só o autoload do Composer. A lib não depende do
 * WordPress para os testes desta fatia (SiteIdentity, LicenseState,
 * UpdateGate são puros); onde uma função do WP é opcionalmente lida
 * (wp_get_environment_type), o código já trata a ausência dela com
 * function_exists().
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Stub mínimo de wp_get_environment_type(): o WordPress real lê a constante
// WP_ENVIRONMENT_TYPE internamente; aqui expomos um valor mutável por
// variável global para os testes poderem simular cada ambiente sem definir
// constantes globais irreversíveis entre casos de teste.
if ( ! function_exists( 'wp_get_environment_type' ) ) {
	$GLOBALS['v3r_core_test_environment_type'] = 'production';

	function wp_get_environment_type(): string {
		return $GLOBALS['v3r_core_test_environment_type'];
	}
}

// Stubs mínimos de options/transients, só para permitir testar Bootstrap +
// LicenseStorage de ponta a ponta sem WordPress carregado (BootstrapTest).
// Nenhum teste desta fatia depende do comportamento fino de expiração via
// estes stubs — isso é coberto de verdade por InMemoryKeyValueStore, que
// simula expiração com um relógio controlável.
if ( ! function_exists( 'get_option' ) ) {
	$GLOBALS['v3r_core_test_options'] = array();

	/**
	 * @param string $name
	 * @param mixed  $defaultValue
	 * @return mixed
	 */
	function get_option( string $name, $defaultValue = false ) {
		return $GLOBALS['v3r_core_test_options'][ $name ] ?? $defaultValue;
	}

	/**
	 * @param string $name
	 * @param mixed  $value
	 * @param mixed  $autoload
	 */
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['v3r_core_test_options'][ $name ] = $value;

		return true;
	}

	function delete_option( string $name ): bool {
		unset( $GLOBALS['v3r_core_test_options'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	$GLOBALS['v3r_core_test_transients'] = array();

	/**
	 * @return mixed
	 */
	function get_transient( string $name ) {
		return $GLOBALS['v3r_core_test_transients'][ $name ] ?? false;
	}

	/**
	 * @param string $name
	 * @param mixed  $value
	 * @param int    $expiration
	 */
	function set_transient( string $name, $value, int $expiration = 0 ): bool {
		$GLOBALS['v3r_core_test_transients'][ $name ] = $value;

		return true;
	}

	function delete_transient( string $name ): bool {
		unset( $GLOBALS['v3r_core_test_transients'][ $name ] );

		return true;
	}
}
