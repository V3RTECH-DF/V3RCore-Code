<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\Family;
use V3R\Core\Admin\Nav\MenuEntry;
use V3R\Core\Admin\Nav\Navigation;
use V3R\Core\Admin\Nav\NavCapabilityGate;
use V3R\Core\Admin\Nav\Registry;
use V3R\Core\Admin\Nav\Screen;
use V3R\Core\Tests\Support\CountingScreenAccess;

/**
 * Cobre a fachada — o que ela delega (tree(), canView()) e o que
 * `renderMenu()` registra no WordPress (§6 e a camada 1 do §4). Segue o
 * mesmo padrão de teste de Licensing\AdminPageTest: `renderMenu()` é
 * chamado direto, sem depender do hook `admin_menu` disparar de verdade.
 */
final class NavigationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['v3r_core_test_puc_filters']              = array();
		$GLOBALS['v3r_core_test_registered_menu_pages']    = array();
		$GLOBALS['v3r_core_test_registered_submenu_pages'] = array();
	}

	public function test_tree_delega_para_o_registry_e_o_access(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'perm' ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array( 'perm' ) ) );

		self::assertSame(
			array(
				array(
					'type'  => 'screen',
					'slug'  => 'x',
					'label' => 'X',
				),
			),
			$navigation->tree()
		);
	}

	public function test_canView_delega_para_o_screen_access_pela_mesma_permissao(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'perm' ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array( 'perm' ) ) );

		self::assertTrue( $navigation->canView( 'x' ) );
	}

	public function test_canView_nega_tela_sem_permissao(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'perm' ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array() ) );

		self::assertFalse( $navigation->canView( 'x' ) );
	}

	/** Falha fechado: slug que não existe nunca é "visível". */
	public function test_canView_de_slug_desconhecido_e_falso(): void {
		$navigation = new Navigation( new Registry(), new CountingScreenAccess( array() ) );

		self::assertFalse( $navigation->canView( 'nao-existe' ) );
	}

	public function test_renderMenu_registra_a_entrada_unica_visivel(): void {
		$navigation = new Navigation( new Registry(), new CountingScreenAccess( array() ) );
		$navigation->registerMenu( new MenuEntry( 'RIT360 Flow', 'v3rflow', Family::RIT ) );
		$navigation->renderMenu();

		self::assertCount( 1, $GLOBALS['v3r_core_test_registered_menu_pages'] );

		$page = $GLOBALS['v3r_core_test_registered_menu_pages'][0];
		self::assertSame( 'RIT360 Flow', $page['page_title'] );
		self::assertSame( 'v3rflow', $page['menu_slug'] );
		self::assertStringStartsWith( 'data:image/svg+xml;base64,', $page['icon_url'] );
		self::assertSame(
			NavCapabilityGate::ROOT_CAPABILITY,
			$page['capability'],
			"A entrada visível não pode usar 'read' — visibilidade é derivada dos filhos (§5), via ROOT_CAPABILITY."
		);
	}

	public function test_renderMenu_registra_uma_pagina_oculta_por_tela(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'pessoas-cadastro', 'Cadastro', null, 'perm_a' ) );
		$registry->add( new Screen( 'pessoas-relatorio', 'Relatório', null, 'perm_b' ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array( 'perm_a', 'perm_b' ) ) );
		$navigation->registerMenu( new MenuEntry( 'RIT360 Flow', 'v3rflow', Family::RIT ) );
		$navigation->renderMenu();

		self::assertCount( 2, $GLOBALS['v3r_core_test_registered_submenu_pages'] );

		$cadastro = $GLOBALS['v3r_core_test_registered_submenu_pages'][0];
		self::assertNull( $cadastro['parent_slug'], 'A página precisa ser oculta — sem parent_slug.' );
		self::assertSame( NavCapabilityGate::capabilityFor( 'pessoas-cadastro' ), $cadastro['capability'] );
		self::assertSame( 'pessoas-cadastro', $cadastro['menu_slug'] );
	}

	/** Nenhuma tela declarada: a entrada visível ainda é registrada, sem página oculta nenhuma. */
	public function test_renderMenu_sem_telas_nao_registra_pagina_oculta(): void {
		$navigation = new Navigation( new Registry(), new CountingScreenAccess( array() ) );
		$navigation->registerMenu( new MenuEntry( 'RIT360 Flow', 'v3rflow', Family::RIT ) );
		$navigation->renderMenu();

		self::assertCount( 1, $GLOBALS['v3r_core_test_registered_menu_pages'] );
		self::assertCount( 0, $GLOBALS['v3r_core_test_registered_submenu_pages'] );
	}

	/** Sem registerMenu() ter sido chamado, renderMenu() não registra nada — não quebra também. */
	public function test_renderMenu_sem_registerMenu_previo_e_no_op(): void {
		$navigation = new Navigation( new Registry(), new CountingScreenAccess( array() ) );
		$navigation->renderMenu();

		self::assertCount( 0, $GLOBALS['v3r_core_test_registered_menu_pages'] );
		self::assertCount( 0, $GLOBALS['v3r_core_test_registered_submenu_pages'] );
	}
}
