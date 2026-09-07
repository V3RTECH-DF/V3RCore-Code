<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\NavCapabilityGate;
use V3R\Core\Admin\Nav\Registry;
use V3R\Core\Admin\Nav\Screen;
use V3R\Core\Admin\Nav\TreeBuilder;
use V3R\Core\Tests\Support\CountingScreenAccess;
use V3R\Core\Tests\Support\ReentrantScreenAccess;
use V3R\Core\Tests\Support\UserAwareScreenAccess;

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
		// Idem para o usuário corrente do stub get_current_user_id(): sem
		// reset, um teste que o mude vazaria para o próximo.
		$GLOBALS['v3r_core_test_current_user_id'] = 1;
		// NavCapabilityGate agora guarda registros e caches em estado
		// ESTÁTICO de processo, de propósito (ver docblock da classe, "Um
		// filtro por PROCESSO") — sem este reset, o primeiro teste a
		// chamar register() deixaria filtro e cache quentes para todos os
		// testes seguintes deste processo PHPUnit.
		NavCapabilityGate::resetForTests();
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
	 * A capability sintética derivada (`rootCapabilityFor()`) do slug de
	 * menu 'plugin-a' — o slug padrão usado por quase todos os testes desta
	 * classe, que simula UM plugin. Testes que simulam DOIS plugins usam
	 * 'plugin-a' e 'plugin-b' explicitamente.
	 */
	private function rootCap( string $menuSlug = 'plugin-a' ): string {
		return NavCapabilityGate::rootCapabilityFor( $menuSlug );
	}

	/**
	 * @param array<string, bool> $allcaps
	 * @return array<string, bool>
	 */
	private function askRootCapability( array $allcaps = array(), string $menuSlug = 'plugin-a', int $userId = 1 ): array {
		$cap = $this->rootCap( $menuSlug );

		return apply_filters(
			'user_has_cap',
			$allcaps,
			array( $cap ),
			array( $cap, $userId ),
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
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$allcaps = $this->askRootCapability();

		self::assertFalse( $allcaps[ $this->rootCap() ] );
	}

	/** Controle negativo do teste acima: com uma tela visível, a entrada raiz é concedida. */
	public function test_root_capability_com_uma_tela_visivel_e_concedida(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new CountingScreenAccess( array( 'perm_a' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$allcaps = $this->askRootCapability();

		self::assertTrue( $allcaps[ $this->rootCap() ] );
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
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$allcaps = $this->askRootCapability();

		self::assertTrue( $allcaps[ $this->rootCap() ] );
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
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$this->askRootCapability();
		$this->askRootCapability();
		$this->askRootCapability();

		self::assertSame( 1, $access->callsFor( 'perm_a' ) );
		self::assertSame( 1, $access->callsFor( 'perm_b' ) );
		self::assertSame( 0, $access->callsFor( 'perm_c' ), 'A busca deveria ter parado no primeiro true, sem chegar até aqui.' );
	}

	/**
	 * Critério de aceite mais fácil de errar sem notar: quem enxerga
	 * APENAS uma tela oculta continua contando para "enxerga ao menos uma
	 * tela" — a árvore não lista a tela oculta, mas a guarda (e a entrada
	 * raiz do menu) não distinguem tela oculta de tela normal.
	 */
	public function test_root_capability_e_concedida_a_quem_so_enxerga_uma_tela_oculta(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'certificado', 'Certificado', null, 'perm_certificado', null, true ) );

		$access = new CountingScreenAccess( array( 'perm_certificado' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$allcaps = $this->askRootCapability();

		self::assertTrue( $allcaps[ $this->rootCap() ] );
	}

	/** A guarda de acesso direto trata tela oculta exatamente como tela normal: concede a quem tem a permissão. */
	public function test_endereco_direto_de_tela_oculta_e_liberado_para_quem_tem_a_permissao(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'certificado', 'Certificado', null, 'perm_certificado', null, true ) );

		$access = new CountingScreenAccess( array( 'perm_certificado' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$allcaps = apply_filters(
			'user_has_cap',
			array(),
			array( 'v3r_nav_certificado' ),
			array( 'v3r_nav_certificado', 1 ),
			null
		);

		self::assertTrue( $allcaps['v3r_nav_certificado'] );
	}

	/** Controle negativo: e barra quem não tem, mesma tela oculta. */
	public function test_endereco_direto_de_tela_oculta_e_barrado_para_quem_nao_tem_a_permissao(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'certificado', 'Certificado', null, 'perm_certificado', null, true ) );

		$access = new CountingScreenAccess( array() ); // Nega tudo.
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$allcaps = apply_filters(
			'user_has_cap',
			array(),
			array( 'v3r_nav_certificado' ),
			array( 'v3r_nav_certificado', 1 ),
			null
		);

		self::assertFalse( $allcaps['v3r_nav_certificado'] );
	}

	/**
	 * Defeito #1: consultada ANTES de o Registry terminar de acumular
	 * telas — como aconteceria se outro plugin/o WooCommerce perguntasse
	 * cedo no ciclo do WordPress —, a resposta "não" não pode ficar presa
	 * pelo resto da requisição depois que a tela é declarada.
	 */
	public function test_root_capability_reavalia_apos_declarar_tela_depois_da_primeira_consulta(): void {
		$registry = new Registry();
		// Registry ainda vazio: a primeira consulta acontece "cedo demais".

		$access = new CountingScreenAccess( array( 'perm_a' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		self::assertFalse( $this->askRootCapability()[ $this->rootCap() ], 'Sem tela nenhuma declarada, a raiz não pode ser concedida.' );

		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		self::assertTrue( $this->askRootCapability()[ $this->rootCap() ], 'Depois de declarar a tela, a segunda consulta precisa reavaliar — não pode continuar presa no "não" da primeira.' );
	}

	/** Mesmo cenário para a guarda de acesso direto (capability sintética por tela), não só a raiz. */
	public function test_endereco_direto_reavalia_apos_declarar_tela_depois_da_primeira_consulta(): void {
		$registry = new Registry();

		$access = new CountingScreenAccess( array( 'perm_a' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		// Uma primeira consulta qualquer, antes de a tela existir, para
		// forçar o cache agregado a computar "false" cedo.
		self::assertFalse( $this->askRootCapability()[ $this->rootCap() ] );

		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$allcaps = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_a' ), array( 'v3r_nav_a', 1 ), null );
		self::assertTrue( $allcaps['v3r_nav_a'] );
	}

	/**
	 * A promessa do §3 (uma consulta por permissão distinta enquanto o
	 * Registry não muda) não pode ser sacrificada pela correção do
	 * defeito #1: sem nenhuma mudança no Registry entre as chamadas, a
	 * varredura não pode se repetir.
	 */
	public function test_cache_agregado_nao_reavalia_sem_mudanca_no_registry(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$registry->add( new Screen( 'b', 'B', null, 'perm_b' ) );

		$access = new CountingScreenAccess( array( 'perm_b' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$this->askRootCapability();
		$this->askRootCapability();
		$this->askRootCapability();

		self::assertSame( 1, $access->callsFor( 'perm_a' ), 'Sem mudança no Registry, a varredura não pode se repetir.' );
		self::assertSame( 1, $access->callsFor( 'perm_b' ) );
	}

	/**
	 * Defeito #2: `grant()` é chamado para QUALQUER usuário
	 * (`user_can( $outro, ... )`), não só o corrente. Perguntada sobre
	 * outra pessoa, a guarda não pode responder — nem conceder, nem negar.
	 */
	public function test_pergunta_sobre_outro_usuario_nao_altera_allcaps_para_capability_sintetica(): void {
		$GLOBALS['v3r_core_test_current_user_id'] = 1;

		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'perm_x' ) );

		$access = new CountingScreenAccess( array( 'perm_x' ) ); // Concederia, se fosse consultado.
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		// Pergunta sobre o usuário 99, não o corrente (1).
		$allcaps = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_x' ), array( 'v3r_nav_x', 99 ), null );

		self::assertArrayNotHasKey( 'v3r_nav_x', $allcaps, 'Pergunta sobre outra pessoa não pode conceder nem negar — allcaps não pode ser tocado.' );
		self::assertSame( 0, $access->callsFor( 'perm_x' ), 'ScreenAccess só sabe responder sobre o usuário corrente — não deveria nem ser consultado.' );
	}

	/** Mesmo controle, para view_admin_dashboard: também se cala sobre terceiros. */
	public function test_pergunta_sobre_outro_usuario_nao_altera_view_admin_dashboard(): void {
		$GLOBALS['v3r_core_test_current_user_id'] = 1;

		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new CountingScreenAccess( array( 'perm_a' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$allcaps = apply_filters(
			'user_has_cap',
			array(),
			array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY ),
			array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY, 99 ),
			null
		);

		self::assertArrayNotHasKey( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY, $allcaps );
	}

	/** Controle negativo: pergunta sobre o próprio usuário corrente continua funcionando como sempre. */
	public function test_pergunta_sobre_o_usuario_corrente_continua_concedendo_normalmente(): void {
		$GLOBALS['v3r_core_test_current_user_id'] = 42;

		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'perm_x' ) );

		$access = new CountingScreenAccess( array( 'perm_x' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$allcaps = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_x' ), array( 'v3r_nav_x', 42 ), null );

		self::assertTrue( $allcaps['v3r_nav_x'] );
		self::assertSame( 1, $access->callsFor( 'perm_x' ) );
	}

	/**
	 * Critério de aceite (defeito medido no RIT360 Flow, 07/09/2026):
	 * calculado quando o usuário corrente é 0 (identidade ainda não
	 * resolvida — algo perguntou cedo demais no ciclo do WordPress), o
	 * agregado consultado depois, já com um usuário logado, precisa
	 * responder SOBRE O USUÁRIO LOGADO, e não sobre o zero preso em cache.
	 */
	public function test_root_capability_calculada_para_usuario_zero_nao_vaza_para_usuario_logado_depois(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		// O respondente concede a quem TEM perm_a — usuário 0 (não
		// resolvido) não tem nada, usuário 7 (logado depois) tem.
		$access = new UserAwareScreenAccess(
			array(
				0 => array(),
				7 => array( 'perm_a' ),
			)
		);
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		// Pergunta cedo demais: identidade ainda não resolvida (usuário 0).
		$GLOBALS['v3r_core_test_current_user_id'] = 0;
		$allcapsZero                              = apply_filters(
			'user_has_cap',
			array(),
			array( $this->rootCap() ),
			array( $this->rootCap(), 0 ),
			null
		);
		self::assertFalse( $allcapsZero[ $this->rootCap() ], 'Usuário 0 não enxerga nada — a resposta calculada para ele é "não".' );

		// Mesma requisição, agora com a identidade resolvida (usuário 7 logado).
		$GLOBALS['v3r_core_test_current_user_id'] = 7;
		$allcapsSete                              = apply_filters(
			'user_has_cap',
			array(),
			array( $this->rootCap() ),
			array( $this->rootCap(), 7 ),
			null
		);
		self::assertTrue( $allcapsSete[ $this->rootCap() ], 'O "não" calculado para o usuário 0 não pode vazar para o usuário 7, logado depois na mesma requisição.' );
	}

	/**
	 * Critério de aceite: duas pessoas diferentes, com permissões
	 * diferentes, na mesma requisição, recebem respostas diferentes — o
	 * cache agregado não pode misturar as duas.
	 */
	public function test_duas_pessoas_diferentes_recebem_respostas_diferentes_na_mesma_requisicao(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new UserAwareScreenAccess(
			array(
				1 => array( 'perm_a' ), // Vê a tela.
				2 => array(),           // Não vê nada.
			)
		);
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$GLOBALS['v3r_core_test_current_user_id'] = 1;
		$allcaps1                                 = apply_filters(
			'user_has_cap',
			array(),
			array( $this->rootCap() ),
			array( $this->rootCap(), 1 ),
			null
		);

		$GLOBALS['v3r_core_test_current_user_id'] = 2;
		$allcaps2                                 = apply_filters(
			'user_has_cap',
			array(),
			array( $this->rootCap() ),
			array( $this->rootCap(), 2 ),
			null
		);

		self::assertTrue( $allcaps1[ $this->rootCap() ] );
		self::assertFalse( $allcaps2[ $this->rootCap() ] );
	}

	/**
	 * A promessa do §3 continua valendo POR PESSOA: a mesma pessoa,
	 * consultada várias vezes sem mudança no Registry, só dispara uma
	 * varredura — mesmo havendo outra pessoa intercalada nas consultas.
	 */
	public function test_cache_por_pessoa_nao_reavalia_para_a_mesma_pessoa_mesmo_com_outra_pessoa_intercalada(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new UserAwareScreenAccess(
			array(
				1 => array( 'perm_a' ),
				2 => array(),
			)
		);
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$ask = function ( int $userId ): array {
			$GLOBALS['v3r_core_test_current_user_id'] = $userId;
			return apply_filters(
				'user_has_cap',
				array(),
				array( $this->rootCap() ),
				array( $this->rootCap(), $userId ),
				null
			);
		};

		$ask( 1 );
		$ask( 2 );
		$ask( 1 );
		$ask( 1 );

		self::assertSame( 1, $access->callsFor( 1, 'perm_a' ), 'O usuário 1 deveria ter sido consultado apenas na primeira vez que perguntou — as chamadas seguintes reaproveitam o cache dele.' );
		self::assertSame( 1, $access->callsFor( 2, 'perm_a' ) );
	}

	/**
	 * A `view_admin_dashboard` segue a mesma regra: concedida a quem
	 * enxerga tela, e a resposta de uma pessoa não pode servir a outra.
	 */
	public function test_view_admin_dashboard_nao_vaza_entre_pessoas_diferentes(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new UserAwareScreenAccess(
			array(
				1 => array( 'perm_a' ),
				2 => array(),
			)
		);
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();

		$ask = function ( int $userId ): array {
			$GLOBALS['v3r_core_test_current_user_id'] = $userId;
			return apply_filters(
				'user_has_cap',
				array(),
				array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY ),
				array( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY, $userId ),
				null
			);
		};

		$allcaps1 = $ask( 1 );
		$allcaps2 = $ask( 2 );

		self::assertTrue( $allcaps1[ NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY ] );
		self::assertArrayNotHasKey( NavCapabilityGate::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY, $allcaps2 );
	}

	/**
	 * Defeito de 07/09/2026: uma consulta REENTRANTE (disparada de dentro
	 * de `ScreenAccess::canView()`, como o WordPress faz de verdade quando
	 * `current_user_can()` dispara `user_has_cap` de novo) não pode receber
	 * o `false` provisório publicado antes de a varredura terminar. Com a
	 * tela `a` visível, a versão antiga publicava `$rootVisible = false`
	 * ANTES do laço — a consulta reentrante via exatamente esse `false`
	 * como se fosse a resposta final. Agora ela não recebe `false`
	 * nenhum: a chave nem é tocada (silêncio) enquanto o resultado ainda
	 * não é conhecido.
	 */
	public function test_consulta_reentrante_nao_recebe_false_provisorio_com_tela_visivel(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new ReentrantScreenAccess( array( 'perm_a' ), 'perm_a' );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$allcaps = $this->askRootCapability();

		self::assertTrue( $allcaps[ $this->rootCap() ], 'A resposta final, depois da varredura completa, precisa ser true — a tela é visível.' );
		self::assertSame(
			array( false ),
			$access->reentrantKeyWasPresent(),
			'A consulta reentrante não pode ver a chave da capability de raiz publicada — nem como false.'
		);
		self::assertSame( array( null ), $access->reentrantValues() );
	}

	/**
	 * Controle negativo do teste acima: SEM tela visível, a resposta final
	 * (depois de a varredura terminar) É `false` de verdade — isto não é
	 * o defeito, é a resposta correta e definitiva depois do cálculo
	 * completo, publicada só uma vez, no fim.
	 */
	public function test_root_capability_sem_tela_visivel_e_false_apos_o_calculo_completo_mesmo_com_gatilho_reentrante(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new ReentrantScreenAccess( array(), 'perm_a' ); // Nega tudo.
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$allcaps = $this->askRootCapability();

		self::assertFalse( $allcaps[ $this->rootCap() ] );
		self::assertSame(
			array( false ),
			$access->reentrantKeyWasPresent(),
			'Durante o cálculo (ainda incompleto), a consulta reentrante continua sem ver a chave — mesmo que a resposta final também venha a ser false.'
		);
	}

	/**
	 * Critério de aceite: a consulta reentrante não pode disparar um
	 * segundo laço completo nem recursar sem fim — `perm_a` só pode ter
	 * sido consultada UMA vez, mesmo intermediada por uma reentrância no
	 * meio.
	 */
	public function test_consulta_reentrante_nao_dispara_segundo_laco_nem_recursao(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new ReentrantScreenAccess( array( 'perm_a' ), 'perm_a' );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$this->askRootCapability();

		self::assertSame( 1, $access->callsFor( 'perm_a' ), 'Uma consulta reentrante não pode provocar uma segunda varredura completa (nem recursão) da mesma permissão.' );
	}

	/**
	 * A reentrância no meio do laço não atrapalha o restante da varredura:
	 * a tela seguinte (não-gatilho, visível) ainda é encontrada e
	 * publicada normalmente depois que a consulta reentrante retorna.
	 */
	public function test_calculo_continua_normalmente_apos_a_consulta_reentrante_no_meio_do_laco(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) ); // Gatilho, negada — o laço continua.
		$registry->add( new Screen( 'b', 'B', null, 'perm_b' ) ); // Visível — encontrada depois da reentrância.

		$access = new ReentrantScreenAccess( array( 'perm_b' ), 'perm_a' );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$allcaps = $this->askRootCapability();

		self::assertTrue( $allcaps[ $this->rootCap() ] );
		self::assertSame( 1, $access->callsFor( 'perm_a' ) );
		self::assertSame( 1, $access->callsFor( 'perm_b' ) );
	}

	/**
	 * O cache só publica o resultado do cálculo COMPLETO: depois que a
	 * consulta reentrante (no meio da primeira varredura) e a resposta
	 * final já aconteceram, uma segunda consulta pela raiz reaproveita o
	 * cache — sem repetir a varredura.
	 */
	public function test_cache_agregado_so_publica_apos_calculo_completo_mesmo_com_reentrancia(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new ReentrantScreenAccess( array( 'perm_a' ), 'perm_a' );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$this->askRootCapability();
		$allcaps = $this->askRootCapability();

		self::assertTrue( $allcaps[ $this->rootCap() ] );
		self::assertSame( 1, $access->callsFor( 'perm_a' ), 'A segunda consulta (fora da reentrância) precisa reaproveitar o cache do cálculo completo.' );
	}

	/**
	 * Defeito medido em produção no RIT360 Flow (07/09/2026): duas
	 * `Navigation` construídas no mesmo processo — cada uma com o próprio
	 * `NavCapabilityGate` — só podem pendurar UM filtro `user_has_cap` no
	 * total, não um por instância.
	 */
	public function test_duas_instancias_registradas_penduram_um_unico_filtro(): void {
		$registryA = new Registry();
		$registryA->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$accessA = new CountingScreenAccess( array( 'perm_a' ) );

		$registryB = new Registry();
		$registryB->add( new Screen( 'b', 'B', null, 'perm_b' ) );
		$accessB = new CountingScreenAccess( array( 'perm_b' ) );

		( new NavCapabilityGate( $registryA, $accessA ) )->register();
		( new NavCapabilityGate( $registryB, $accessB ) )->register();

		self::assertCount( 1, $GLOBALS['v3r_core_test_puc_filters']['user_has_cap'], 'Duas instâncias registradas não podem pendurar dois filtros.' );
	}

	/**
	 * Critério central da correção: uma tela declarada SÓ na segunda
	 * `Navigation`/registro continua guardada de verdade — negada para
	 * quem não pode, permitida para quem pode. Ignorar o segundo registro
	 * trocaria a corrida por um buraco (a tela abriria sem guarda nenhuma).
	 */
	public function test_tela_declarada_so_no_segundo_registro_continua_negada_para_quem_nao_pode(): void {
		$registryA = new Registry();
		$registryA->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$accessA = new CountingScreenAccess( array( 'perm_a' ) );

		$registryB = new Registry();
		$registryB->add( new Screen( 'b', 'B', null, 'perm_b' ) );
		$accessB = new CountingScreenAccess( array() ); // Nega tudo.

		( new NavCapabilityGate( $registryA, $accessA ) )->register();
		( new NavCapabilityGate( $registryB, $accessB ) )->register();

		$allcaps = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_b' ), array( 'v3r_nav_b', 1 ), null );

		self::assertFalse( $allcaps['v3r_nav_b'] );
	}

	/** Controle negativo do teste acima: permitida para quem pode, mesma tela do segundo registro. */
	public function test_tela_declarada_so_no_segundo_registro_e_permitida_para_quem_pode(): void {
		$registryA = new Registry();
		$registryA->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$accessA = new CountingScreenAccess( array( 'perm_a' ) );

		$registryB = new Registry();
		$registryB->add( new Screen( 'b', 'B', null, 'perm_b' ) );
		$accessB = new CountingScreenAccess( array( 'perm_b' ) );

		( new NavCapabilityGate( $registryA, $accessA ) )->register();
		( new NavCapabilityGate( $registryB, $accessB ) )->register();

		$allcaps = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_b' ), array( 'v3r_nav_b', 1 ), null );

		self::assertTrue( $allcaps['v3r_nav_b'] );
	}

	/** Cada tela é respondida pelo respondente DO SEU registro — mesmo com respondentes diferentes que concordariam nunca fica implícito nem confuso. */
	public function test_cada_registro_responde_pelas_proprias_telas_com_respondentes_diferentes(): void {
		$registryA = new Registry();
		$registryA->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$accessA = new CountingScreenAccess( array( 'perm_a' ) ); // Concede a de A.

		$registryB = new Registry();
		$registryB->add( new Screen( 'b', 'B', null, 'perm_b' ) );
		$accessB = new CountingScreenAccess( array() ); // Nega a de B.

		( new NavCapabilityGate( $registryA, $accessA ) )->register();
		( new NavCapabilityGate( $registryB, $accessB ) )->register();

		$allcapsA = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_a' ), array( 'v3r_nav_a', 1 ), null );
		$allcapsB = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_b' ), array( 'v3r_nav_b', 1 ), null );

		self::assertTrue( $allcapsA['v3r_nav_a'] );
		self::assertFalse( $allcapsB['v3r_nav_b'] );
		// accessA nunca deveria ter sido consultado pela permissão de B, nem vice-versa.
		self::assertSame( 0, $accessA->callsFor( 'perm_b' ) );
		self::assertSame( 0, $accessB->callsFor( 'perm_a' ) );
	}

	/** O agregado (capability da raiz) é verdadeiro se QUALQUER registro tiver tela visível — mesmo que o primeiro registrado não tenha nenhuma. */
	public function test_root_capability_verdadeira_se_qualquer_registro_tiver_tela_visivel(): void {
		$registryA = new Registry();
		$registryA->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$accessA = new CountingScreenAccess( array() ); // Nega tudo em A.

		$registryB = new Registry();
		$registryB->add( new Screen( 'b', 'B', null, 'perm_b' ) );
		$accessB = new CountingScreenAccess( array( 'perm_b' ) ); // Concede em B.

		( new NavCapabilityGate( $registryA, $accessA ) )->register();
		( new NavCapabilityGate( $registryB, $accessB ) )->register();
		// As DUAS declarações pertencem ao MESMO plugin (mesma entrada de
		// menu) — cenário de "duas Navigation, um só plugin", já coberto
		// pelo docblock da classe ("Um filtro por PROCESSO").
		NavCapabilityGate::registerRootMenu( $registryA, $accessA, 'plugin-a' );
		NavCapabilityGate::registerRootMenu( $registryB, $accessB, 'plugin-a' );

		self::assertTrue( $this->askRootCapability()[ $this->rootCap() ] );
	}

	/** Controle negativo: nenhum registro com tela visível, o agregado é falso. */
	public function test_root_capability_falsa_quando_nenhum_registro_tem_tela_visivel(): void {
		$registryA = new Registry();
		$registryA->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$accessA = new CountingScreenAccess( array() );

		$registryB = new Registry();
		$registryB->add( new Screen( 'b', 'B', null, 'perm_b' ) );
		$accessB = new CountingScreenAccess( array() );

		( new NavCapabilityGate( $registryA, $accessA ) )->register();
		( new NavCapabilityGate( $registryB, $accessB ) )->register();
		NavCapabilityGate::registerRootMenu( $registryA, $accessA, 'plugin-a' );
		NavCapabilityGate::registerRootMenu( $registryB, $accessB, 'plugin-a' );

		self::assertFalse( $this->askRootCapability()[ $this->rootCap() ] );
	}

	/**
	 * O defeito medido em produção, na forma exata em que apareceu: a
	 * resposta final não pode depender da ORDEM em que as `Navigation`
	 * foram construídas. Registrar B antes de A dá o mesmo resultado que
	 * registrar A antes de B.
	 */
	public function test_resposta_nao_depende_da_ordem_de_construcao(): void {
		$registryA = new Registry();
		$registryA->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$accessA = new CountingScreenAccess( array( 'perm_a' ) );

		$registryB = new Registry();
		$registryB->add( new Screen( 'b', 'B', null, 'perm_b' ) );
		$accessB = new CountingScreenAccess( array() );

		// B primeiro, depois A — o inverso da ordem dos testes acima.
		( new NavCapabilityGate( $registryB, $accessB ) )->register();
		( new NavCapabilityGate( $registryA, $accessA ) )->register();
		NavCapabilityGate::registerRootMenu( $registryB, $accessB, 'plugin-a' );
		NavCapabilityGate::registerRootMenu( $registryA, $accessA, 'plugin-a' );

		self::assertTrue( $this->askRootCapability()[ $this->rootCap() ] );

		$allcapsA = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_a' ), array( 'v3r_nav_a', 1 ), null );
		$allcapsB = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_b' ), array( 'v3r_nav_b', 1 ), null );

		self::assertTrue( $allcapsA['v3r_nav_a'] );
		self::assertFalse( $allcapsB['v3r_nav_b'] );
	}

	/** Registrar o MESMO par Registry+ScreenAccess mais de uma vez não duplica nada nem dispara consulta em dobro. */
	public function test_registrar_o_mesmo_par_duas_vezes_nao_duplica(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', null, 'perm_x' ) );
		$access = new CountingScreenAccess( array( 'perm_x' ) );

		$gate1 = new NavCapabilityGate( $registry, $access );
		$gate1->register();
		// Um SEGUNDO NavCapabilityGate embrulhando o MESMO par.
		$gate2 = new NavCapabilityGate( $registry, $access );
		$gate2->register();

		self::assertCount( 1, $GLOBALS['v3r_core_test_puc_filters']['user_has_cap'] );

		apply_filters( 'user_has_cap', array(), array( 'v3r_nav_x' ), array( 'v3r_nav_x', 1 ), null );

		self::assertSame( 1, $access->callsFor( 'perm_x' ), 'O mesmo par registrado duas vezes não pode consultar em dobro.' );
	}

	/**
	 * ResetForTests() zera de fato o estado estático: (a) o registro
	 * anterior deixa de responder pela própria tela, e (b) a flag "já
	 * pendurei o filtro" também zera — a próxima chamada de register()
	 * pendura um SEGUNDO filtro no global de teste (o global do stub, ao
	 * contrário do estado da classe, não é limpo por resetForTests(); quem
	 * o limpa é setUp() do próprio teste — aqui deixamos o filtro antigo de
	 * propósito para provar que a classe pendura um novo por cima).
	 */
	public function test_reset_for_tests_zera_registros_e_a_flag_de_filtro_pendurado(): void {
		$registryOld = new Registry();
		$registryOld->add( new Screen( 'x', 'X', null, 'perm_x' ) );
		$accessOld = new CountingScreenAccess( array( 'perm_x' ) );

		( new NavCapabilityGate( $registryOld, $accessOld ) )->register();
		self::assertCount( 1, $GLOBALS['v3r_core_test_puc_filters']['user_has_cap'] );

		NavCapabilityGate::resetForTests();

		$registryNew = new Registry();
		$registryNew->add( new Screen( 'y', 'Y', null, 'perm_y' ) );
		$accessNew = new CountingScreenAccess( array( 'perm_y' ) );

		( new NavCapabilityGate( $registryNew, $accessNew ) )->register();

		self::assertCount(
			2,
			$GLOBALS['v3r_core_test_puc_filters']['user_has_cap'],
			'Depois do reset, a flag de "já pendurei" deveria ter zerado — register() pendura um segundo filtro.'
		);

		// O callback antigo (do registro anterior ao reset) ainda está no
		// global do stub, mas o ESTADO DA CLASSE que ele lê foi zerado —
		// então ele não sabe mais responder pela tela 'x' que só existia
		// no registro descartado.
		$allcaps = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_x' ), array( 'v3r_nav_x', 1 ), null );
		self::assertArrayNotHasKey( 'v3r_nav_x', $allcaps, 'A tela do registro anterior ao reset não deveria mais ser reconhecida.' );

		// A tela nova, declarada depois do reset, responde normalmente.
		$allcapsNew = apply_filters( 'user_has_cap', array(), array( 'v3r_nav_y' ), array( 'v3r_nav_y', 1 ), null );
		self::assertTrue( $allcapsNew['v3r_nav_y'] );
	}

	/**
	 * O defeito medido em produção (07/09/2026, RIT360 Flow + V3RLGPD): a
	 * capability sintética da raiz é POR PLUGIN, derivada do slug do MENU
	 * (`rootCapabilityFor()`), não uma string fixa da biblioteca. Duas
	 * guardas independentes — registros e respondentes diferentes,
	 * simulando dois plugins —, cada uma com a própria entrada de menu: a
	 * resposta de uma não pode ser sobrescrita pela outra, em NENHUMA
	 * ordem de registro. Sem a correção (voltando à antiga capability
	 * única, compartilhada por todo o processo), este teste falharia: a
	 * segunda guarda a responder sobrescreveria `$allcaps` da primeira,
	 * porque as duas reconheceriam a MESMA chave.
	 */
	public function test_capability_de_raiz_de_um_plugin_nao_e_sobrescrita_pela_de_outro_em_qualquer_ordem(): void {
		$registryA = new Registry();
		$registryA->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$accessA = new CountingScreenAccess( array( 'perm_a' ) ); // Plugin A enxerga a própria tela.

		$registryB = new Registry();
		$registryB->add( new Screen( 'b', 'B', null, 'perm_b' ) );
		$accessB = new CountingScreenAccess( array() ); // Plugin B não enxerga nada.

		// Ordem 1: A primeiro, depois B.
		( new NavCapabilityGate( $registryA, $accessA ) )->register();
		( new NavCapabilityGate( $registryB, $accessB ) )->register();
		NavCapabilityGate::registerRootMenu( $registryA, $accessA, 'plugin-a' );
		NavCapabilityGate::registerRootMenu( $registryB, $accessB, 'plugin-b' );

		self::assertTrue(
			$this->askRootCapability( array(), 'plugin-a' )[ $this->rootCap( 'plugin-a' ) ],
			'A raiz do plugin A precisa ser concedida — ele enxerga a própria tela.'
		);
		self::assertFalse(
			$this->askRootCapability( array(), 'plugin-b' )[ $this->rootCap( 'plugin-b' ) ],
			'A raiz do plugin B não pode ser concedida por causa da tela do plugin A — cada plugin responde só pela própria.'
		);

		NavCapabilityGate::resetForTests();

		// Ordem 2 (invertida): B primeiro, depois A — o defeito medido em
		// produção era sensível à ordem em que os plugins carregavam.
		$registryA2 = new Registry();
		$registryA2->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$accessA2 = new CountingScreenAccess( array( 'perm_a' ) );

		$registryB2 = new Registry();
		$registryB2->add( new Screen( 'b', 'B', null, 'perm_b' ) );
		$accessB2 = new CountingScreenAccess( array() );

		( new NavCapabilityGate( $registryB2, $accessB2 ) )->register();
		( new NavCapabilityGate( $registryA2, $accessA2 ) )->register();
		NavCapabilityGate::registerRootMenu( $registryB2, $accessB2, 'plugin-b' );
		NavCapabilityGate::registerRootMenu( $registryA2, $accessA2, 'plugin-a' );

		self::assertTrue(
			$this->askRootCapability( array(), 'plugin-a' )[ $this->rootCap( 'plugin-a' ) ],
			'Mesmo com B registrado primeiro, a raiz de A continua concedida.'
		);
		self::assertFalse(
			$this->askRootCapability( array(), 'plugin-b' )[ $this->rootCap( 'plugin-b' ) ],
			'Mesmo com B registrado primeiro, a raiz de B continua negada — não herda a visibilidade de A.'
		);
	}

	/**
	 * O segundo furo da mesma família: perguntada sobre a capability de
	 * raiz de OUTRO plugin (mesmo prefixo, slug que este processo nunca
	 * registrou via `registerRootMenu()`), a guarda não pode responder —
	 * nem conceder, nem negar. É a mesma disciplina de silêncio que já
	 * vale para capability de tela desconhecida
	 * (`test_capability_com_prefixo_sem_tela_correspondente_nao_quebra`).
	 */
	public function test_capability_de_raiz_de_outro_plugin_fica_em_silencio(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		// Concederia, se fosse consultado — prova que o silêncio não vem de
		// a tela estar invisível, e sim de o slug ser de outro plugin.
		$access = new CountingScreenAccess( array( 'perm_a' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		$capabilityDeOutroPlugin = NavCapabilityGate::rootCapabilityFor( 'plugin-b' );

		$allcaps = apply_filters(
			'user_has_cap',
			array(),
			array( $capabilityDeOutroPlugin ),
			array( $capabilityDeOutroPlugin, 1 ),
			null
		);

		self::assertArrayNotHasKey(
			$capabilityDeOutroPlugin,
			$allcaps,
			'Capability de raiz de um slug nunca registrado por este processo não pode ser concedida nem negada.'
		);
	}

	/** Controle negativo do teste acima: perguntada sobre o PRÓPRIO slug, a guarda responde normalmente. */
	public function test_capability_de_raiz_do_proprio_plugin_continua_respondendo(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );

		$access = new CountingScreenAccess( array( 'perm_a' ) );
		$gate   = new NavCapabilityGate( $registry, $access );
		$gate->register();
		NavCapabilityGate::registerRootMenu( $registry, $access, 'plugin-a' );

		self::assertTrue( $this->askRootCapability()[ $this->rootCap() ] );
	}

	/**
	 * Critério de aceite: dois menus DIFERENTES do MESMO processo (caso
	 * incomum, mas possível — nada na API impede um plugin de declarar duas
	 * entradas de menu) não se misturam: a raiz de um não conta as telas do
	 * outro.
	 */
	public function test_dois_menus_do_mesmo_processo_nao_agregam_telas_um_do_outro(): void {
		$registryX = new Registry();
		$registryX->add( new Screen( 'x', 'X', null, 'perm_x' ) );
		$accessX = new CountingScreenAccess( array() ); // Nega tudo em X.

		$registryY = new Registry();
		$registryY->add( new Screen( 'y', 'Y', null, 'perm_y' ) );
		$accessY = new CountingScreenAccess( array( 'perm_y' ) ); // Concede em Y.

		( new NavCapabilityGate( $registryX, $accessX ) )->register();
		( new NavCapabilityGate( $registryY, $accessY ) )->register();
		NavCapabilityGate::registerRootMenu( $registryX, $accessX, 'menu-x' );
		NavCapabilityGate::registerRootMenu( $registryY, $accessY, 'menu-y' );

		self::assertFalse(
			$this->askRootCapability( array(), 'menu-x' )[ $this->rootCap( 'menu-x' ) ],
			'A raiz do menu X não pode ser concedida por causa de uma tela visível do menu Y.'
		);
		self::assertTrue( $this->askRootCapability( array(), 'menu-y' )[ $this->rootCap( 'menu-y' ) ] );
	}
}
