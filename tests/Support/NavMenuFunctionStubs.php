<?php
/**
 * Stubs mínimos de `add_menu_page()` e `add_submenu_page()`, só o
 * suficiente para testar `Admin\Nav\Navigation::registerMenu()`
 * (V3RCore-Code#35) sem WordPress carregado — capturam os argumentos
 * recebidos em globals, para o teste inspecionar sem um wp-admin de
 * verdade.
 *
 * `add_submenu_page()` de verdade devolve o hook suffix da tela, ou
 * `false` quando a capability é inválida; aqui devolve sempre uma string
 * fixa — nenhum teste desta suíte depende do valor de retorno.
 */

declare(strict_types=1);

if ( ! function_exists( 'add_menu_page' ) ) {
	$GLOBALS['v3r_core_test_registered_menu_pages'] = array();

	/**
	 * @param string         $pageTitle
	 * @param string         $menuTitle
	 * @param string         $capability
	 * @param string         $menuSlug
	 * @param callable       $callback
	 * @param string         $iconUrl
	 * @param int|float|null $position
	 */
	function add_menu_page( string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable $callback, string $iconUrl = '', $position = null ): string {
		$GLOBALS['v3r_core_test_registered_menu_pages'][] = array(
			'page_title' => $pageTitle,
			'menu_title' => $menuTitle,
			'capability' => $capability,
			'menu_slug'  => $menuSlug,
			'icon_url'   => $iconUrl,
		);

		return $menuSlug;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	$GLOBALS['v3r_core_test_registered_submenu_pages'] = array();

	/**
	 * @param string|null $parentSlug
	 * @param string      $pageTitle
	 * @param string      $menuTitle
	 * @param string      $capability
	 * @param string      $menuSlug
	 * @param callable    $callback
	 */
	function add_submenu_page( $parentSlug, string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable $callback ): string {
		$GLOBALS['v3r_core_test_registered_submenu_pages'][] = array(
			'parent_slug' => $parentSlug,
			'page_title'  => $pageTitle,
			'menu_title'  => $menuTitle,
			'capability'  => $capability,
			'menu_slug'   => $menuSlug,
		);

		return $menuSlug;
	}
}
