<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Licensing;

use PHPUnit\Framework\TestCase;
use V3R\Core\Licensing\AdminPage;
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

/**
 * Cobre AdminPage::handleAction() — a parte de decisão testável sem
 * WordPress (nonce/capability/HTML ficam em render(), só exercitável com o
 * wp-admin de verdade). Critério de aceite: instanciar AdminPage não pode
 * lançar nem registrar nada por si só (ver AdminPage::register()).
 */
final class AdminPageTest extends TestCase {

	private FakeHttpTransport $transport;

	private LicenseStorage $storage;

	private AdminPage $adminPage;

	protected function setUp(): void {
		$this->transport = new FakeHttpTransport();
		$verifier        = new SignatureVerifier( Ed25519TestKeys::PUBLIC_KEY_BASE64 );
		$apiClient       = new HttpApiClient( 'https://licencas.example.com/wp-json/v3r-license/v1', $this->transport, $verifier, 5 );
		$this->storage   = new LicenseStorage( 'v3rlgpd', new InMemoryKeyValueStore(), new InMemoryKeyValueStore() );
		$manager         = new LicenseManager( 'v3rlgpd', $apiClient, $this->storage, '1.0.0' );

		$this->adminPage = new AdminPage( $manager, new UpdateGate(), 'manage_options' );
	}

	public function test_instantiating_never_registers_anything_or_throws(): void {
		// register() sem WordPress carregado é no-op seguro.
		$this->adminPage->register();

		self::addToAssertionCount( 1 );
	}

	public function test_activate_without_key_returns_error_notice(): void {
		$result = $this->adminPage->handleAction(
			array(
				'action' => 'activate',
				'license_key' => '',
			)
		);

		self::assertSame( 'error', $result['type'] );
	}

	public function test_activate_success_returns_success_notice(): void {
		$envelope = TestSigner::sign( array( 'status' => 'active' ) );
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$result = $this->adminPage->handleAction(
			array(
				'action' => 'activate',
				'license_key' => 'V3RL-AAAA',
			)
		);

		self::assertSame( 'success', $result['type'] );
	}

	public function test_activate_failure_returns_error_notice_but_never_throws(): void {
		$this->transport->enqueue( HttpTransportResult::failure( 'timeout' ) );

		$result = $this->adminPage->handleAction(
			array(
				'action' => 'activate',
				'license_key' => 'V3RL-AAAA',
			)
		);

		self::assertSame( 'error', $result['type'] );
		self::assertStringContainsString( 'continua funcionando normalmente', $result['message'] );
	}

	public function test_deactivate_success(): void {
		$this->transport->enqueue( HttpTransportResult::success( 200, '{"deactivated": true}' ) );

		$result = $this->adminPage->handleAction( array( 'action' => 'deactivate' ) );

		self::assertSame( 'success', $result['type'] );
	}

	public function test_refresh_success(): void {
		$this->storage->save( new LicenseState( 'V3RL-AAAA', LicenseStatus::ACTIVE, null, 0, null, new \DateTimeImmutable( '2020-01-01' ), null, 'v3rlgpd' ) );

		$envelope = TestSigner::sign( array( 'status' => 'active' ) );
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$result = $this->adminPage->handleAction( array( 'action' => 'refresh' ) );

		self::assertSame( 'success', $result['type'] );
	}

	public function test_unknown_action_returns_error_notice(): void {
		$result = $this->adminPage->handleAction( array( 'action' => 'nao-existe' ) );

		self::assertSame( 'error', $result['type'] );
	}
}
