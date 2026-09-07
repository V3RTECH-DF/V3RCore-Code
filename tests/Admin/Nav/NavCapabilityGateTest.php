<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\NavCapabilityGate;
use V3R\Core\Admin\Nav\Registry;
use V3R\Core\Admin\Nav\Screen;
use V3R\Core\Admin\Nav\TreeBuilder;
use V3R\Core\Tests\Support\CountingScreenAccess;

/**
 * A guarda de acesso direto (§4). `add_filter()`/`apply_filters()` já
 * existem globalmente (tests/Support/PucFunctionStubs.php, sempre
 * carregado por tests/bootstrap.php) — chamamos `apply_filters()`
 * diretamente para simular o WordPress consultando `user_has_cap`, sem
 * precisar reproduzir `current_user_can()`/`user_can()` inteiros.
 */
final class NavCapabilityGateTest extends TestCase {

	protected function setUp(): void {
		// PucFunctionStubs.php acumula filtros num global só, sem remoção
		// entre testes.
		$GLOBALS['v3r_core_test_puc_filters'] = array();
	}

	public function test_capability_for_usa_o_prefixo_v3r_nav(): void {
		self::assertSame( 'v3r_nav_pessoas-cadastro', NavCapabilityGate::capabilityFor( 'pessoas-cadastro' ) );
	}

