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

	public function test_accessMap_inclui_tela_oculta_permitida_e_negada(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'oculta-permitida', 'Oculta permitida', null, 'perm_a', null, true ) );
		$registry->add( new Screen( 'oculta-negada', 'Oculta negada', null, 'perm_b', null, true ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array( 'perm_a' ) ) );

		self::assertSame(
			array(
				'oculta-permitida' => true,
				'oculta-negada'    => false,
			),
			$navigation->accessMap()
		);
	}

	public function test_accessMap_inclui_tela_visivel_permitida_e_negada(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'visivel-permitida', 'Visível permitida', null, 'perm_a' ) );
		$registry->add( new Screen( 'visivel-negada', 'Visível negada', null, 'perm_b' ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array( 'perm_a' ) ) );

		self::assertSame(
			array(
				'visivel-permitida' => true,
				'visivel-negada'    => false,
			),
			$navigation->accessMap()
		);
	}

	/**
	 * O critério que mais tenta ser "simplificado": tela sem permissão não
	 * pode sumir do mapa — precisa aparecer com `false`, senão fica
	 * indistinguível de slug inexistente para quem consulta por chave.
	 */
	public function test_accessMap_nao_omite_tela_sem_permissao(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'negada', 'Negada', null, 'perm_negada' ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array() ) );

		$map = $navigation->accessMap();

		self::assertArrayHasKey( 'negada', $map );
		self::assertFalse( $map['negada'] );
	}

	public function test_accessMap_consulta_screen_access_no_maximo_uma_vez_por_permissao(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_compartilhada' ) );
		$registry->add( new Screen( 'b', 'B', null, 'perm_compartilhada' ) );
		$registry->add( new Screen( 'c', 'C', null, 'perm_compartilhada', null, true ) );

		$access     = new CountingScreenAccess( array( 'perm_compartilhada' ) );
		$navigation = new Navigation( $registry, $access );

		$navigation->accessMap();

		self::assertSame( 1, $access->callsFor( 'perm_compartilhada' ) );
	}

	/** Mesmo slug declarado duas vezes: uma entrada só, a do primeiro registro. */
	public function test_accessMap_nao_duplica_entrada_para_slug_repetido(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'repetida', 'Primeira', null, 'perm_a' ) );
		$registry->add( new Screen( 'repetida', 'Segunda', null, 'perm_b' ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array( 'perm_b' ) ) );

		$map = $navigation->accessMap();

		self::assertCount( 1, $map );
		self::assertFalse( $map['repetida'], 'Deve valer a permissão do primeiro registro (perm_a, negada), não a do segundo.' );
	}

	public function test_tree_sem_superficie_informada_e_identica_a_antes(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'perm' ) );
		$registry->add( new Screen( 'so-painel', 'Só painel', null, 'perm', null, false, array( 'painel' ) ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array( 'perm' ) ) );

		self::assertSame( $navigation->tree(), $navigation->tree( null ) );
		self::assertCount( 2, $navigation->tree() );
	}

	/** Tela declarada só na superfície 'painel' não aparece na árvore de 'publico', e aparece na de 'painel'. */
	public function test_tree_filtra_por_superficie(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'so-painel', 'Só painel', null, 'perm', null, false, array( 'painel' ) ) );
		$registry->add( new Screen( 'ambas', 'Ambas', null, 'perm' ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array( 'perm' ) ) );

		$publico = $navigation->tree( 'publico' );
		self::assertCount( 1, $publico );
		self::assertSame( 'ambas', $publico[0]['slug'] );

		$painel = $navigation->tree( 'painel' );
		self::assertCount( 2, $painel );
	}

	public function test_accessMap_sem_superficie_informada_e_identico_a_antes(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'perm' ) );
		$registry->add( new Screen( 'so-painel', 'Só painel', null, 'perm', null, false, array( 'painel' ) ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array( 'perm' ) ) );

		self::assertSame( $navigation->accessMap(), $navigation->accessMap( null ) );
		self::assertCount( 2, $navigation->accessMap() );
	}

	/** Tela fora da superfície pedida é OMITIDA do mapa — não vem com `false` (o oposto da regra de "sem permissão"). */
	public function test_accessMap_omite_tela_fora_da_superficie_em_vez_de_negar(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'so-painel', 'Só painel', null, 'perm', null, false, array( 'painel' ) ) );
		$registry->add( new Screen( 'ambas', 'Ambas', null, 'perm' ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array( 'perm' ) ) );

		$map = $navigation->accessMap( 'publico' );

		self::assertArrayNotHasKey( 'so-painel', $map, 'Fora da superfície: omitida, não presente com false.' );
		self::assertSame( array( 'ambas' => true ), $map );
	}

	/** Controle negativo: DENTRO da superfície pedida, sem permissão, a tela continua vindo com `false` — a regra do §5 segue valendo. */
	public function test_accessMap_dentro_da_superficie_sem_permissao_continua_com_false(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'so-painel-negada', 'Só painel, negada', null, 'perm_negada', null, false, array( 'painel' ) ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array() ) );

		$map = $navigation->accessMap( 'painel' );

		self::assertArrayHasKey( 'so-painel-negada', $map );
		self::assertFalse( $map['so-painel-negada'] );
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
			NavCapabilityGate::rootCapabilityFor( 'v3rflow' ),
			$page['capability'],
			"A entrada visível não pode usar 'read' — visibilidade é derivada dos filhos (§5), via rootCapabilityFor()."
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

	/**
	 * A forma recomendada pelo §3: uma função no lugar de um objeto
	 * `ScreenAccess`. Cobre tree(), canView() e accessMap() com a mesma
	 * função — as três precisam responder por ela.
	 */
	public function test_access_como_funcao_responde_pela_arvore_pelo_canView_e_pelo_accessMap(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'perm_a' ) );
		$registry->add( new Screen( 'y', 'Y', null, 'perm_b' ) );

		$decider = static function ( string $permission ): bool {
			return 'perm_a' === $permission;
		};

		$navigation = new Navigation( $registry, $decider );

		self::assertSame(
			array(
				array(
					'type'  => 'screen',
					'slug'  => 'x',
					'label' => 'X',
				),
			),
			$navigation->tree(),
			'Passar função funciona: a árvore responde por ela.'
		);
		self::assertTrue( $navigation->canView( 'x' ) );
		self::assertFalse( $navigation->canView( 'y' ) );
		self::assertSame(
			array(
				'x' => true,
				'y' => false,
			),
			$navigation->accessMap()
		);
	}

	/**
	 * A guarda de acesso direto (`NavCapabilityGate`, via `renderMenu()`)
	 * também responde pela função — não só a árvore. Prova que a
	 * normalização alcança as três consumidoras, não só duas.
	 */
	public function test_access_como_funcao_tambem_alimenta_a_guarda_de_acesso_direto(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'perm_a' ) );

		$decider = static function ( string $permission ): bool {
			return 'perm_a' === $permission;
		};

		$navigation = new Navigation( $registry, $decider );
		$navigation->registerMenu( new MenuEntry( 'RIT360 Flow', 'v3rflow', Family::RIT ) );
		$navigation->renderMenu();

		$pagina = $GLOBALS['v3r_core_test_registered_submenu_pages'][0];
		self::assertSame( NavCapabilityGate::capabilityFor( 'x' ), $pagina['capability'] );
	}

	/**
	 * Passar objeto `ScreenAccess` continua funcionando, igual a antes desta
	 * mudança — a normalização não pode quebrar o consumidor existente.
	 */
	public function test_access_como_objeto_screenaccess_continua_funcionando(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'perm' ) );

		$navigation = new Navigation( $registry, new CountingScreenAccess( array( 'perm' ) ) );

		self::assertTrue( $navigation->canView( 'x' ) );
	}

	/**
	 * O respondente é consultado o MESMO número de vezes nas duas formas —
	 * normalizar não pode introduzir consulta extra (critério de aceite).
	 * Comparação direta: mesma árvore de telas, mesma permissão
	 * compartilhada, uma vez via função contadora, outra via
	 * `CountingScreenAccess`.
	 */
	public function test_normalizar_nao_introduz_consulta_extra_em_relacao_ao_objeto(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_compartilhada' ) );
		$registry->add( new Screen( 'b', 'B', null, 'perm_compartilhada' ) );

		$chamadasViaFuncao = 0;
		$decider           = static function ( string $permission ) use ( &$chamadasViaFuncao ): bool {
			++$chamadasViaFuncao;
			return 'perm_compartilhada' === $permission;
		};

		( new Navigation( $registry, $decider ) )->tree();

		$objeto = new CountingScreenAccess( array( 'perm_compartilhada' ) );
		( new Navigation( $registry, $objeto ) )->tree();

		self::assertSame(
			$objeto->callsFor( 'perm_compartilhada' ),
			$chamadasViaFuncao,
			'A função foi consultada um número de vezes diferente do objeto equivalente.'
		);
	}

	/**
	 * Falha na construção, não silenciosamente depois: nem função, nem
	 * ScreenAccess.
	 */
	public function test_access_invalido_falha_na_construcao_com_mensagem_explicativa(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/ScreenAccess/' );

		// @phpstan-ignore-next-line argument.type (deliberadamente inválido — é o que o teste prova)
		new Navigation( new Registry(), 'nao-e-funcao-nem-objeto-valido-xyz' );
	}

	/** Controle negativo: string que É um nome de função válido não deve ser recusada. */
	public function test_access_como_nome_de_funcao_existente_e_aceito(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'perm' ) );

		$navigation = new Navigation( $registry, __NAMESPACE__ . '\\v3r_core_test_sempre_permite' );

		self::assertTrue( $navigation->canView( 'x' ) );
	}
}
