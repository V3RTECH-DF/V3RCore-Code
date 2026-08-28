<?php
/**
 * Stub mínimo de `add_options_page()`, só o suficiente para testar
 * `Licensing\AdminPage::registerMenu()` sem WordPress carregado
 * (V3RCore-Code#11) — captura os argumentos recebidos (rótulo do menu,
 * título da página) num global, para o teste inspecionar sem precisar de
 * um wp-admin de verdade.
 *
 * `add_options_page()` de verdade devolve o hook suffix da tela; aqui
 * devolve uma string fixa, porque nenhum teste desta suíte depende do
 * valor de retorno.
 */

declare(strict_types=1);

if ( ! function_exists( 'add_options_page' ) ) {
	$GLOBALS['v3r_core_test_registered_options_pages'] = array();

	function add_options_page( string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable $callback ): string {
		$GLOBALS['v3r_core_test_registered_options_pages'][] = array(
			'page_title' => $pageTitle,
			'menu_title' => $menuTitle,
			'capability' => $capability,
			'menu_slug'  => $menuSlug,
		);

		return 'settings_page_' . $menuSlug;
	}
}
