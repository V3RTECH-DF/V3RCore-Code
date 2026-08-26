<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Licensing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use V3R\Core\Licensing\ApiException;
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

/**
 * LicenseManager::checkForUpdate() (fatia 2b, docs/api-contract.md §2.4) —
 * só a orquestração da chamada de rede; a decisão de "o site tem direito"
 * é do UpdateGate (ver Updater\UpdateMetadataResolverTest).
 */
final class LicenseManagerCheckForUpdateTest extends TestCase {

	private FakeHttpTransport $transport;

	private LicenseStorage $storage;

	private LicenseManager $manager;

	protected function setUp(): void {
		$this->transport = new FakeHttpTransport();
		$verifier        = new SignatureVerifier( Ed25519TestKeys::PUBLIC_KEY_BASE64 );
		$apiClient       = new HttpApiClient( 'https://licencas.example.com/wp-json/v3r-license/v1', $this->transport, $verifier, 5 );
		$this->storage   = new LicenseStorage( 'v3rlgpd', new InMemoryKeyValueStore(), new InMemoryKeyValueStore() );
		$this->manager   = new LicenseManager( 'v3rlgpd', $apiClient, $this->storage, '1.0.0' );
	}

	public function test_inactive_site_never_contacts_server(): void {
		$result = $this->manager->checkForUpdate();

		self::assertNull( $result );
		self::assertSame( 0, $this->transport->getCallCount() );
	}

	public function test_active_site_sends_product_slug_key_and_version(): void {
		$this->storage->save( new LicenseState( 'V3RL-AAAA', LicenseStatus::ACTIVE, null, 0, null, new DateTimeImmutable( '2020-01-01' ), null, 'v3rlgpd' ) );

		$envelope = TestSigner::sign(
			array(
				'update_available' => false,
				'checked_at' => '2026-08-25T12:00:00+00:00',
			)
		);
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$this->manager->checkForUpdate();

		$call = $this->transport->getCalls()[0];
		self::assertStringContainsString( 'product_slug=v3rlgpd', $call['url'] );
		self::assertStringContainsString( 'license_key=V3RL-AAAA', $call['url'] );
		self::assertStringContainsString( 'plugin_version=1.0.0', $call['url'] );
	}

	public function test_optional_version_is_forwarded_for_rollback(): void {
		$this->storage->save( new LicenseState( 'V3RL-AAAA', LicenseStatus::ACTIVE, null, 0, null, new DateTimeImmutable( '2020-01-01' ), null, 'v3rlgpd' ) );

		$envelope = TestSigner::sign(
			array(
				'update_available' => false,
				'checked_at' => '2026-08-25T12:00:00+00:00',
			)
		);
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$this->manager->checkForUpdate( '2.1.0' );

		$call = $this->transport->getCalls()[0];
		self::assertStringContainsString( 'version=2.1.0', $call['url'] );
	}

	public function test_business_error_from_server_is_repassed(): void {
		$this->storage->save( new LicenseState( 'V3RL-AAAA', LicenseStatus::ACTIVE, null, 0, null, new DateTimeImmutable( '2020-01-01' ), null, 'v3rlgpd' ) );

		$body = array(
			'code'    => 'license_expired',
			'message' => 'Licença expirada.',
			'data'    => array( 'status' => 403 ),
		);
		$this->transport->enqueue( HttpTransportResult::success( 403, (string) json_encode( $body ) ) );

		$this->expectException( ApiException::class );

		$this->manager->checkForUpdate();
	}
}
