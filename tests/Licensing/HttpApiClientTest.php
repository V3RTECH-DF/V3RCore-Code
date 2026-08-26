<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Licensing;

use PHPUnit\Framework\TestCase;
use V3R\Core\Licensing\ApiException;
use V3R\Core\Licensing\HttpApiClient;
use V3R\Core\Licensing\SignatureVerifier;
use V3R\Core\Tests\Fixtures\Ed25519TestKeys;
use V3R\Core\Tests\Fixtures\TestSigner;
use V3R\Core\Tests\Licensing\Transport\FakeHttpTransport;
use V3R\Core\Licensing\Transport\HttpTransportResult;

final class HttpApiClientTest extends TestCase {

	private FakeHttpTransport $transport;

	private HttpApiClient $client;

	protected function setUp(): void {
		$this->transport = new FakeHttpTransport();
		$verifier        = new SignatureVerifier( Ed25519TestKeys::PUBLIC_KEY_BASE64 );
		$this->client    = new HttpApiClient( 'https://licencas.example.com/wp-json/v3r-license/v1', $this->transport, $verifier, 5 );
	}

	public function test_activate_success_verifies_signature_and_returns_envelope(): void {
		$envelope = TestSigner::sign(
			array(
				'status'            => 'active',
				'expires_at'        => '2027-08-25T00:00:00+00:00',
				'activations_used'  => 1,
				'activations_max'   => 5,
				'product_slug'      => 'v3rlgpd',
				'already_activated' => false,
				'checked_at'        => '2026-08-25T12:00:00+00:00',
			)
		);

		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$result = $this->client->activate( array( 'license_key' => 'V3RL-AAAA' ) );

		self::assertSame( 'active', $result['payload']['status'] );

		$calls = $this->transport->getCalls();
		self::assertSame( 'https://licencas.example.com/wp-json/v3r-license/v1/activate', $calls[0]['url'] );
		self::assertSame( 5, $calls[0]['timeout'] );
	}

	public function test_network_timeout_is_communication_failure(): void {
		$this->transport->enqueue( HttpTransportResult::failure( 'cURL error 28: Operation timed out' ) );

		try {
			$this->client->activate( array( 'license_key' => 'V3RL-AAAA' ) );
			self::fail( 'Deveria ter lançado ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertSame( ApiException::COMMUNICATION_FAILURE, $exception->getErrorCode() );
		}
	}

	public function test_server_5xx_is_communication_failure_even_with_json_looking_body(): void {
		$envelope = TestSigner::sign( array( 'status' => 'active' ) );

		$this->transport->enqueue( HttpTransportResult::success( 503, (string) json_encode( $envelope ) ) );

		try {
			$this->client->validate( array( 'license_key' => 'V3RL-AAAA' ) );
			self::fail( 'Deveria ter lançado ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertSame( ApiException::COMMUNICATION_FAILURE, $exception->getErrorCode() );
		}
	}

	public function test_known_4xx_error_body_is_translated_to_matching_error_code(): void {
		$body = array(
			'code'    => 'license_expired',
			'message' => 'Licença expirada.',
			'data'    => array( 'status' => 403 ),
		);

		$this->transport->enqueue( HttpTransportResult::success( 403, (string) json_encode( $body ) ) );

		try {
			$this->client->activate( array( 'license_key' => 'V3RL-AAAA' ) );
			self::fail( 'Deveria ter lançado ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertSame( 'license_expired', $exception->getErrorCode() );
			self::assertFalse( $exception->isCommunicationFailure() );
		}
	}

	public function test_malformed_json_on_200_is_communication_failure_never_treated_as_no_update(): void {
		$this->transport->enqueue( HttpTransportResult::success( 200, '{"payload": invalid json' ) );

		try {
			$this->client->checkUpdate( array( 'product_slug' => 'v3rlgpd' ) );
			self::fail( 'Deveria ter lançado ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertSame( ApiException::COMMUNICATION_FAILURE, $exception->getErrorCode() );
		}
	}

	public function test_invalid_signature_is_never_treated_as_valid_license(): void {
		$envelope = TestSigner::signWithTamperedSignature( array( 'status' => 'active' ) );

		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		try {
			$this->client->validate( array( 'license_key' => 'V3RL-AAAA' ) );
			self::fail( 'Deveria ter lançado ApiException.' );
		} catch ( ApiException $exception ) {
			// Fatia 2b (docs/api-contract.md §8.9/§8.10): código mais
			// específico que COMMUNICATION_FAILURE, mas isCommunicationFailure()
			// continua true — o protocolo externo (§7) trata os dois de
			// forma idêntica; só o protocolo interno distingue.
			self::assertSame( ApiException::SIGNATURE_INVALID, $exception->getErrorCode() );
			self::assertTrue( $exception->isCommunicationFailure() );
		}
	}

	public function test_missing_signature_field_is_communication_failure(): void {
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( array( 'payload' => array( 'status' => 'active' ) ) ) ) );

		try {
			$this->client->validate( array( 'license_key' => 'V3RL-AAAA' ) );
			self::fail( 'Deveria ter lançado ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertSame( ApiException::SIGNATURE_INVALID, $exception->getErrorCode() );
			self::assertTrue( $exception->isCommunicationFailure() );
		}
	}

	public function test_unrecognized_error_body_shape_is_communication_failure(): void {
		$this->transport->enqueue( HttpTransportResult::success( 404, '{"oops": true}' ) );

		try {
			$this->client->activate( array( 'license_key' => 'V3RL-AAAA' ) );
			self::fail( 'Deveria ter lançado ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertSame( ApiException::COMMUNICATION_FAILURE, $exception->getErrorCode() );
		}
	}

	public function test_deactivate_success_does_not_require_signature(): void {
		$this->transport->enqueue( HttpTransportResult::success( 200, '{"deactivated": true}' ) );

		$result = $this->client->deactivate( array( 'license_key' => 'V3RL-AAAA' ) );

		self::assertTrue( $result['deactivated'] );
	}

	public function test_deactivate_error_is_translated(): void {
		$body = array(
			'code'    => 'domain_not_activated',
			'message' => 'Domínio não ativado.',
			'data'    => array( 'status' => 404 ),
		);

		$this->transport->enqueue( HttpTransportResult::success( 404, (string) json_encode( $body ) ) );

		try {
			$this->client->deactivate( array( 'license_key' => 'V3RL-AAAA' ) );
			self::fail( 'Deveria ter lançado ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertSame( 'domain_not_activated', $exception->getErrorCode() );
		}
	}

	public function test_check_update_verifies_signature_via_get(): void {
		$envelope = TestSigner::sign(
			array(
				'update_available' => false,
				'checked_at'       => '2026-08-25T12:00:00+00:00',
			)
		);

		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$result = $this->client->checkUpdate( array( 'product_slug' => 'v3rlgpd' ) );

		self::assertFalse( $result['payload']['update_available'] );

		$calls = $this->transport->getCalls();
		self::assertSame( 'GET', $calls[0]['method'] );
		self::assertStringContainsString( '/update-check?', $calls[0]['url'] );
	}

	public function test_exception_message_never_contains_the_license_key(): void {
		$this->transport->enqueue( HttpTransportResult::failure( 'timeout' ) );

		try {
			$this->client->activate( array( 'license_key' => 'V3RL-SUPER-SECRET-KEY-2D5C' ) );
			self::fail( 'Deveria ter lançado ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertStringNotContainsString( 'V3RL-SUPER-SECRET-KEY-2D5C', $exception->getMessage() );
		}
	}
}
