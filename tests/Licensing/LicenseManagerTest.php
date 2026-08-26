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
use V3R\Core\Updater\UpdateGate;

/**
 * Cobre a tabela de docs/api-contract.md §7 — cada linha tem um teste
 * correspondente aqui (ou em HttpApiClientTest, quando a regra pertence à
 * tradução HTTP em vez de à orquestração).
 */
final class LicenseManagerTest extends TestCase {

	private FakeHttpTransport $transport;

	private LicenseStorage $storage;

	private LicenseManager $manager;

	private UpdateGate $gate;

	protected function setUp(): void {
		$this->transport = new FakeHttpTransport();
		$verifier        = new SignatureVerifier( Ed25519TestKeys::PUBLIC_KEY_BASE64 );
		$apiClient       = new HttpApiClient( 'https://licencas.example.com/wp-json/v3r-license/v1', $this->transport, $verifier, 5 );
		$this->storage   = new LicenseStorage( 'v3rlgpd', new InMemoryKeyValueStore(), new InMemoryKeyValueStore() );
		$this->manager   = new LicenseManager(
			'v3rlgpd',
			$apiClient,
			$this->storage,
			'1.0.0',
			static function (): string {
				return 'https://cliente.example.com';
			},
			static function (): string {
				return '6.6';
			}
		);
		$this->gate      = new UpdateGate();
	}

