<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Support;

use PHPUnit\Framework\TestCase;
use V3R\Core\Support\SiteIdentity;

final class SiteIdentityTest extends TestCase {

	private SiteIdentity $identity;

	protected function setUp(): void {
		$this->identity = new SiteIdentity();
	}

	public function test_normalizes_protocol_www_port_and_case_to_same_domain(): void {
		$a = $this->identity->normalizeDomain( 'https://WWW.Exemplo.com.br:443/' );
		$b = $this->identity->normalizeDomain( 'http://exemplo.com.br' );

		self::assertSame( $a, $b );
		self::assertSame( 'exemplo.com.br', $a );
	}

	public function test_trailing_slash_and_bare_domain_normalize_equally(): void {
		self::assertSame(
			$this->identity->normalizeDomain( 'https://exemplo.com.br/' ),
			$this->identity->normalizeDomain( 'exemplo.com.br' )
		);
	}

	/**
	 * @dataProvider localHostsProvider
	 */
	public function test_recognizes_local_hosts_as_test_environment( string $url ): void {
		self::assertTrue( $this->identity->isTestEnvironment( $url ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function localHostsProvider(): array {
		return array(
			'localhost bare'      => array( 'http://localhost' ),
			'localhost with port' => array( 'http://localhost:8080' ),
			'loopback ip'         => array( 'http://127.0.0.1' ),
			'.local tld'          => array( 'https://meusite.local' ),
			'.test tld'           => array( 'https://meusite.test' ),
			'.localhost tld'      => array( 'https://meusite.localhost' ),
			'staging prefix'      => array( 'https://staging.foo.com.br' ),
			'dev prefix'          => array( 'https://dev.foo.com.br' ),
			'homolog domain'      => array( 'https://teste.bpky.pro.br' ),
			'homolog subdomain'   => array( 'https://cliente.teste.bpky.pro.br' ),
		);
	}

	public function test_domain_containing_staging_as_substring_is_not_test_environment(): void {
		// "meustaging.com.br" não é ambiente de teste: só conta o primeiro
		// rótulo do host ser exatamente "staging" ou "dev".
		self::assertFalse( $this->identity->isTestEnvironment( 'https://meustaging.com.br' ) );
	}

	public function test_domain_containing_dev_as_substring_is_not_test_environment(): void {
		self::assertFalse( $this->identity->isTestEnvironment( 'https://desenvolvimento.com.br' ) );
	}

	public function test_production_domain_is_not_test_environment(): void {
		self::assertFalse( $this->identity->isTestEnvironment( 'https://v3rtech.com.br' ) );
	}

	public function test_similar_but_different_homolog_domain_is_not_test_environment(): void {
		self::assertFalse( $this->identity->isTestEnvironment( 'https://naotesteb.pky.pro.br' ) );
	}

	public function test_wp_environment_type_marks_any_domain_as_test_environment(): void {
		$GLOBALS['v3r_core_test_environment_type'] = 'staging';

		try {
			self::assertTrue( $this->identity->isTestEnvironment( 'https://producao-de-verdade.com.br' ) );
		} finally {
			$GLOBALS['v3r_core_test_environment_type'] = 'production';
		}
	}

	public function test_wp_environment_type_production_does_not_force_test_environment(): void {
		$GLOBALS['v3r_core_test_environment_type'] = 'production';

		self::assertFalse( $this->identity->isTestEnvironment( 'https://producao-de-verdade.com.br' ) );
	}
}
