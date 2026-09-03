<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Frontend;

use PHPUnit\Framework\TestCase;
use V3R\Core\Frontend\AssetLocator;

final class AssetLocatorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['v3r_core_test_enqueued_scripts'] = array();
	}

	public function testResolveOAtivoDistribuidoPelaPropriaBiblioteca(): void {
		$locator = new AssetLocator();

		$this->assertFileExists( $locator->path( 'js/email-suggestion.js' ) );
		$this->assertFileExists( $locator->path( 'data/email-suggestion-cases.json' ) );
	}

	/**
	 * A URL acompanha o caminho real do arquivo, porque ela depende de onde
	 * o hospedeiro instalou a biblioteca — caminho fixo daria a URL errada
	 * em qualquer plugin que não fosse aquele onde a linha foi escrita.
	 */
	public function testUrlEhDerivadaDoCaminhoRealDoArquivo(): void {
		$locator = new AssetLocator( null, '/var/www/wp-content/plugins/meuplugin/vendor-prefixed/v3rtech/v3r-core/src/Assets' );

		$this->assertSame(
			'https://exemplo.test/wp-content/plugins/meuplugin/vendor-prefixed/v3rtech/v3r-core/src/Assets/js/email-suggestion.js',
			$locator->url( 'js/email-suggestion.js' )
		);
	}

	/**
	 * Hospedeiro fora de `wp-content/plugins` (mu-plugin, tema) informa a
	 * base, e ela vence a derivação.
	 */
	public function testBaseExplicitaVenceADerivacao(): void {
		$locator = new AssetLocator( 'https://exemplo.test/wp-content/mu-plugins/meuplugin/lib/src/Assets/' );

		$this->assertSame(
			'https://exemplo.test/wp-content/mu-plugins/meuplugin/lib/src/Assets/js/email-suggestion.js',
			$locator->url( 'js/email-suggestion.js' )
		);
	}

	/**
	 * A versão é a data de modificação do ARQUIVO, não a do plugin: a
	 * versão do plugin identifica a release, não o pacote gerado, e já
	 * produziu cache servindo o arquivo anterior.
	 */
	public function testVersaoEhADataDeModificacaoDoArquivo(): void {
		$locator = new AssetLocator();
		$path    = $locator->path( 'js/email-suggestion.js' );

		$this->assertSame( (string) filemtime( $path ), $locator->version( 'js/email-suggestion.js' ) );
	}

	/**
	 * Ativo inexistente devolve versão nula em vez de inventar uma fixa —
	 * versão inventada congelaria o cache num arquivo que mudou.
	 */
	public function testAtivoInexistenteNaoInventaVersao(): void {
		$locator = new AssetLocator();

		$this->assertNull( $locator->version( 'js/nao-existe.js' ) );
	}

	public function testEnqueueRepassaUrlVersaoEDependencias(): void {
		$locator = new AssetLocator();
		$locator->enqueueScript( 'v3r-core-email-suggestion', 'js/email-suggestion.js', array( 'jquery' ) );

		/** @var array<int, array<string, mixed>> $enqueued */
		$enqueued = $GLOBALS['v3r_core_test_enqueued_scripts'];

		$this->assertCount( 1, $enqueued );
		$this->assertSame( 'v3r-core-email-suggestion', $enqueued[0]['handle'] );
		$this->assertSame( $locator->url( 'js/email-suggestion.js' ), $enqueued[0]['src'] );
		$this->assertSame( $locator->version( 'js/email-suggestion.js' ), $enqueued[0]['version'] );
		$this->assertSame( array( 'jquery' ), $enqueued[0]['dependencies'] );
		$this->assertTrue( $enqueued[0]['inFooter'] );
	}

	/**
	 * Nada é enfileirado sozinho: a classe não registra hook nenhum, e um
	 * plugin que não quer o front não paga nada por ela existir.
	 */
	public function testConstruirNaoEnfileiraNada(): void {
		new AssetLocator();

		$this->assertSame( array(), $GLOBALS['v3r_core_test_enqueued_scripts'] );
	}
}