	public function test_activate_success_persists_state_and_marks_cache_fresh(): void {
		$now = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );

		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $this->activePayload( $now ) ) ) );

		$state = $this->manager->activate( 'V3RL-AAAA', $now );

		self::assertSame( LicenseStatus::ACTIVE, $state->getStatus() );
		self::assertEquals( $now, $state->getLastCheckedAt() );
		self::assertNull( $state->getGraceUntil() );
		self::assertTrue( $this->storage->hasFreshValidationCache() );

		$reloaded = $this->manager->getState();
		self::assertSame( LicenseStatus::ACTIVE, $reloaded->getStatus() );
	}

	public function test_activate_failure_does_not_change_local_state(): void {
		$this->transport->enqueue( HttpTransportResult::failure( 'timeout' ) );

		$before = $this->manager->getState();

		try {
			$this->manager->activate( 'V3RL-AAAA' );
			self::fail( 'Deveria ter lançado ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertSame( ApiException::COMMUNICATION_FAILURE, $exception->getErrorCode() );
		}

		self::assertEquals( $before, $this->manager->getState() );
	}

	public function test_activate_business_error_does_not_change_local_state(): void {
		$body = array(
			'code'    => 'invalid_key',
			'message' => 'Chave inválida.',
			'data'    => array( 'status' => 404 ),
		);
		$this->transport->enqueue( HttpTransportResult::success( 404, (string) json_encode( $body ) ) );

		try {
			$this->manager->activate( 'V3RL-INVALID' );
			self::fail( 'Deveria ter lançado ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertSame( 'invalid_key', $exception->getErrorCode() );
		}

		self::assertSame( LicenseStatus::INACTIVE, $this->manager->getState()->getStatus() );
	}

	public function test_deactivate_success_clears_local_state(): void {
		$this->activateSuccessfully();

		$this->transport->enqueue( HttpTransportResult::success( 200, '{"deactivated": true}' ) );

		$state = $this->manager->deactivate();

		self::assertSame( LicenseStatus::INACTIVE, $state->getStatus() );
		self::assertSame( LicenseStatus::INACTIVE, $this->manager->getState()->getStatus() );
	}

	public function test_deactivate_failure_preserves_local_state(): void {
		$this->activateSuccessfully();
		$before = $this->manager->getState();

		$body = array(
			'code'    => 'domain_not_activated',
			'message' => 'Domínio não ativado.',
			'data'    => array( 'status' => 404 ),
		);
		$this->transport->enqueue( HttpTransportResult::success( 404, (string) json_encode( $body ) ) );

		try {
			$this->manager->deactivate();
			self::fail( 'Deveria ter lançado ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertSame( 'domain_not_activated', $exception->getErrorCode() );
		}

		self::assertEquals( $before, $this->manager->getState() );
	}

	public function test_network_timeout_keeps_last_state_and_enters_grace_period(): void {
		$activatedAt = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$this->activateSuccessfully( $activatedAt );

		$this->transport->enqueue( HttpTransportResult::failure( 'timeout' ) );

		$now   = $activatedAt->modify( '+1 day' );
		$state = $this->manager->refresh( true, $now );

		self::assertSame( LicenseStatus::ACTIVE, $state->getStatus() );
		self::assertNotNull( $state->getGraceUntil() );
		self::assertTrue( $state->isInGracePeriod( $now ) );
		self::assertTrue( $this->gate->canUpdate( $state, $now ) );
	}

	public function test_server_5xx_keeps_last_state_and_enters_grace_period(): void {
		$activatedAt = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$this->activateSuccessfully( $activatedAt );

		$this->transport->enqueue( HttpTransportResult::success( 503, '{"code":"server_error"}' ) );

		$now   = $activatedAt->modify( '+1 day' );
		$state = $this->manager->refresh( true, $now );

		self::assertSame( LicenseStatus::ACTIVE, $state->getStatus() );
		self::assertTrue( $state->isInGracePeriod( $now ) );
	}

	public function test_known_4xx_error_body_keeps_last_state_and_enters_grace_period(): void {
		$activatedAt = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$this->activateSuccessfully( $activatedAt );

		$body = array(
			'code'    => 'rate_limited',
			'message' => 'Muitas tentativas.',
			'data'    => array( 'status' => 429 ),
		);
		$this->transport->enqueue( HttpTransportResult::success( 429, (string) json_encode( $body ) ) );

		$now   = $activatedAt->modify( '+1 day' );
		$state = $this->manager->refresh( true, $now );

		self::assertSame( LicenseStatus::ACTIVE, $state->getStatus() );
		self::assertTrue( $state->isInGracePeriod( $now ) );
	}

	public function test_malformed_json_is_never_treated_as_no_update_available(): void {
		$activatedAt = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$this->activateSuccessfully( $activatedAt );

		$this->transport->enqueue( HttpTransportResult::success( 200, '{not json' ) );

		$now   = $activatedAt->modify( '+1 day' );
		$state = $this->manager->refresh( true, $now );

		// Continua achando que está ativa (mantém último estado conhecido) —
		// nunca "sem update" nem "inativa" por causa de um corpo quebrado.
		self::assertSame( LicenseStatus::ACTIVE, $state->getStatus() );
		self::assertTrue( $this->gate->canUpdate( $state, $now ) );
	}

	public function test_invalid_or_missing_signature_never_becomes_valid_license(): void {
		$activatedAt = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$this->activateSuccessfully( $activatedAt );

		$tampered = TestSigner::signWithTamperedSignature(
			array(
				'status'     => 'expired',
				'checked_at' => $activatedAt->modify( '+1 day' )->format( DATE_ATOM ),
			)
		);
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $tampered ) ) );

		$now   = $activatedAt->modify( '+1 day' );
		$state = $this->manager->refresh( true, $now );

		// A resposta "diz" expired, mas como a assinatura é inválida, o
		// estado JAMAIS pode virar "expired" a partir dela — continua ACTIVE
		// (último estado conhecido), em grace period.
		self::assertSame( LicenseStatus::ACTIVE, $state->getStatus() );
		self::assertTrue( $state->isInGracePeriod( $now ) );
	}

	public function test_signed_confirmation_of_expired_suspends_update_immediately_without_grace(): void {
		$activatedAt = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$this->activateSuccessfully( $activatedAt );

		$now      = $activatedAt->modify( '+1 day' );
		$envelope = TestSigner::sign(
			array(
				'status'     => 'expired',
				'checked_at' => $now->format( DATE_ATOM ),
			)
		);
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$state = $this->manager->refresh( true, $now );

		self::assertSame( LicenseStatus::EXPIRED, $state->getStatus() );
		self::assertNull( $state->getGraceUntil() );
		self::assertFalse( $this->gate->canUpdate( $state, $now ) );
	}

	public function test_signed_confirmation_of_revoked_suspends_update_immediately_without_grace(): void {
		$activatedAt = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$this->activateSuccessfully( $activatedAt );

		$now      = $activatedAt->modify( '+1 day' );
		$envelope = TestSigner::sign(
			array(
				'status'     => 'revoked',
				'checked_at' => $now->format( DATE_ATOM ),
			)
		);
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$state = $this->manager->refresh( true, $now );

		self::assertSame( LicenseStatus::REVOKED, $state->getStatus() );
		self::assertFalse( $this->gate->canUpdate( $state, $now ) );

		// Regra de produto inegociável: revogada não desativa nada — não há
		// método nesta biblioteca capaz de desativar o plugin hospedeiro.
		// A prova é estrutural (ver UpdateGateTest), aqui confirmamos que o
		// LicenseManager também não faz nada além de marcar o status.
	}

	public function test_grace_period_day_14_still_receives_update(): void {
		$activatedAt = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$this->activateSuccessfully( $activatedAt );

		$this->transport->enqueue( HttpTransportResult::failure( 'timeout' ) );

		$day14 = $activatedAt->modify( '+' . UpdateGate::GRACE_PERIOD_DAYS . ' days' );
		$state = $this->manager->refresh( true, $day14 );

		self::assertTrue( $this->gate->canUpdate( $state, $day14 ) );
	}

	public function test_grace_period_day_15_stops_updates_but_deactivates_nothing(): void {
		$activatedAt = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$this->activateSuccessfully( $activatedAt );

		$this->transport->enqueue( HttpTransportResult::failure( 'timeout' ) );

		$day15 = $activatedAt->modify( '+' . ( UpdateGate::GRACE_PERIOD_DAYS + 1 ) . ' days' );
		$state = $this->manager->refresh( true, $day15 );

		self::assertFalse( $this->gate->canUpdate( $state, $day15 ) );

		// O estado local continua existindo e "ativo" do ponto de vista da
		// licença em si — só o update parou. Nada na lib desativa o plugin.
		self::assertSame( LicenseStatus::ACTIVE, $state->getStatus() );
	}

	public function test_refresh_without_force_skips_network_call_within_12h_cache(): void {
		$this->activateSuccessfully();

		// Nenhum resultado enfileirado: se o transporte for chamado, o
		// FakeHttpTransport lança RuntimeException.
		$state = $this->manager->refresh( false );

		self::assertSame( LicenseStatus::ACTIVE, $state->getStatus() );
		self::assertSame( 1, $this->transport->getCallCount() ); // só a chamada de activate().
	}

	public function test_refresh_with_force_ignores_12h_cache(): void {
		$this->activateSuccessfully();

		$now      = new DateTimeImmutable( '2026-08-02T00:00:00+00:00' );
		$envelope = $this->activePayload( $now );
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$this->manager->refresh( true, $now );

		self::assertSame( 2, $this->transport->getCallCount() );
	}

	public function test_refresh_never_called_before_any_activation(): void {
		$state = $this->manager->refresh( true );

		self::assertSame( LicenseStatus::INACTIVE, $state->getStatus() );
		self::assertSame( 0, $this->transport->getCallCount() );
	}

	public function test_activation_failure_exception_never_contains_full_license_key(): void {
		$this->transport->enqueue( HttpTransportResult::failure( 'timeout' ) );

		try {
			$this->manager->activate( 'V3RL-SUPER-SECRET-9F3A' );
			self::fail( 'Deveria ter lançado ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertStringNotContainsString( 'V3RL-SUPER-SECRET-9F3A', $exception->getMessage() );
		}
	}

	private function activateSuccessfully( ?DateTimeImmutable $now = null ): LicenseState {
		$now = $now ?? new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );

		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $this->activePayload( $now ) ) ) );

		return $this->manager->activate( 'V3RL-AAAA', $now );
	}

	/**
	 * @return array{payload: array<string, mixed>, signature: string}
	 */
	private function activePayload( DateTimeImmutable $now ): array {
		return TestSigner::sign(
			array(
				'status'             => 'active',
				'expires_at'         => null,
				'activations_used'   => 1,
				'activations_max'    => 5,
				'product_slug'       => 'v3rlgpd',
				'already_activated'  => false,
				'checked_at'         => $now->format( DATE_ATOM ),
			)
		);
	}
}
