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
