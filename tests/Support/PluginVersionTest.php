<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Support;

use PHPUnit\Framework\TestCase;
use V3R\Core\Support\PluginVersion;

/**
 * `Support\PluginVersion::resolve()` (v3rtech-scripts#32, movida de
 * V3RLGPD `Core\PluginVersion` — issue #72 daquele repositório) — a versão
 * é lida no boot do plugin consumidor, então uma falha aqui não pode virar
 * exceção nem string vazia: as duas quebrariam o carregamento do plugin ou
 * o cache-busting de assets em silêncio.
 */
final class PluginVersionTest extends TestCase {

	private string $fixtureFile;

	protected function setUp(): void {
		$GLOBALS['v3r_core_test_get_file_data_throws'] = false;
		$this->fixtureFile                             = tempnam( sys_get_temp_dir(), 'v3r_core_plugin_version_' ) . '.php';
	}

	protected function tearDown(): void {
		$GLOBALS['v3r_core_test_get_file_data_throws'] = false;
		if ( is_file( $this->fixtureFile ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- teste sem WordPress carregado; arquivo de fixture no diretório de temp do processo, não conteúdo do usuário.
			unlink( $this->fixtureFile );
		}
	}

	private function writeFixture( string $contents ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- teste sem WordPress carregado; sem WP_Filesystem disponível.
		file_put_contents( $this->fixtureFile, $contents );
	}

	public function test_reads_version_from_plugin_header(): void {
		$this->writeFixture(
			"<?php\n/**\n * Plugin Name: Fixture Plugin\n * Version: 3.4.5\n */\n"
		);

		$this->assertSame( '3.4.5', PluginVersion::resolve( $this->fixtureFile, '0.0.0-fallback' ) );
	}

	public function test_falls_back_when_file_is_unreadable(): void {
		$resolved = PluginVersion::resolve( '/caminho/inexistente/plugin.php', '9.9.9' );

		$this->assertSame( '9.9.9', $resolved );
		$this->assertNotSame( '', $resolved );
	}

	public function test_falls_back_when_header_is_absent(): void {
		$this->writeFixture( "<?php\n/**\n * Plugin Name: Sem versão nenhuma\n */\n" );

		$this->assertSame( '1.2.3', PluginVersion::resolve( $this->fixtureFile, '1.2.3' ) );
	}

	public function test_falls_back_when_header_is_malformed(): void {
		// "Version:" sem valor nenhum na mesma linha — cabeçalho malformado,
		// não ausente.
		$this->writeFixture( "<?php\n/**\n * Plugin Name: Malformado\n * Version:\n */\n" );

		$this->assertSame( '1.2.3', PluginVersion::resolve( $this->fixtureFile, '1.2.3' ) );
	}

	/**
	 * Se `get_file_data()` (função do WordPress) se comportar mal e lançar,
	 * `resolve()` precisa engolir e cair no fallback — não pode propagar a
	 * exceção e derrubar o boot do plugin que a chama. Sem este teste, uma
	 * futura remoção do try/catch em `resolve()` passaria despercebida.
	 */
	public function test_does_not_let_header_read_failure_break_plugin_boot(): void {
		$this->writeFixture( "<?php\n/**\n * Plugin Name: Fixture Plugin\n * Version: 3.4.5\n */\n" );
		$GLOBALS['v3r_core_test_get_file_data_throws'] = true;

		$resolved = PluginVersion::resolve( $this->fixtureFile, '9.9.9' );

		$this->assertSame( '9.9.9', $resolved );
	}
}
