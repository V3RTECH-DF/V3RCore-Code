<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\Family;
use V3R\Core\Admin\Nav\MenuEntry;
use V3R\Core\Admin\Nav\MenuOrder;

/**
 * Cobre a reordenação que mantém os dois blocos da casa contíguos
 * (V3RCore-Code#25). Os testes exercitam `reorder()` direto, sem depender
 * do filtro `menu_order` disparar — mesmo padrão de
 * `NavigationTest::renderMenu()`.
 */
final class MenuOrderTest extends TestCase {

	protected function setUp(): void {
		MenuOrder::resetForTests();
	}

	protected function tearDown(): void {
		MenuOrder::resetForTests();
	}

	private function announce( string $slug, string $title, string $family ): void {
		MenuOrder::announce( new MenuEntry( $title, $slug, $family ) );
	}

	/** O caso da issue: quatro produtos nossos dispersos entre plugins de terceiro. */
	public function test_agrupa_os_dois_blocos_e_expulsa_os_de_terceiro_do_meio(): void {
		$this->announce( 'v3rflow', 'RIT360 Flow', Family::RIT );
		$this->announce( 'v3rlgpd', 'V3RLGPD', Family::V3RTECH );
		$this->announce( 'v3rsolidario', 'RIT360 Solidário', Family::RIT );
		$this->announce( 'v3revent', 'V3REvent', Family::V3RTECH );

		$order = array(
			'index.php',
			'v3rlgpd',
			'woocommerce',
			'v3rflow',
			'elementor',
			'v3revent',
			'jetpack',
			'v3rsolidario',
			'options-general.php',
		);

		self::assertSame(
			array(
				'index.php',
				// Bloco RIT (alfabético), depois bloco V3RTECH (alfabético),
				// ancorados onde a primeira entrada nossa estava.
				'v3rflow',
				'v3rsolidario',
				'v3revent',
				'v3rlgpd',
				'woocommerce',
				'elementor',
				'jetpack',
				'options-general.php',
			),
			MenuOrder::reorder( $order )
		);
	}

	/** Rodar de novo sobre o próprio resultado não muda nada — é o que torna seguro N cópias prefixadas pendurarem o filtro. */
	public function test_reordenar_e_idempotente(): void {
		$this->announce( 'v3rflow', 'RIT360 Flow', Family::RIT );
		$this->announce( 'v3rlgpd', 'V3RLGPD', Family::V3RTECH );

		$order = array( 'index.php', 'v3rlgpd', 'woocommerce', 'v3rflow', 'options-general.php' );

		$once  = MenuOrder::reorder( $order );
		$twice = MenuOrder::reorder( $once );

		self::assertSame( $once, $twice );
	}

	/** Convergência: a ordem de anúncio não muda o resultado (cópias carregam em ordens diferentes). */
	public function test_a_ordem_do_anuncio_nao_muda_o_resultado(): void {
		$this->announce( 'v3revent', 'V3REvent', Family::V3RTECH );
		$this->announce( 'v3rflow', 'RIT360 Flow', Family::RIT );
		$primeiro = MenuOrder::reorder( array( 'v3revent', 'x', 'v3rflow' ) );

		MenuOrder::resetForTests();
		$this->announce( 'v3rflow', 'RIT360 Flow', Family::RIT );
		$this->announce( 'v3revent', 'V3REvent', Family::V3RTECH );
		$segundo = MenuOrder::reorder( array( 'v3revent', 'x', 'v3rflow' ) );

		self::assertSame( $primeiro, $segundo );
		self::assertSame( array( 'v3rflow', 'v3revent', 'x' ), $primeiro );
	}

	/** A família RIT vem antes, mesmo com o título depois no alfabeto. */
	public function test_bloco_rit_vem_antes_do_bloco_v3rtech(): void {
		$this->announce( 'zzz', 'ZZZ Produto', Family::RIT );
		$this->announce( 'aaa', 'AAA Produto', Family::V3RTECH );

		self::assertSame( array( 'zzz', 'aaa' ), MenuOrder::reorder( array( 'aaa', 'zzz' ) ) );
	}

	/** Acento não muda o lugar da entrada na ordem alfabética. */
	public function test_ordem_alfabetica_ignora_acento_e_caixa(): void {
		// 'Á' sem tratamento vale mais que qualquer letra ASCII em
		// comparação por bytes — a entrada acentuada iria para o fim.
		$this->announce( 'a', 'RIT360 Águas', Family::RIT );
		$this->announce( 'b', 'RIT360 Solidário', Family::RIT );
		$this->announce( 'c', 'RIT360 premiado', Family::RIT );

		self::assertSame( array( 'a', 'c', 'b' ), MenuOrder::reorder( array( 'b', 'c', 'a' ) ) );
	}

