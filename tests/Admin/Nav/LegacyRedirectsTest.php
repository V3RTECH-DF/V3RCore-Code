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
 *
 * `registerMissingPages()` cobre o segundo defeito (V3RCore-Code#40):
 * slug antigo NÃO registrado por ninguém era recusado pelo WordPress
 * antes de `admin_init` disparar, então `maybeRedirect()` nunca era
 * chamado. A prova de que a recusa acontecia e deixou de acontecer é do
 * WordPress real (`bin/sonda-acesso-por-pessoa.php`); aqui só se prova a
 * composição correta da marcação e a discriminação entre slug ausente e
 * já registrado.
 */
final class LegacyRedirectsTest extends TestCase {

	/** @var array<string, mixed> */
	private $originalGet;

	protected function setUp(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só guarda o $_GET original para restaurar em tearDown(), teste não processa formulário.
		$this->originalGet                                 = $_GET;
		$GLOBALS['v3r_core_test_safe_redirects']           = array();
		$GLOBALS['v3r_core_test_admin_url_prefix']         = 'https://example.test/wp-admin/';
		$GLOBALS['v3r_core_test_registered_submenu_pages'] = array();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- stub de teste: simula $menu/$submenu que o WordPress real popula em admin_menu, para provar registerMissingPages() sem wp-admin/menu.php carregado.
		$GLOBALS['menu'] = array();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- mesmo motivo da linha acima.
		$GLOBALS['submenu'] = array();
	}

	protected function tearDown(): void {
		$_GET = $this->originalGet;
		unset( $GLOBALS['menu'], $GLOBALS['submenu'] );
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

	public function test_targetFor_compoe_slug_de_pagina_do_painel_preservando_os_demais_parametros(): void {
		$_GET = array(
			'page' => 'gea-antigo',
			'id'   => '42',
		);

		$redirects = new LegacyRedirects( 'gea', array( 'gea-antigo' => 'gea-charge-detail' ) );

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=gea-charge-detail&id=42',
			$redirects->targetFor( 'gea-antigo' )
		);
	}

	public function test_targetFor_slug_de_pagina_nao_vaza_o_page_do_endereco_antigo(): void {
		// Controle negativo do teste acima: o `page` da requisição original
		// (o endereço antigo) não pode aparecer duplicado nem sobrepor o
		// `page` do destino composto.
		$_GET = array( 'page' => 'gea-antigo' );

		$redirects = new LegacyRedirects( 'gea', array( 'gea-antigo' => 'gea-charge-detail' ) );

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=gea-charge-detail',
			$redirects->targetFor( 'gea-antigo' )
		);
	}

	public function test_construtor_recusa_destino_ambiguo_com_barra(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "o destino 'gea/charge-detail'" );

		new LegacyRedirects( 'gea', array( 'gea-antigo' => 'gea/charge-detail' ) );
	}

	public function test_construtor_recusa_destino_ambiguo_com_cara_de_dominio_sem_esquema(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "o destino 'ajuda.v3rtech.com.br'" );

		new LegacyRedirects( 'gea', array( 'gea-antigo' => 'ajuda.v3rtech.com.br' ) );
	}

	public function test_construtor_aceita_slug_simples_sem_barra_nem_ponto(): void {
		// Controle negativo dos dois testes acima: um slug de página comum
		// não deve ser recusado.
		$redirects = new LegacyRedirects( 'gea', array( 'gea-antigo' => 'gea-charge-detail' ) );

		self::assertInstanceOf( LegacyRedirects::class, $redirects );
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

	/**
	 * Reprodução do defeito (V3RCore-Code#40): `v3rlgpd-docs` nunca é
	 * registrado por ninguém (não é a entrada única, nem coincide com
	 * página oculta de nenhum `Screen`) — sem `registerMissingPages()`, o
	 * WordPress recusaria a requisição com 403 antes de `admin_init`
	 * sequer disparar. Este teste prova o registro da marcação que evita
	 * essa recusa; a recusa em si só é observável no WordPress real (ver
	 * `bin/sonda-acesso-por-pessoa.php`).
	 */
	public function test_registerMissingPages_registra_pagina_oculta_para_slug_nao_registrado(): void {
		$redirects = new LegacyRedirects(
			'v3rlgpd',
			array( 'v3rlgpd-docs' => 'https://ajuda.v3rtech.com.br/v3rlgpd' )
		);

		$redirects->registerMissingPages();

		self::assertCount( 1, $GLOBALS['v3r_core_test_registered_submenu_pages'] );

		$pagina = $GLOBALS['v3r_core_test_registered_submenu_pages'][0];

		self::assertNull( $pagina['parent_slug'] );
		self::assertSame( 'v3rlgpd-docs', $pagina['menu_slug'] );
		self::assertSame( 'read', $pagina['capability'] );
	}

	/**
	 * Controle negativo do teste acima: um slug do mapa que JÁ está
	 * registrado — aqui simulando a coincidência com uma página oculta de
	 * `Screen` já anunciada em `$submenu['']`, o caso real medido no
	 * V3RLGPD (`v3rlgpd-settings`) — não ganha marcação duplicada.
	 */
	public function test_registerMissingPages_nao_registra_marcacao_para_slug_ja_registrado(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- stub de teste, ver setUp().
		$GLOBALS['submenu'][''] = array(
			array( 'ROPA', 'read', 'v3rlgpd-ropa', 'ROPA' ),
		);

		$redirects = new LegacyRedirects( 'v3rlgpd', array( 'v3rlgpd-ropa' => '/ropa' ) );

		$redirects->registerMissingPages();

		self::assertSame( array(), $GLOBALS['v3r_core_test_registered_submenu_pages'] );
	}

	/**
	 * Controle negativo por outra via: slug que coincide com uma entrada de
	 * TOPO (`$menu`, não `$submenu`) também não ganha marcação.
	 */
	public function test_registerMissingPages_nao_registra_marcacao_para_slug_no_menu_de_topo(): void {
		// O slug ANTIGO do mapa (a chave, não o destino) já existe como
		// entrada de topo — cenário de um plugin que registrou o próprio
		// endereço antigo como `add_menu_page()` de outro produto.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- stub de teste, ver setUp().
		$GLOBALS['menu'][] = array( 'GEA antigo', 'read', 'gea-charge-old', 'GEA antigo' );

		$redirects = new LegacyRedirects( 'gea', array( 'gea-charge-old' => 'gea-charge-detail' ) );

		$redirects->registerMissingPages();

		self::assertSame( array(), $GLOBALS['v3r_core_test_registered_submenu_pages'] );
	}

	/**
	 * Mapa com mais de um slug ausente: cada um ganha a própria marcação,
	 * e só os ausentes — mistura os dois grupos do teste acima num só
	 * cenário, como o mapa real de um plugin costuma ter.
	 */
	public function test_registerMissingPages_trata_cada_slug_do_mapa_de_forma_independente(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- stub de teste, ver setUp().
		$GLOBALS['submenu'][''] = array(
			array( 'ROPA', 'read', 'v3rlgpd-ropa', 'ROPA' ),
		);

		$redirects = new LegacyRedirects(
			'v3rlgpd',
			array(
				'v3rlgpd-ropa' => '/ropa',
				'v3rlgpd-docs' => 'https://ajuda.v3rtech.com.br/v3rlgpd',
			)
		);

		$redirects->registerMissingPages();

		self::assertCount( 1, $GLOBALS['v3r_core_test_registered_submenu_pages'] );
		self::assertSame(
			'v3rlgpd-docs',
			$GLOBALS['v3r_core_test_registered_submenu_pages'][0]['menu_slug']
		);
	}
}
