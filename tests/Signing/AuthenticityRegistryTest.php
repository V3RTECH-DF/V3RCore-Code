<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Signing;

use PHPUnit\Framework\TestCase;
use V3R\Core\Signing\AuthenticitySealingException;
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

	public function testIssueDevolveRegistroSemResumoENaoExigeArquivo(): void {
		// issue #28: emitir não pode exigir o arquivo final, porque o
		// código vai impresso DENTRO dele — no instante da emissão o
		// arquivo ainda não existe.
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );

		$record = $registry->issue( SigningMode::CERTIFICADO_DIGITAL );

		$this->assertSame( SigningMode::CERTIFICADO_DIGITAL, $record->mode() );
		$this->assertNotSame( '', $record->code() );
		$this->assertFalse( $record->isSealed() );
		$this->assertNull( $record->fileHash() );
	}

	public function testCodigoDeAutenticidadeNaoEhReproduzivelAPartirDosDadosDoDocumento(): void {
		// A verificação central da issue #27 (defeito 2): o código NÃO pode
		// ser derivável a partir de campos do documento. Provamos o
		// contrário do que aconteceria com um código derivado: dois
		// emissões seguidas recebem códigos DIFERENTES, porque o código
		// vem de random_bytes(), não de nada relacionado ao documento.
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );

		$registroA = $registry->issue( SigningMode::REGISTRO_ELETRONICO );
		$registroB = $registry->issue( SigningMode::REGISTRO_ELETRONICO );

		$this->assertNotSame( $registroA->code(), $registroB->code() );
	}

	public function testSealGravaOResumoDoArquivoFinalNoRegistroJaEmitido(): void {
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );
		$emitido  = $registry->issue( SigningMode::CERTIFICADO_DIGITAL );

		$arquivo = $this->tempFileWithContents( 'documento final, ja com o codigo impresso' );
		$selado  = $registry->seal( $emitido->code(), $arquivo );

		$this->assertTrue( $selado->isSealed() );
		$this->assertSame( hash_file( 'sha256', $arquivo ), $selado->fileHash() );
		$this->assertSame( $emitido->code(), $selado->code() );
		$this->assertSame( $emitido->mode(), $selado->mode() );

		// E o registro persistido reflete o selamento, não só o valor devolvido.
		$reconstruido = $registry->find( $emitido->code() );
		$this->assertNotNull( $reconstruido );
		$this->assertTrue( $reconstruido->isSealed() );
		$this->assertSame( $selado->fileHash(), $reconstruido->fileHash() );
	}

	public function testSealDuasVezesComOMesmoResumoEhAceitoENaoFazNada(): void {
		// issue #28, detalhe (b): permite refazer uma tentativa que falhou
		// entre emitir e selar, sem entijolar o registro para sempre.
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );
		$emitido  = $registry->issue( SigningMode::CERTIFICADO_DIGITAL );
		$arquivo  = $this->tempFileWithContents( 'documento final' );

		$primeiroSelamento = $registry->seal( $emitido->code(), $arquivo );
		$segundoSelamento  = $registry->seal( $emitido->code(), $arquivo );

		$this->assertSame( $primeiroSelamento->fileHash(), $segundoSelamento->fileHash() );
	}

	public function testSealComResumoDiferenteDeUmRegistroJaSeladoEhRecusado(): void {
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );
		$emitido  = $registry->issue( SigningMode::CERTIFICADO_DIGITAL );

		$arquivoOriginal = $this->tempFileWithContents( 'documento final' );
		$registry->seal( $emitido->code(), $arquivoOriginal );

		$arquivoDiferente = $this->tempFileWithContents( 'documento diferente' );

		$this->expectException( AuthenticitySealingException::class );

		try {
			$registry->seal( $emitido->code(), $arquivoDiferente );
		} catch ( AuthenticitySealingException $e ) {
			$this->assertSame( AuthenticitySealingException::RESUMO_DIVERGENTE, $e->getErrorCode() );

			throw $e;
		}
	}

	public function testSealDeCodigoInexistenteFalhaExplicitamenteSemGravarNada(): void {
		$store    = new InMemoryKeyValueStore();
		$registry = new AuthenticityRegistry( $store, 'produto' );
		$arquivo  = $this->tempFileWithContents( 'qualquer coisa' );

		try {
			$registry->seal( 'ABCD-2345-6789-CDEF', $arquivo );
			$this->fail( 'Esperava AuthenticitySealingException.' );
		} catch ( AuthenticitySealingException $e ) {
			$this->assertSame( AuthenticitySealingException::CODIGO_INEXISTENTE, $e->getErrorCode() );
		}

		$this->assertNull( $registry->find( 'ABCD-2345-6789-CDEF' ) );
	}

	public function testSealDeArquivoIlegivelFalhaExplicitamenteSemAlterarORegistro(): void {
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );
		$emitido  = $registry->issue( SigningMode::CERTIFICADO_DIGITAL );

		try {
			$registry->seal( $emitido->code(), '/caminho/que/nao/existe/documento.pdf' );
			$this->fail( 'Esperava AuthenticitySealingException.' );
		} catch ( AuthenticitySealingException $e ) {
			$this->assertSame( AuthenticitySealingException::ARQUIVO_ILEGIVEL, $e->getErrorCode() );
		}

		// O registro continua exatamente como issue() o deixou: não selado.
		$reconstruido = $registry->find( $emitido->code() );
		$this->assertNotNull( $reconstruido );
		$this->assertFalse( $reconstruido->isSealed() );
	}

	public function testFindDevolveORegistroEmitidoESelado(): void {
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );
		$arquivo  = $this->tempFileWithContents( 'conteudo' );

		$emitido      = $registry->issue( SigningMode::CERTIFICADO_DIGITAL );
		$selado       = $registry->seal( $emitido->code(), $arquivo );
		$reconstruido = $registry->find( $emitido->code() );

		$this->assertNotNull( $reconstruido );
		$this->assertSame( $selado->code(), $reconstruido->code() );
		$this->assertSame( $selado->mode(), $reconstruido->mode() );
		$this->assertSame( $selado->fileHash(), $reconstruido->fileHash() );
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

		$emitido = $registry->issue( SigningMode::CERTIFICADO_DIGITAL );
		$registry->seal( $emitido->code(), $arquivo );

		$verificacao = $registry->verifyFile( $emitido->code(), $arquivo );

		$this->assertTrue( $verificacao->wasFound() );
		$this->assertTrue( $verificacao->fileMatches() );
		$this->assertFalse( $verificacao->wasTampered() );
		$this->assertFalse( $verificacao->isAwaitingSeal() );
	}

	public function testVerifyFileDetectaArquivoAlterado(): void {
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );
		$arquivo  = $this->tempFileWithContents( 'conteudo original' );

		$emitido = $registry->issue( SigningMode::CERTIFICADO_DIGITAL );
		$registry->seal( $emitido->code(), $arquivo );

		// Simula alteração depois de selado.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $arquivo, 'conteudo alterado depois de selado' );

		$verificacao = $registry->verifyFile( $emitido->code(), $arquivo );

		$this->assertTrue( $verificacao->wasFound() );
		$this->assertFalse( $verificacao->fileMatches() );
		$this->assertTrue( $verificacao->wasTampered() );
		$this->assertFalse( $verificacao->isAwaitingSeal() );
	}

	public function testVerifyFileParaCodigoInexistenteNaoEhTratadoComoAdulterado(): void {
		// Controle negativo: "nunca existiu" e "existiu e foi alterado" são
		// respostas diferentes (wasTampered() só é true no segundo caso).
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );
		$arquivo  = $this->tempFileWithContents( 'qualquer coisa' );

		$verificacao = $registry->verifyFile( 'ABCD-2345-6789-CDEF', $arquivo );

		$this->assertFalse( $verificacao->wasFound() );
		$this->assertFalse( $verificacao->wasTampered() );
		$this->assertFalse( $verificacao->isAwaitingSeal() );
		$this->assertNull( $verificacao->fileMatches() );
	}

	public function testVerifyFileParaRegistroAindaNaoSeladoDevolveOTerceiroEstadoENuncaAdulterado(): void {
		// issue #28, detalhe (a): registro não selado NUNCA pode produzir
		// wasTampered() === true — mesmo apresentando um arquivo que nem
		// existe.
		$registry = new AuthenticityRegistry( new InMemoryKeyValueStore(), 'produto' );
		$emitido  = $registry->issue( SigningMode::CERTIFICADO_DIGITAL );

		$verificacao = $registry->verifyFile( $emitido->code(), '/caminho/que/nao/existe.pdf' );

		$this->assertTrue( $verificacao->wasFound() );
		$this->assertTrue( $verificacao->isAwaitingSeal() );
		$this->assertFalse( $verificacao->wasTampered() );
		$this->assertNull( $verificacao->fileMatches() );
	}

	public function testDoisPrefixosDiferentesNaoDividemONamespace(): void {
		$store   = new InMemoryKeyValueStore();
		$arquivo = $this->tempFileWithContents( 'conteudo' );

		$registryA = new AuthenticityRegistry( $store, 'produto-a' );
		$registryB = new AuthenticityRegistry( $store, 'produto-b' );

		$emitido = $registryA->issue( SigningMode::CERTIFICADO_DIGITAL );
		$registryA->seal( $emitido->code(), $arquivo );

		$this->assertNull( $registryB->find( $emitido->code() ) );
		$this->assertNotNull( $registryA->find( $emitido->code() ) );
	}
}