	/** Critério de aceite: um motor de permissão próprio (não current_user_can) é suficiente para a guarda que o WordPress aplica. */
	public function test_motor_de_permissao_proprio_concede_a_capability_sintetica(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'v3rlgpd_perm_x' ) );

		$access = new CountingScreenAccess( array( 'v3rlgpd_perm_x' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$allcaps = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_x' ), array( 'v3r_nav_x', 1 ), null );

		self::assertTrue( $allcaps['v3r_nav_x'] );
	}

	/** Controle negativo: a guarda respeita "não" tanto quanto "sim". */
	public function test_permissao_negada_nao_concede_a_capability_sintetica(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'v3rlgpd_perm_x' ) );

		$access = new CountingScreenAccess( array() );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$allcaps = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_x' ), array( 'v3r_nav_x', 1 ), null );

		self::assertFalse( $allcaps['v3r_nav_x'] );
	}

	/** Capability alheia ao prefixo não aciona o ScreenAccess — nenhuma consulta extra. */
	public function test_capability_alheia_nao_consulta_o_screen_access(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'v3rlgpd_perm_x' ) );

		$access = new CountingScreenAccess( array( 'v3rlgpd_perm_x' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		apply_filters( 'user_has_cap', array(), array( 'manage_options' ), array( 'manage_options', 1 ), null );

		self::assertSame( 0, $access->callsFor( 'v3rlgpd_perm_x' ) );
	}

	/** Capability com o prefixo mas sem tela registrada não é concedida nem provoca erro. */
	public function test_capability_com_prefixo_sem_tela_correspondente_nao_quebra(): void {
		$registry = new Registry();
		$access   = new CountingScreenAccess( array() );
		$gate     = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$allcaps = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_fantasma' ), array( 'v3r_nav_fantasma', 1 ), null );

		self::assertArrayNotHasKey( 'v3r_nav_fantasma', $allcaps );
	}

	/**
	 * O critério central da #35: a tela sem permissão não aparece na
	 * árvore E não é acessível pelo endereço direto, e as duas coisas vêm
	 * da MESMA declaração — mesmo Registry, mesma Screen, mesmo
	 * ScreenAccess.
	 */
	public function test_tela_sem_permissao_some_da_arvore_e_da_guarda_pela_mesma_declaracao(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'financeiro-fechamento', 'Fechamento', null, 'v3rflow_close_month' ) );

		$access = new CountingScreenAccess( array() ); // Nega tudo.

		$tree = ( new TreeBuilder( $registry, $access ) )->build();
		self::assertSame( array(), $tree, 'A tela sem permissão não pode aparecer na árvore.' );

		$gate = new NavCapabilityGate( $registry, $access );
		$gate->register();
		$allcaps = apply_filters(
			'user_has_cap',
			array(),
			array( 'v3r_nav_financeiro-fechamento' ),
			array( 'v3r_nav_financeiro-fechamento', 1 ),
			null
		);
		self::assertFalse( $allcaps['v3r_nav_financeiro-fechamento'], 'O endereço direto não pode ser acessível quando a árvore já escondeu a tela.' );
	}

	/** Controle negativo do teste acima: concedida a permissão, as duas coisas aparecem/liberam juntas. */
	public function test_tela_com_permissao_aparece_na_arvore_e_libera_a_guarda(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'financeiro-fechamento', 'Fechamento', null, 'v3rflow_close_month' ) );

		$access = new CountingScreenAccess( array( 'v3rflow_close_month' ) );

		$tree = ( new TreeBuilder( $registry, $access ) )->build();
		self::assertCount( 1, $tree );

		$gate = new NavCapabilityGate( $registry, $access );
		$gate->register();
		$allcaps = apply_filters(
			'user_has_cap',
			array(),
			array( 'v3r_nav_financeiro-fechamento' ),
			array( 'v3r_nav_financeiro-fechamento', 1 ),
			null
		);
		self::assertTrue( $allcaps['v3r_nav_financeiro-fechamento'] );
	}

	public function test_register_e_idempotente(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'perm' ) );

		$access = new CountingScreenAccess( array( 'perm' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		$gate->register();

		apply_filters( 'user_has_cap', array(), array( 'v3r_nav_x' ), array( 'v3r_nav_x', 1 ), null );

		self::assertSame( 1, $access->callsFor( 'perm' ), 'Registrado duas vezes, o filtro não pode disparar duas vezes por chamada.' );
	}

	/** Critério de aceite: quem enxerga ao menos uma tela recebe view_admin_dashboard (defeito medido no RIT360 Flow, 06/09/2026). */
	public function test_view_admin_dashboard_e_concedida_a_quem_enxerga_ao_menos_uma_tela(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new CountingScreenAccess( array( 'perm_a' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$allcaps = apply_filters(
			'user_has_cap',
			array(),
			array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY ),
			array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY, 1 ),
			null
		);

		self::assertTrue( $allcaps[ NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY ] );
	}

	/**
	 * Controle negativo: quem não enxerga tela nenhuma não recebe a
	 * capability POR NOSSA CAUSA — o filtro não escreve `false` na chave,
	 * ele simplesmente não mexe nela quando não há tela visível.
	 */
	public function test_view_admin_dashboard_nao_e_concedida_por_nossa_causa_sem_tela_visivel(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new CountingScreenAccess( array() ); // Nega tudo.
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$allcaps = apply_filters(
			'user_has_cap',
			array(),
			array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY ),
			array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY, 1 ),
			null
		);

		self::assertArrayNotHasKey( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY, $allcaps );
	}

	/**
	 * Critério de aceite: já concedida por outra origem (outro plugin, outro
	 * papel), a capability CONTINUA concedida mesmo sem tela visível — nunca
	 * a negamos, porque não é nossa para tirar.
	 */
	public function test_view_admin_dashboard_ja_concedida_por_outra_origem_e_preservada_sem_tela_visivel(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new CountingScreenAccess( array() ); // Nega tudo — nenhuma tela visível.
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$allcaps = apply_filters(
			'user_has_cap',
			array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY => true ),
			array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY ),
			array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY, 1 ),
			null
		);

		self::assertTrue( $allcaps[ NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY ] );
	}

	/** A concessão de view_admin_dashboard reaproveita o cache de hasAnyVisibleScreen() — sem varredura nova por consulta. */
	public function test_view_admin_dashboard_nao_dispara_varredura_nova(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$registry->add( new Screen( 'b', 'B', null, 'perm_b' ) );

		$access = new CountingScreenAccess( array( 'perm_a' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		apply_filters(
			'user_has_cap',
			array(),
			array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY ),
			array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY, 1 ),
			null
		);
		apply_filters(
			'user_has_cap',
			array(),
			array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY ),
			array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY, 1 ),
			null
		);

		self::assertSame( 1, $access->callsFor( 'perm_a' ), 'A segunda consulta deveria reaproveitar o cache do hasAnyVisibleScreen(), sem varrer de novo.' );
		self::assertSame( 0, $access->callsFor( 'perm_b' ), 'A busca já para no primeiro true; perm_b nunca deveria ser consultada.' );
	}

	/**
	 * @param array<string, bool> $allcaps
	 * @return array<string, bool>
	 */
	private function askRootCapability( array $allcaps = array() ): array {
		return apply_filters(
			'user_has_cap',
			$allcaps,
			array( NavCapabilityGate::ROOT_CAPABILITY ),
			array( NavCapabilityGate::ROOT_CAPABILITY, 1 ),
			null
		);
	}

	/** Sem nenhuma tela visível, a entrada raiz do menu não pode ser concedida (§5/§6: visibilidade derivada dos filhos). */
	public function test_root_capability_sem_nenhuma_tela_visivel_nao_e_concedida(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$registry->add( new Screen( 'b', 'B', null, 'perm_b' ) );

		$access = new CountingScreenAccess( array() ); // Nega tudo.
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$allcaps = $this->askRootCapability();

		self::assertFalse( $allcaps[ NavCapabilityGate::ROOT_CAPABILITY ] );
	}

	/** Controle negativo do teste acima: com uma tela visível, a entrada raiz é concedida. */
	public function test_root_capability_com_uma_tela_visivel_e_concedida(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new CountingScreenAccess( array( 'perm_a' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$allcaps = $this->askRootCapability();

		self::assertTrue( $allcaps[ NavCapabilityGate::ROOT_CAPABILITY ] );
	}

	/** Mesmo cenário com mais de uma tela declarada, só uma visível — a raiz ainda é concedida. */
	public function test_root_capability_com_ao_menos_uma_de_varias_telas_visivel_e_concedida(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$registry->add( new Screen( 'b', 'B', null, 'perm_b' ) );
		$registry->add( new Screen( 'c', 'C', null, 'perm_c' ) );

		$access = new CountingScreenAccess( array( 'perm_b' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$allcaps = $this->askRootCapability();

		self::assertTrue( $allcaps[ NavCapabilityGate::ROOT_CAPABILITY ] );
	}

	/**
	 * Critério de desempenho: perguntar pela capability raiz mais de uma
	 * vez na mesma requisição não pode disparar uma nova varredura
	 * completa nem consultar `ScreenAccess` de novo para a mesma
	 * permissão — a resposta agregada fica cacheada depois da primeira
	 * chamada (`hasAnyVisibleScreen()`), e a busca já pára no primeiro
	 * `true` encontrado, então uma tela declarada DEPOIS da visível nunca
	 * é consultada.
	 */
	public function test_root_capability_nao_dispara_varredura_completa_a_cada_chamada(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) ); // Negada — consultada e descartada.
		$registry->add( new Screen( 'b', 'B', null, 'perm_b' ) ); // Concedida — a busca pára aqui.
		$registry->add( new Screen( 'c', 'C', null, 'perm_c' ) ); // Nunca deveria ser consultada.

		$access = new CountingScreenAccess( array( 'perm_b' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$this->askRootCapability();
		$this->askRootCapability();
		$this->askRootCapability();

		self::assertSame( 1, $access->callsFor( 'perm_a' ) );
		self::assertSame( 1, $access->callsFor( 'perm_b' ) );
		self::assertSame( 0, $access->callsFor( 'perm_c' ), 'A busca deveria ter parado no primeiro true, sem chegar até aqui.' );
	}
}
