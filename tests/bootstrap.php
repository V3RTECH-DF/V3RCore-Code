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

// Stubs mínimos do protocolo REST interno (fatia 2b, docs/api-contract.md
// §8), só o suficiente para testar
// Rest\LicenseController::permission_callback_read()/permission_callback_manage()
// sem WordPress carregado. Controláveis por globals, mesmo padrão do
// V3RLicense (tests/Support/WpStubs.php) para não inventar um segundo jeito
// de simular current_user_can()/wp_verify_nonce() na casa.
//
// $GLOBALS['v3r_core_test_current_user_can'] aceita duas formas:
// - bool: concede/nega todas as capabilities (comportamento original);
// - array<string>: concede só as capabilities listadas — necessário para
// testar isolamento por operação (issue #9: usuário com leitura mas sem
// gestão).
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		$granted = $GLOBALS['v3r_core_test_current_user_can'] ?? false;

		if ( is_array( $granted ) ) {
			return in_array( $capability, $granted, true );
		}

		return (bool) $granted;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * @return int|false
	 */
	function wp_verify_nonce( string $nonce, string $action = '' ) {
		$expected = $GLOBALS['v3r_core_test_valid_nonce'] ?? null;

		return null !== $expected && $expected === $nonce ? 1 : false;
	}
}

// As duas classes-stub (WP_Error, WP_REST_Request) ficam em arquivos
// próprios — WordPress.Files (via phpcs) exige um objeto por arquivo e não
// mistura função com classe no mesmo arquivo.
require_once __DIR__ . '/Support/WpErrorStub.php';
require_once __DIR__ . '/Support/WpRestRequestStub.php';

// Stubs específicos do Plugin Update Checker real (V3RCore-Code#8 e #10)
// — ver o docblock do próprio arquivo para o porquê de existirem à parte.
require_once __DIR__ . '/Support/PucFunctionStubs.php';

// Stub de user_can(), fiel o bastante para reentrar em user_has_cap — só
// assim o teste de não-recursão da Licensing\CapabilityGate prova algo
// (V3RCore-Code#12). Ver o docblock do próprio arquivo.
require_once __DIR__ . '/Support/WpUserStub.php';
require_once __DIR__ . '/Support/CapabilityFunctionStubs.php';

// Stub de add_options_page(), só para testar AdminPage::registerMenu()
// (rótulo do menu por produto, V3RCore-Code#11) sem WordPress carregado.
// Ver o docblock do próprio arquivo.
require_once __DIR__ . '/Support/AdminMenuFunctionStubs.php';
require_once __DIR__ . '/Support/FrontendAssetFunctionStubs.php';

// Stub de get_file_data(), só para testar Support\PluginVersion::resolve()
// (v3rtech-scripts#32) sem WordPress carregado. Ver o docblock do próprio
// arquivo.
require_once __DIR__ . '/Support/FileDataFunctionStubs.php';
