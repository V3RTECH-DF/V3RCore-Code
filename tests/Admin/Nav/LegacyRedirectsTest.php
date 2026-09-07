<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\LegacyRedirects;

/**
 * Cobre a peça que fecha o buraco medido na adoção do V3RLGPD
 * (07/09/2026, V3RCore-Code#35, docs/navegacao-do-painel.md): endereço de
 * submenu antigo levando ao destino certo, URL absoluta usada como está,
 * requisição alheia não interceptada, o laço de redirecionamento
 * recusado na declaração, e subdiretório.
 */
final class LegacyRedirectsTest extends TestCase {

	/** @var array<string, mixed> */
	private $originalGet;

	protected function setUp(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só guarda o $_GET original para restaurar em tearDown(), teste não processa formulário.
		$this->originalGet                         = $_GET;
		$GLOBALS['v3r_core_test_safe_redirects']   = array();
		$GLOBALS['v3r_core_test_admin_url_prefix'] = 'https://example.test/wp-admin/';
	}

	protected function tearDown(): void {
		$_GET = $this->originalGet;
	}

	public function test_targetFor_compoe_rota_interna_preservando_os_demais_parametros(): void {
		$_GET = array(
			'page' => 'v3rlgpd-ropa',
			'id'   => '5',
		);

		$redirects = new LegacyRedirects( 'v3rlgpd', array( 'v3rlgpd-ropa' => '/ropa' ) );

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=v3rlgpd&id=5#/ropa',
			$redirects->targetFor( 'v3rlgpd-ropa' )
		);
	}

	public function test_targetFor_normaliza_rota_que_comeca_com_cerquilha(): void {
		$_GET = array( 'page' => 'v3rlgpd-atendimento' );

		$redirects = new LegacyRedirects( 'v3rlgpd', array( 'v3rlgpd-atendimento' => '#/atendimento' ) );

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=v3rlgpd#/atendimento',
			$redirects->targetFor( 'v3rlgpd-atendimento' )
		);
	}

	public function test_targetFor_usa_url_absoluta_como_esta_sem_acrescentar_parametro(): void {
		$_GET = array(
			'page' => 'v3rlgpd-docs',
			'id'   => '5',
		);

		$redirects = new LegacyRedirects(
			'v3rlgpd',
			array( 'v3rlgpd-docs' => 'https://ajuda.v3rtech.com.br/v3rlgpd' )
		);

		self::assertSame(
			'https://ajuda.v3rtech.com.br/v3rlgpd',
			$redirects->targetFor( 'v3rlgpd-docs' )
		);
	}

	public function test_targetFor_devolve_null_para_slug_nao_mapeado(): void {
		$redirects = new LegacyRedirects( 'v3rlgpd', array( 'v3rlgpd-ropa' => '/ropa' ) );

		self::assertNull( $redirects->targetFor( 'v3rlgpd-inexistente' ) );
	}

	public function test_maybeRedirect_nao_intercepta_quando_page_nao_esta_no_mapa(): void {
		$_GET = array( 'page' => 'outro-plugin-qualquer' );

		$terminated = false;
		$redirects  = new LegacyRedirects(
			'v3rlgpd',
			array( 'v3rlgpd-ropa' => '/ropa' ),
			static function () use ( &$terminated ): void {
				$terminated = true;
			}
		);

		$redirects->maybeRedirect();

		self::assertSame( array(), $GLOBALS['v3r_core_test_safe_redirects'] );
		self::assertFalse( $terminated );
	}

	public function test_maybeRedirect_nao_intercepta_quando_nao_ha_parametro_page(): void {
		$_GET = array();

		$terminated = false;
		$redirects  = new LegacyRedirects(
			'v3rlgpd',
			array( 'v3rlgpd-ropa' => '/ropa' ),
			static function () use ( &$terminated ): void {
				$terminated = true;
			}
		);

		$redirects->maybeRedirect();

		self::assertSame( array(), $GLOBALS['v3r_core_test_safe_redirects'] );
		self::assertFalse( $terminated );
	}

	public function test_maybeRedirect_redireciona_e_encerra_quando_page_esta_no_mapa(): void {
		$_GET = array(
			'page' => 'v3rlgpd-ropa',
			'id'   => '5',
		);

		$terminated = false;
		$redirects  = new LegacyRedirects(
			'v3rlgpd',
			array( 'v3rlgpd-ropa' => '/ropa' ),
			static function () use ( &$terminated ): void {
				$terminated = true;
			}
		);

		$redirects->maybeRedirect();

		self::assertSame(
			array( 'https://example.test/wp-admin/admin.php?page=v3rlgpd&id=5#/ropa' ),
			$GLOBALS['v3r_core_test_safe_redirects']
		);
		self::assertTrue( $terminated );
	}

	public function test_construtor_recusa_mapa_que_contem_o_slug_da_propria_entrada_unica(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "a entrada 'v3rlgpd'" );

		new LegacyRedirects(
			'v3rlgpd',
			array(
				'v3rlgpd-ropa' => '/ropa',
				'v3rlgpd'      => '/painel',
			)
		);
	}

	public function test_construtor_aceita_mapa_sem_o_slug_da_propria_entrada_unica(): void {
		// Controle negativo do teste acima: um mapa que não referencia a
		// entrada única não deve ser recusado, e continua funcionando.
		$_GET = array( 'page' => 'v3rlgpd-atendimento' );

		$redirects = new LegacyRedirects(
			'v3rlgpd',
			array(
				'v3rlgpd-ropa'        => '/ropa',
				'v3rlgpd-atendimento' => '/atendimento',
			)
		);

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=v3rlgpd#/atendimento',
			$redirects->targetFor( 'v3rlgpd-atendimento' )
		);
	}

	public function test_construtor_recusa_ownEntrySlug_vazio(): void {
		$this->expectException( \InvalidArgumentException::class );

		new LegacyRedirects( '', array( 'v3rlgpd-ropa' => '/ropa' ) );
	}

	public function test_construtor_recusa_destino_vazio(): void {
		$this->expectException( \InvalidArgumentException::class );

		new LegacyRedirects( 'v3rlgpd', array( 'v3rlgpd-ropa' => '' ) );
	}

	public function test_targetFor_funciona_com_wordpress_em_subdiretorio(): void {
		$GLOBALS['v3r_core_test_admin_url_prefix'] = 'https://example.test/wp/wp-admin/';
		$_GET                                      = array( 'page' => 'v3rlgpd-ropa' );

		$redirects = new LegacyRedirects( 'v3rlgpd', array( 'v3rlgpd-ropa' => '/ropa' ) );

		self::assertSame(
			'https://example.test/wp/wp-admin/admin.php?page=v3rlgpd#/ropa',
			$redirects->targetFor( 'v3rlgpd-ropa' )
		);
	}
}
