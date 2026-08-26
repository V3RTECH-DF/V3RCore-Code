<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Rest;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use V3R\Core\Licensing\HttpApiClient;
use V3R\Core\Licensing\LicenseManager;
use V3R\Core\Licensing\LicenseState;
use V3R\Core\Licensing\LicenseStatus;
use V3R\Core\Licensing\LicenseStorage;
use V3R\Core\Licensing\RefreshThrottle;
use V3R\Core\Licensing\SignatureVerifier;
use V3R\Core\Rest\LicenseController;
use V3R\Core\Tests\Fixtures\Ed25519TestKeys;
use V3R\Core\Tests\Fixtures\TestSigner;
use V3R\Core\Tests\Licensing\Storage\InMemoryKeyValueStore;
use V3R\Core\Tests\Licensing\Transport\FakeHttpTransport;
use V3R\Core\Licensing\Transport\HttpTransportResult;
use V3R\Core\Updater\UpdateGate;

/**
 * Cobre docs/api-contract.md §8: autorização (§8.2), schema de resposta
 * (§8.3), ativação/desativação (§8.6/§8.7) e throttle de refresh (§8.8.1).
 */
final class LicenseControllerTest extends TestCase {

	private FakeHttpTransport $transport;

	private LicenseStorage $storage;

	private LicenseManager $manager;

	private LicenseController $controller;

	protected function tearDown(): void {
		unset( $GLOBALS['v3r_core_test_current_user_can'], $GLOBALS['v3r_core_test_valid_nonce'] );
	}

	private const READ_CAPABILITY   = 'v3rlgpd_settings_view';
	private const MANAGE_CAPABILITY = 'v3rlgpd_settings_manage';

	private function makeController( ?RefreshThrottle $throttle = null ): LicenseController {
		$this->transport = new FakeHttpTransport();
		$verifier        = new SignatureVerifier( Ed25519TestKeys::PUBLIC_KEY_BASE64 );
		$apiClient       = new HttpApiClient( 'https://licencas.example.com/wp-json/v3r-license/v1', $this->transport, $verifier, 5 );
		$this->storage   = new LicenseStorage( 'v3rlgpd', new InMemoryKeyValueStore(), new InMemoryKeyValueStore() );
		$this->manager   = new LicenseManager( 'v3rlgpd', $apiClient, $this->storage, '1.0.0' );

		return new LicenseController( $this->manager, new UpdateGate(), self::READ_CAPABILITY, self::MANAGE_CAPABILITY, $throttle );
	}

	protected function setUp(): void {
		$this->controller = $this->makeController();
	}

	public function test_denies_read_without_capability_even_with_valid_nonce(): void {
		$GLOBALS['v3r_core_test_current_user_can'] = false;
		$GLOBALS['v3r_core_test_valid_nonce']      = 'abc123';

		$request = new \WP_REST_Request();
		$request->set_header( 'X-WP-Nonce', 'abc123' );

		self::assertFalse( $this->controller->permission_callback_read( $request ) );
	}

	public function test_denies_read_without_valid_nonce_even_with_capability(): void {
		$GLOBALS['v3r_core_test_current_user_can'] = array( self::READ_CAPABILITY, self::MANAGE_CAPABILITY );
		$GLOBALS['v3r_core_test_valid_nonce']      = 'abc123';

		$request = new \WP_REST_Request();
		$request->set_header( 'X-WP-Nonce', 'nonce-errado' );

		self::assertFalse( $this->controller->permission_callback_read( $request ) );
	}

	public function test_denies_read_missing_nonce_header(): void {
		$GLOBALS['v3r_core_test_current_user_can'] = array( self::READ_CAPABILITY, self::MANAGE_CAPABILITY );
		$GLOBALS['v3r_core_test_valid_nonce']      = 'abc123';

		self::assertFalse( $this->controller->permission_callback_read( new \WP_REST_Request() ) );
	}

