<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\Family;
use V3R\Core\Admin\Nav\MenuEntry;
use V3R\Core\Admin\Nav\MenuOrder;

/**
 * Prova a convivência entre **cópias prefixadas** da biblioteca — o que os
 * testes normais não alcançam, porque rodam com uma cópia só.
 *
 * É o cenário que a camada já pagou caro para aprender (V3RCore-Code#25,
 * e antes disso a capability de raiz da v0.21.0): num WordPress com vários
 * plugins da casa, cada plugin embute a PRÓPRIA cópia da biblioteca, com as
 * próprias classes e o próprio estado estático. Adoção validada sozinha não
 * prova convivência.
 *
 * O teste carrega uma segunda cópia de `MenuOrder` (e das duas classes de
 * que ela depende) sob outro namespace, exatamente o que o Strauss faz ao
 * embutir a biblioteca, e prova que as duas cópias:
 *
 * 1. **enxergam o mesmo anúncio** — a global de nome fixo não é prefixada,
 *    e é isso que permite a cópia de um plugin conhecer a entrada do outro;
 * 2. **convergem** — a reordenação de uma devolve o mesmo resultado da
 *    outra, e aplicar as duas em sequência (é o que acontece no site: cada
 *    cópia pendura o próprio filtro) não muda nada.
 */
final class MenuOrderCoexistenceTest extends TestCase {

	/** Namespace da segunda cópia — o papel do prefixo que o Strauss aplica. */
	private const OTHER_NAMESPACE = 'V3RCoreTestOutraCopia\\Vendor\\V3R\\Core\\Admin\\Nav';

	protected function setUp(): void {
		MenuOrder::resetForTests();
		self::loadOtherCopy();
	}

	protected function tearDown(): void {
		MenuOrder::resetForTests();
	}

	/**
	 * Reescreve o namespace do fonte e o avalia — as classes resultantes são
	 * outras classes, com outro estado estático, como as de um segundo
	 * plugin. Carregado uma vez por processo (redeclarar dá erro fatal).
	 */
	private static function loadOtherCopy(): void {
		if ( class_exists( self::OTHER_NAMESPACE . '\\MenuOrder', false ) ) {
			return;
		}

		$src = dirname( __DIR__, 3 ) . '/src/Admin/Nav/';

		foreach ( array( 'Family', 'MenuEntry', 'MenuOrder' ) as $class ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lê o fonte da própria biblioteca em disco, não uma URL remota.
			$code = file_get_contents( $src . $class . '.php' );

			self::assertIsString( $code, "Fonte de {$class} ilegível — sem ele o teste não prova nada." );

			$code = str_replace(
				'namespace V3R\\Core\\Admin\\Nav;',
				'namespace ' . self::OTHER_NAMESPACE . ';',
				$code
			);

			// `eval()` não aceita a tag de abertura nem `declare(strict_types=1)`
			// fora do início de um arquivo.
			$code = preg_replace( '/^<\?php\s*declare\(strict_types=1\);/', '', $code );

			eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- carrega uma segunda cópia da própria biblioteca sob outro namespace, o que o Strauss faz no empacotamento; é o objeto deste teste.
		}
	}

	/**
	 * Anuncia uma entrada a partir da cópia indicada.
	 *
	 * @param string $copyNamespace Namespace da cópia que anuncia — a nossa, ou a outra.
	 * @param string $title         Título da entrada.
	 * @param string $slug          Slug do menu.
	 * @param string $family        Família (`Family::RIT`/`Family::V3RTECH`).
	 */
	private static function announceFrom( string $copyNamespace, string $title, string $slug, string $family ): void {
		$entryClass = $copyNamespace . '\\MenuEntry';
		$orderClass = $copyNamespace . '\\MenuOrder';

		$orderClass::announce( new $entryClass( $title, $slug, $family ) );
	}

	public function test_uma_copia_enxerga_a_entrada_anunciada_pela_outra(): void {
		self::announceFrom( self::OTHER_NAMESPACE, 'V3RLGPD', 'v3rlgpd', 'v3rtech' );
		MenuOrder::announce( new MenuEntry( 'RIT360 Flow', 'v3rflow', Family::RIT ) );

		self::assertSame(
			array( 'v3rflow', 'v3rlgpd', 'woocommerce' ),
			MenuOrder::reorder( array( 'v3rlgpd', 'woocommerce', 'v3rflow' ) ),
			'A cópia desta suíte precisa reconhecer a entrada anunciada pela outra cópia — sem isso cada plugin reordenaria só a própria e elas brigariam.'
		);
	}

	public function test_as_duas_copias_calculam_a_mesma_ordem(): void {
		self::announceFrom( self::OTHER_NAMESPACE, 'V3RLGPD', 'v3rlgpd', 'v3rtech' );
		self::announceFrom( self::OTHER_NAMESPACE, 'V3REvent', 'v3revent', 'v3rtech' );
		MenuOrder::announce( new MenuEntry( 'RIT360 Flow', 'v3rflow', Family::RIT ) );

		$order      = array( 'index.php', 'v3rlgpd', 'woocommerce', 'v3revent', 'jetpack', 'v3rflow' );
		$otherClass = self::OTHER_NAMESPACE . '\\MenuOrder';

		$daOutra = $otherClass::reorder( $order );
		$daNossa = MenuOrder::reorder( $order );

		self::assertSame( $daOutra, $daNossa );

		// No site as duas rodam em sequência, uma sobre o resultado da outra.
		self::assertSame( $daNossa, $otherClass::reorder( $daNossa ) );
		self::assertSame(
			array( 'index.php', 'v3rflow', 'v3revent', 'v3rlgpd', 'woocommerce', 'jetpack' ),
			$daNossa
		);
	}
}
