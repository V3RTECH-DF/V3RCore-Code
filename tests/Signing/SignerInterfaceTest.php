<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Signing;

use PHPUnit\Framework\TestCase;
use V3R\Core\Signing\CertificateMaterial;
use V3R\Core\Signing\SigningException;
use V3R\Core\Tests\Signing\Support\FakeSigner;

/**
 * Chamadas diretas de filesystem (file_put_contents(), unlink()) neste
 * arquivo são de teste, sem WordPress carregado — mesma justificativa de
 * tests/Support/PluginVersionTest.php.
 */
final class SignerInterfaceTest extends TestCase {

	/** @var string */
	private $arquivo;

	protected function setUp(): void {
		$this->arquivo = tempnam( sys_get_temp_dir(), 'v3r-core-signer-test-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->arquivo, 'documento não assinado' );
	}

	protected function tearDown(): void {
		foreach ( array( $this->arquivo, $this->arquivo . '.signed' ) as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file );
			}
		}

		parent::tearDown();
	}

	public function testSignDevolveOCaminhoDoArquivoAssinado(): void {
		$signer   = new FakeSigner();
		$material = new CertificateMaterial( '/caminho/do/certificado.pfx', 'senha-correta' );

		$assinado = $signer->sign( $this->arquivo, $material );

		$this->assertFileExists( $assinado );
	}

	public function testFalhaDeclaradaCarregaOCodigoDeErro(): void {
		$signer   = new FakeSigner( 'senha-errada' );
		$material = new CertificateMaterial( '/caminho/do/certificado.pfx', 'senha-errada' );

		try {
			$signer->sign( $this->arquivo, $material );
			$this->fail( 'Esperava SigningException.' );
		} catch ( SigningException $e ) {
			$this->assertSame( SigningException::SENHA_INVALIDA, $e->getErrorCode() );
		}
	}
}
