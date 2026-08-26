<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Updater;

use PHPUnit\Framework\TestCase;
use V3R\Core\Licensing\HttpApiClient;
use V3R\Core\Licensing\LicenseManager;
use V3R\Core\Licensing\LicenseStorage;
use V3R\Core\Licensing\SignatureVerifier;
use V3R\Core\Tests\Fixtures\Ed25519TestKeys;
use V3R\Core\Tests\Licensing\Storage\InMemoryKeyValueStore;
use V3R\Core\Tests\Licensing\Transport\FakeHttpTransport;
use V3R\Core\Updater\UpdateChecker;
use V3R\Core\Updater\UpdateGate;

/**
 * Fora do WordPress (nenhuma função add_filter/add_action disponível no
 * processo de teste desta biblioteca — ver tests/bootstrap.php), register()
 * precisa ser um no-op seguro: instanciar o Plugin Update Checker de
 * verdade (Updater\PucBridge) sem plugin_basename()/add_filter() reais
 * quebraria o próprio construtor da lib de terceiro. A integração real com
 * os hooks do WordPress só é verificável dentro de um WordPress de verdade
 * (ver relatório da fatia).
 */
final class UpdateCheckerTest extends TestCase {

	public function test_register_is_a_safe_noop_without_wordpress_loaded(): void {
		$apiClient = new HttpApiClient(
			'https://licencas.example.com/wp-json/v3r-license/v1',
			new FakeHttpTransport(),
			new SignatureVerifier( Ed25519TestKeys::PUBLIC_KEY_BASE64 )
		);
		$manager   = new LicenseManager(
			'v3rlgpd',
			$apiClient,
			new LicenseStorage( 'v3rlgpd', new InMemoryKeyValueStore(), new InMemoryKeyValueStore() ),
			'1.0.0'
		);
		$gate      = new UpdateGate();

		$checker = new UpdateChecker( __FILE__, 'v3rlgpd', $manager, $gate );

		$checker->register();
		$checker->register(); // idempotente: segunda chamada não deve lançar.

		self::assertSame( 'v3rlgpd', $checker->getProductSlug() );
		self::assertSame( $manager, $checker->getLicenseManager() );
		self::assertSame( $gate, $checker->getGate() );
		self::assertNotNull( $checker->getResolver() );
	}
}
