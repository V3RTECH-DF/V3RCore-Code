<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Updater;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use V3R\Core\Licensing\HttpApiClient;
use V3R\Core\Licensing\LicenseManager;
use V3R\Core\Licensing\LicenseState;
use V3R\Core\Licensing\LicenseStatus;
use V3R\Core\Licensing\LicenseStorage;
use V3R\Core\Licensing\SignatureVerifier;
use V3R\Core\Tests\Fixtures\Ed25519TestKeys;
use V3R\Core\Tests\Fixtures\TestSigner;
use V3R\Core\Tests\Licensing\Storage\InMemoryKeyValueStore;
use V3R\Core\Tests\Licensing\Transport\FakeHttpTransport;
use V3R\Core\Licensing\Transport\HttpTransportResult;
use V3R\Core\Updater\UpdateGate;
use V3R\Core\Updater\UpdateMetadataResolver;

/**
 * Critério de aceite: "UpdateChecker respeita o UpdateGate: com licença sem
 * direito, o WordPress não enxerga atualização" — cobre a peça pura que
 * decide isso (a integração de verdade com o Plugin Update Checker,
 * Updater\PucBridge, só roda dentro de um WordPress de verdade).
 */
final class UpdateMetadataResolverTest extends TestCase {

	private FakeHttpTransport $transport;

	private LicenseStorage $storage;

	private LicenseManager $manager;

	private UpdateGate $gate;

	private UpdateMetadataResolver $resolver;

	protected function setUp(): void {
		$this->transport = new FakeHttpTransport();
		$verifier        = new SignatureVerifier( Ed25519TestKeys::PUBLIC_KEY_BASE64 );
		$apiClient       = new HttpApiClient( 'https://licencas.example.com/wp-json/v3r-license/v1', $this->transport, $verifier, 5 );
		$this->storage   = new LicenseStorage( 'v3rlgpd', new InMemoryKeyValueStore(), new InMemoryKeyValueStore() );
		$this->manager   = new LicenseManager( 'v3rlgpd', $apiClient, $this->storage, '1.0.0' );
		$this->gate      = new UpdateGate();
		$this->resolver  = new UpdateMetadataResolver( $this->manager, $this->gate );
	}

	private function activate( DateTimeImmutable $now ): void {
		$this->storage->save(
			new LicenseState( 'V3RL-AAAA', LicenseStatus::ACTIVE, null, 1, 5, $now, null, 'v3rlgpd' )
		);
	}

	public function test_license_without_right_to_update_never_reaches_the_server(): void {
		$this->storage->save( LicenseState::neutral( 'v3rlgpd' ) ); // inactive => gate nega.

		$availability = $this->resolver->resolve();

		self::assertFalse( $availability->isAvailable() );
		self::assertSame( 0, $this->transport->getCallCount() );
	}

	public function test_revoked_license_never_reaches_the_server_even_if_cached_state_is_stale(): void {
		$now = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$this->storage->save( new LicenseState( 'V3RL-AAAA', LicenseStatus::REVOKED, null, 1, 5, $now, null, 'v3rlgpd' ) );

		$availability = $this->resolver->resolve();

		self::assertFalse( $availability->isAvailable() );
		self::assertSame( 0, $this->transport->getCallCount() );
	}

	public function test_active_license_with_available_update_is_reported(): void {
		$now = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$this->activate( $now );

		$envelope = TestSigner::sign(
			array(
				'update_available' => true,
				'version'          => '2.3.0',
				'requires'         => '6.0',
				'requires_php'     => '8.0',
				'tested'           => '6.7',
				'changelog_url'    => 'https://v3rtech.com.br/plugins/v3rlgpd/changelog',
				'package_url'      => 'https://licencas.example.com/download?token=abc',
				'checked_at'       => $now->format( DATE_ATOM ),
			)
		);
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$availability = $this->resolver->resolve();

		self::assertTrue( $availability->isAvailable() );
		self::assertSame( '2.3.0', $availability->getVersion() );
		self::assertSame( '6.0', $availability->getRequires() );
		self::assertSame( '8.0', $availability->getRequiresPhp() );
		self::assertSame( '6.7', $availability->getTested() );
		self::assertSame( 'https://licencas.example.com/download?token=abc', $availability->getPackageUrl() );
	}

	public function test_active_license_without_available_update_reports_none(): void {
		$now = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$this->activate( $now );

		$envelope = TestSigner::sign(
			array(
				'update_available' => false,
				'checked_at'       => $now->format( DATE_ATOM ),
			)
		);
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$availability = $this->resolver->resolve();

		self::assertFalse( $availability->isAvailable() );
	}

	public function test_server_failure_on_update_check_never_breaks_and_reports_none(): void {
		$now = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$this->activate( $now );

		$this->transport->enqueue( HttpTransportResult::failure( 'timeout' ) );

		$availability = $this->resolver->resolve();

		self::assertFalse( $availability->isAvailable() );
	}
}
