<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Signing;

use PHPUnit\Framework\TestCase;
use V3R\Core\Signing\SigningMode;
use V3R\Core\Signing\AuthenticityRegistry;
use V3R\Core\Tests\Licensing\Storage\InMemoryKeyValueStore;

/**
 * Chamadas diretas de filesystem (unlink(), file_put_contents()) neste
 * arquivo são de teste, sem WordPress carregado — mesma justificativa de
 * tests/Support/PluginVersionTest.php.
 */
final class AuthenticityRegistryTest extends TestCase {

	/** @var string[] Arquivos temporários criados no teste, removidos em tearDown(). */
	private $tempFiles = array();

	protected function tearDown(): void {
		foreach ( $this->tempFiles as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file );
			}
		}

		$this->tempFiles = array();

		parent::tearDown();
	}

	private function tempFileWithContents( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'v3r-core-auth-test-' );

		if ( false === $path ) {
			$this->fail( 'Não foi possível criar arquivo temporário para o teste.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, $contents );
		$this->tempFiles[] = $path;

		return $path;
	}

	public function testIssueDevolveRegistroComModoEHashDoArquivo(): void {
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );
		$arquivo  = $this->tempFileWithContents( 'conteudo do documento emitido' );

		$record = $registry->issue( $arquivo, SigningMode::CERTIFICADO_DIGITAL );

		$this->assertSame( SigningMode::CERTIFICADO_DIGITAL, $record->mode() );
		$this->assertSame( hash_file( 'sha256', $arquivo ), $record->fileHash() );
		$this->assertNotSame( '', $record->code() );
	}

	public function testCodigoDeAutenticidadeNaoEhReproduzivelAPartirDosDadosDoDocumento(): void {
		// A verificação central da issue #27 (defeito 2): o código NÃO pode
		// ser derivável a partir de campos do documento. Provamos o
		// contrário do que aconteceria com um código derivado: dois
		// documentos com EXATAMENTE o mesmo conteúdo (logo, o mesmo hash,
		// que seria a "assinatura" de um esquema derivado) recebem códigos
		// DIFERENTES, porque o código vem de random_bytes(), não do
		// conteúdo do arquivo.
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );

		$arquivoA = $this->tempFileWithContents( 'documento identico' );
		$arquivoB = $this->tempFileWithContents( 'documento identico' );

		$this->assertSame( hash_file( 'sha256', $arquivoA ), hash_file( 'sha256', $arquivoB ) );

		$registroA = $registry->issue( $arquivoA, SigningMode::REGISTRO_ELETRONICO );
		$registroB = $registry->issue( $arquivoB, SigningMode::REGISTRO_ELETRONICO );

		$this->assertNotSame( $registroA->code(), $registroB->code() );
	}

	public function testFindDevolveORegistroEmitido(): void {
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );
		$arquivo  = $this->tempFileWithContents( 'conteudo' );

		$emitido      = $registry->issue( $arquivo, SigningMode::CERTIFICADO_DIGITAL );
		$reconstruido = $registry->find( $emitido->code() );

		$this->assertNotNull( $reconstruido );
		$this->assertSame( $emitido->code(), $reconstruido->code() );
		$this->assertSame( $emitido->mode(), $reconstruido->mode() );
		$this->assertSame( $emitido->fileHash(), $reconstruido->fileHash() );
	}

	public function testFindDevolveNullParaCodigoNuncaEmitido(): void {
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );

		$this->assertNull( $registry->find( 'ABCD-2345-6789-CDEF' ) );
	}

	public function testFindDevolveNullParaTextoMalFormado(): void {
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );

		$this->assertNull( $registry->find( 'nem parece um codigo' ) );
	}

	public function testVerifyFileConfirmaArquivoNaoAlterado(): void {
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );
		$arquivo  = $this->tempFileWithContents( 'conteudo original' );

		$emitido     = $registry->issue( $arquivo, SigningMode::CERTIFICADO_DIGITAL );
		$verificacao = $registry->verifyFile( $emitido->code(), $arquivo );

		$this->assertTrue( $verificacao->wasFound() );
		$this->assertTrue( $verificacao->fileMatches() );
		$this->assertFalse( $verificacao->wasTampered() );
	}

	public function testVerifyFileDetectaArquivoAlterado(): void {
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );
		$arquivo  = $this->tempFileWithContents( 'conteudo original' );

		$emitido = $registry->issue( $arquivo, SigningMode::CERTIFICADO_DIGITAL );

		// Simula alteração depois da emissão.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $arquivo, 'conteudo alterado depois de emitido' );

		$verificacao = $registry->verifyFile( $emitido->code(), $arquivo );

		$this->assertTrue( $verificacao->wasFound() );
		$this->assertFalse( $verificacao->fileMatches() );
		$this->assertTrue( $verificacao->wasTampered() );
	}

	public function testVerifyFileParaCodigoInexistenteNaoEhTratadoComoAdulterado(): void {
		// Controle negativo: "nunca existiu" e "existiu e foi alterado" são
		// respostas diferentes (wasTampered() só é true no segundo caso).
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );
		$arquivo  = $this->tempFileWithContents( 'qualquer coisa' );

		$verificacao = $registry->verifyFile( 'ABCD-2345-6789-CDEF', $arquivo );

		$this->assertFalse( $verificacao->wasFound() );
		$this->assertFalse( $verificacao->wasTampered() );
		$this->assertNull( $verificacao->fileMatches() );
	}

	public function testDoisPrefixosDiferentesNaoDividemONamespace(): void {
		$store   = new InMemoryKeyValueStore();
		$arquivo = $this->tempFileWithContents( 'conteudo' );

		$registryA = new AuthenticityRegistry( $store, 'produto-a' );
		$registryB = new AuthenticityRegistry( $store, 'produto-b' );

		$emitido = $registryA->issue( $arquivo, SigningMode::CERTIFICADO_DIGITAL );

		$this->assertNull( $registryB->find( $emitido->code() ) );
		$this->assertNotNull( $registryA->find( $emitido->code() ) );
	}
}