	public function test_allows_read_when_capability_and_nonce_are_both_valid(): void {
		$GLOBALS['v3r_core_test_current_user_can'] = array( self::READ_CAPABILITY, self::MANAGE_CAPABILITY );
		$GLOBALS['v3r_core_test_valid_nonce']      = 'abc123';

		$request = new \WP_REST_Request();
		$request->set_header( 'X-WP-Nonce', 'abc123' );

		self::assertTrue( $this->controller->permission_callback_read( $request ) );
	}

	/**
	 * Critério de aceite da issue #9: usuário com a capability de leitura
	 * mas sem a de gestão consegue GET/refresh (permission_callback_read)
	 * e recebe 403 em activate/deactivate (permission_callback_manage) —
	 * testado isoladamente, sem depender de is_admin() nem de esconder
	 * botão em tela.
	 */
	public function test_read_only_user_passes_read_permission_but_fails_manage_permission(): void {
		$GLOBALS['v3r_core_test_current_user_can'] = array( self::READ_CAPABILITY );
		$GLOBALS['v3r_core_test_valid_nonce']      = 'abc123';

		$request = new \WP_REST_Request();
		$request->set_header( 'X-WP-Nonce', 'abc123' );

		self::assertTrue( $this->controller->permission_callback_read( $request ) );
		self::assertFalse( $this->controller->permission_callback_manage( $request ) );
	}

	public function test_manage_capable_user_passes_both_permissions(): void {
		$GLOBALS['v3r_core_test_current_user_can'] = array( self::READ_CAPABILITY, self::MANAGE_CAPABILITY );
		$GLOBALS['v3r_core_test_valid_nonce']      = 'abc123';

		$request = new \WP_REST_Request();
		$request->set_header( 'X-WP-Nonce', 'abc123' );

		self::assertTrue( $this->controller->permission_callback_read( $request ) );
		self::assertTrue( $this->controller->permission_callback_manage( $request ) );
	}

	public function test_denies_manage_without_valid_nonce_even_with_capability(): void {
		$GLOBALS['v3r_core_test_current_user_can'] = array( self::READ_CAPABILITY, self::MANAGE_CAPABILITY );
		$GLOBALS['v3r_core_test_valid_nonce']      = 'abc123';

		$request = new \WP_REST_Request();
		$request->set_header( 'X-WP-Nonce', 'nonce-errado' );

		self::assertFalse( $this->controller->permission_callback_manage( $request ) );
	}

	public function test_get_state_never_touches_the_network(): void {
		$result = $this->controller->get_state( new \WP_REST_Request() );

		self::assertSame( LicenseStatus::INACTIVE, $result['status'] );
		self::assertSame( 0, $this->transport->getCallCount() );
	}

