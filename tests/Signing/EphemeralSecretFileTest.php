<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Signing;

use PHPUnit\Framework\TestCase;
use V3R\Core\Signing\EphemeralSecretFile;

/**
 * Chamadas diretas de filesystem (file_put_contents(), unlink(), mkdir(),
 * touch(), fclose(), proc_open()) neste arquivo são chamadas de teste, sem
 * WordPress carregado — WP_Filesystem não está disponível aqui, mesma
 * justificativa de tests/Support/PluginVersionTest.php.
 */
final class EphemeralSecretFileTest extends TestCase {

	/** @var string */
	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'v3r-core-signing-test-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		$arquivosRestantes = glob( $this->dir . DIRECTORY_SEPARATOR . '*' );

		foreach ( false === $arquivosRestantes ? array() : $arquivosRestantes as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $file );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $this->dir );

		parent::tearDown();
	}

	public function testWriteGravaConteudoComPermissaoRestrita(): void {
		$file = EphemeralSecretFile::write( 'material sensível', $this->dir );

		$this->assertFileExists( $file->path() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( 'material sensível', file_get_contents( $file->path() ) );
		$this->assertSame( '0600', substr( sprintf( '%o', fileperms( $file->path() ) ), -4 ) );

		$file->dispose();
	}

	public function testNomeEhImprevisivelEntreDuasEscritas(): void {
		$primeiro = EphemeralSecretFile::write( 'a', $this->dir );
		$segundo  = EphemeralSecretFile::write( 'b', $this->dir );

		$this->assertNotSame( $primeiro->path(), $segundo->path() );

		$primeiro->dispose();
		$segundo->dispose();
	}

	public function testDisposeRemoveOArquivoEEhIdempotente(): void {
		$file = EphemeralSecretFile::write( 'x', $this->dir );
		$path = $file->path();

		$file->dispose();
		$this->assertFileDoesNotExist( $path );

		// Chamar de novo não é erro (idempotente) — importante porque
		// dispose() explícito e a shutdown function podem chamar os dois.
		$file->dispose();
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * __destruct() por si só, sem passar pelo caminho de write() (que
	 * registra a shutdown function e mantém uma referência à instância
	 * viva até o fim do processo — ver o docblock da classe). Isto prova
	 * a segunda rede isoladamente, sem depender de quando o GC do PHP
	 * decide coletar um objeto ainda referenciado por outro lugar.
	 */
	public function testDestructRemoveOArquivoQuandoAInstanciaEDescartadaForaDoCaminhoDeWrite(): void {
		$path = $this->dir . DIRECTORY_SEPARATOR . 'v3r-core-signing-destruct-test.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, 'material' );

		$constructor = ( new \ReflectionClass( EphemeralSecretFile::class ) )->getConstructor();

		if ( null === $constructor ) {
			$this->fail( 'EphemeralSecretFile deveria ter um construtor.' );
		}

		$constructor->setAccessible( true );

		$file = ( new \ReflectionClass( EphemeralSecretFile::class ) )->newInstanceWithoutConstructor();
		$constructor->invoke( $file, $path );

		$this->assertFileExists( $path );

		unset( $file );

		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * Prova a garantia "removido mesmo em caminho não-feliz" de verdade,
	 * não por leitura de código: um subprocesso PHP separado escreve o
	 * arquivo e termina por uma exceção NÃO tratada (nunca chama
	 * dispose()) — só o register_shutdown_function() interno de write()
	 * pode ter removido o arquivo depois que o subprocesso morreu.
	 */
	public function testArquivoEhRemovidoMesmoQuandoOProcessoTerminaPorExcecaoNaoTratada(): void {
		$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';

		if ( ! is_file( $autoload ) ) {
			$this->markTestSkipped( 'vendor/autoload.php ausente — não é possível rodar o subprocesso de teste.' );
		}

		$dir = $this->dir;

		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export() aqui monta o script do subprocesso de teste, não é debug esquecido.
		$script = sprintf(
			'require %s; $f = \V3R\Core\Signing\EphemeralSecretFile::write("segredo", %s); ' .
				'file_put_contents(%s, $f->path()); throw new \RuntimeException("falha proposital, sem dispose()");',
			var_export( $autoload, true ),
			var_export( $dir, true ),
			var_export( $dir . DIRECTORY_SEPARATOR . 'path-escrito.txt', true )
		);
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_var_export

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- teste precisa de um processo PHP separado de verdade, para provar a limpeza via shutdown function num processo que morre por exceção não tratada.
		$process = proc_open(
			array( PHP_BINARY, '-r', $script ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		$this->assertNotFalse( $process );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[2] );
		proc_close( $process );

		$pathFile = $dir . DIRECTORY_SEPARATOR . 'path-escrito.txt';
		$this->assertFileExists( $pathFile, 'o subprocesso precisa ter chegado a escrever o arquivo sensível antes de lançar a exceção.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pathDoArquivoSensivel = file_get_contents( $pathFile );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $pathFile );

		if ( false === $pathDoArquivoSensivel ) {
			$this->fail( 'Não foi possível ler o caminho escrito pelo subprocesso.' );
		}

		// A prova em si: o processo terminou por exceção NÃO tratada, sem
		// nunca chamar dispose() — se o arquivo não existe mais, foi a
		// shutdown function registrada dentro de write() que removeu.
		$this->assertFileDoesNotExist( $pathDoArquivoSensivel );
	}

	public function testSweepOrphansRemoveApenasArquivosMaisVelhosQueOLimiar(): void {
		$antigo  = $this->dir . DIRECTORY_SEPARATOR . 'v3r-core-signing-antigo.tmp';
		$recente = $this->dir . DIRECTORY_SEPARATOR . 'v3r-core-signing-recente.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $antigo, 'sobra de processo morto' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $recente, 'ainda em uso' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		touch( $antigo, time() - 7200 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		touch( $recente, time() );

		$removidos = EphemeralSecretFile::sweepOrphans( $this->dir, 3600 );

		$this->assertSame( 1, $removidos );
		$this->assertFileDoesNotExist( $antigo );
		$this->assertFileExists( $recente );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $recente );
	}

	public function testSweepOrphansIgnoraArquivosSemOPrefixo(): void {
		// Controle negativo: não é uma varredura genérica de "tudo velho
		// no diretório" — só o que tem o prefixo desta classe.
		$outroArquivo = $this->dir . DIRECTORY_SEPARATOR . 'nao-eh-nosso.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $outroArquivo, 'de outro processo qualquer' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		touch( $outroArquivo, time() - 7200 );

		$removidos = EphemeralSecretFile::sweepOrphans( $this->dir, 3600 );

		$this->assertSame( 0, $removidos );
		$this->assertFileExists( $outroArquivo );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $outroArquivo );
	}
}