	/** Uma entrada só não forma bloco: mover seria mexer na posição sem motivo. */
	public function test_uma_entrada_so_nao_e_movida(): void {
		$this->announce( 'v3rflow', 'RIT360 Flow', Family::RIT );

		$order = array( 'index.php', 'woocommerce', 'v3rflow', 'options-general.php' );

		self::assertSame( $order, MenuOrder::reorder( $order ) );
	}

	/** Nenhuma entrada nossa: a coluna volta intacta. */
	public function test_sem_entrada_da_casa_a_ordem_nao_muda(): void {
		$order = array( 'index.php', 'woocommerce', 'options-general.php' );

		self::assertSame( $order, MenuOrder::reorder( $order ) );
	}

	/** Nada de terceiro é perdido nem reordenado entre si. */
	public function test_preserva_todos_os_itens_e_a_ordem_relativa_dos_de_terceiro(): void {
		$this->announce( 'v3rflow', 'RIT360 Flow', Family::RIT );
		$this->announce( 'v3rlgpd', 'V3RLGPD', Family::V3RTECH );

		$order  = array( 'a', 'v3rlgpd', 'b', 'c', 'v3rflow', 'd' );
		$result = MenuOrder::reorder( $order );

		self::assertSame( count( $order ), count( $result ) );
		self::assertSame( array(), array_diff( $order, $result ) );
		self::assertSame(
			array( 'a', 'b', 'c', 'd' ),
			array_values( array_filter( $result, static fn( $slug ) => ! in_array( $slug, array( 'v3rflow', 'v3rlgpd' ), true ) ) )
		);
	}

	/** O bloco entra onde a PRIMEIRA entrada nossa estava, não no fim nem no topo. */
	public function test_o_bloco_entra_na_posicao_da_primeira_entrada_nossa(): void {
		$this->announce( 'v3rflow', 'RIT360 Flow', Family::RIT );
		$this->announce( 'v3rlgpd', 'V3RLGPD', Family::V3RTECH );

		self::assertSame(
			array( 'a', 'b', 'v3rflow', 'v3rlgpd', 'c' ),
			MenuOrder::reorder( array( 'a', 'b', 'v3rlgpd', 'c', 'v3rflow' ) )
		);
	}

	/** Entrada anunciada por uma cópia de formato desconhecido é ignorada, nunca derruba o menu. */
	public function test_anuncio_malformado_e_ignorado(): void {
		$this->announce( 'v3rflow', 'RIT360 Flow', Family::RIT );
		$this->announce( 'v3rlgpd', 'V3RLGPD', Family::V3RTECH );

		$GLOBALS[ MenuOrder::GLOBAL_KEY ]['lixo']    = 'não é array';
		$GLOBALS[ MenuOrder::GLOBAL_KEY ]['familia'] = array(
			'family' => 'inexistente',
			'title'  => 'X',
		);

		self::assertSame(
			array( 'v3rflow', 'v3rlgpd', 'lixo', 'familia' ),
			MenuOrder::reorder( array( 'v3rlgpd', 'lixo', 'familia', 'v3rflow' ) )
		);
	}

	/** A reordenação só é ligada quando há entrada nossa — e nunca desliga a de outro plugin. */
	public function test_custom_menu_order_so_liga_com_entrada_anunciada(): void {
		self::assertFalse( MenuOrder::enableCustomOrder( false ) );

		$this->announce( 'v3rflow', 'RIT360 Flow', Family::RIT );

		self::assertTrue( MenuOrder::enableCustomOrder( false ) );
	}

	public function test_custom_menu_order_nao_desliga_o_true_de_outro_plugin(): void {
		self::assertTrue( MenuOrder::enableCustomOrder( true ) );
	}

	/** Cada família pede a própria posição, e a RIT vem antes na coluna. */
	public function test_posicao_por_familia(): void {
		self::assertSame( MenuOrder::POSITION_RIT, MenuOrder::positionFor( Family::RIT ) );
		self::assertSame( MenuOrder::POSITION_V3RTECH, MenuOrder::positionFor( Family::V3RTECH ) );
		self::assertLessThan( MenuOrder::positionFor( Family::V3RTECH ), MenuOrder::positionFor( Family::RIT ) );
	}
}