	public function test_activate_without_license_key_returns_missing_license_key_error(): void {
		$result = $this->controller->activate( new \WP_REST_Request() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'missing_license_key', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_activate_success_returns_masked_state(): void {
		$envelope = TestSigner::sign(
			array(
				'status'           => 'active',
				'expires_at'       => '2027-08-25T00:00:00+00:00',
				'activations_used' => 1,
				'activations_max'  => 5,
			)
		);
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$request = new \WP_REST_Request();
		$request->set_param( 'license_key', 'V3RL-AAAA-BBBB-2D5C' );

		$result = $this->controller->activate( $request );

		self::assertIsArray( $result );
		self::assertSame( 'active', $result['status'] );
		self::assertStringNotContainsString( 'V3RL-AAAA-BBBB-2D5C', $result['license_key_masked'] );
		self::assertSame( 'V3RL-XXXX-...-2D5C', $result['license_key_masked'] );
	}

	public function test_activate_business_error_from_server_is_translated(): void {
		$body = array(
			'code'    => 'invalid_key',
			'message' => 'Chave inválida.',
			'data'    => array( 'status' => 404 ),
		);
		$this->transport->enqueue( HttpTransportResult::success( 404, (string) json_encode( $body ) ) );

		$request = new \WP_REST_Request();
		$request->set_param( 'license_key', 'V3RL-INVALID' );

		$result = $this->controller->activate( $request );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'invalid_key', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_activate_signature_invalid_returns_502_distinct_from_server_unreachable(): void {
		$envelope = TestSigner::signWithTamperedSignature( array( 'status' => 'active' ) );
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$request = new \WP_REST_Request();
		$request->set_param( 'license_key', 'V3RL-AAAA' );

		$result = $this->controller->activate( $request );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'signature_invalid', $result->get_error_code() );
		self::assertSame( 502, $result->get_error_data()['status'] );
	}

	public function test_activate_network_timeout_returns_server_unreachable_503(): void {
		$this->transport->enqueue( HttpTransportResult::failure( 'timeout' ) );

		$request = new \WP_REST_Request();
		$request->set_param( 'license_key', 'V3RL-AAAA' );

		$result = $this->controller->activate( $request );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'server_unreachable', $result->get_error_code() );
		self::assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_key_is_always_masked_never_returned_in_full(): void {
		$envelope = TestSigner::sign( array( 'status' => 'active' ) );
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$request = new \WP_REST_Request();
		$request->set_param( 'license_key', 'V3RL-SEGREDO-TOTAL-9F31' );

		$activated = $this->controller->activate( $request );
		self::assertIsArray( $activated );
		self::assertStringNotContainsString( 'SEGREDO', $activated['license_key_masked'] );

		$state = $this->controller->get_state( new \WP_REST_Request() );
		self::assertStringNotContainsString( 'SEGREDO', $state['license_key_masked'] );
	}

	public function test_deactivate_success(): void {
		$this->transport->enqueue( HttpTransportResult::success( 200, '{"deactivated": true}' ) );

		$result = $this->controller->deactivate( new \WP_REST_Request() );

		self::assertSame( array( 'deactivated' => true ), $result );
	}

	public function test_refresh_within_one_minute_window_is_throttled_and_never_contacts_server(): void {
		$throttle   = new RefreshThrottle( 'v3rlgpd', new InMemoryKeyValueStore() );
		$controller = $this->makeController( $throttle );

		// Sem $now fixo: LicenseController::refresh() usa o relógio real
		// internamente, então o teste marca a tentativa com o relógio real
		// também, a poucos milissegundos de distância — bem dentro da
		// janela de 1 minuto de qualquer forma.
		$throttle->markAttempt();

		// Não enfileira NENHUM resultado no transporte: se o throttle
		// falhar e a chamada tentar sair mesmo assim, o teste falha por
		// "nenhum resultado programado" em vez de silenciosamente passar.
		$result = $controller->refresh( new \WP_REST_Request() );

		self::assertIsArray( $result );
		self::assertTrue( $result['throttled'] );
		self::assertIsInt( $result['retry_after'] );
		self::assertGreaterThan( 0, $result['retry_after'] );
		self::assertSame( 0, $this->transport->getCallCount() );
	}

	public function test_refresh_outside_the_window_contacts_server(): void {
		// Throttle isolado por instância em memória — nunca o
		// WordPressTransientStore padrão, para este teste não depender de
		// nenhum estado global compartilhado entre casos de teste.
		$controller = $this->makeController( new RefreshThrottle( 'v3rlgpd', new InMemoryKeyValueStore() ) );

		$envelope = TestSigner::sign( array( 'status' => 'active' ) );
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		// Ativa primeiro (senão refresh() nem tenta contatar o servidor —
		// LicenseManager::refresh() sai cedo para status inactive).
		$this->storage->save( new LicenseState( 'V3RL-AAAA', LicenseStatus::ACTIVE, null, 0, null, new DateTimeImmutable( '2020-01-01' ), null, 'v3rlgpd' ) );

		$result = $controller->refresh( new \WP_REST_Request() );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'throttled', $result );
		self::assertSame( 1, $this->transport->getCallCount() );
	}
}
