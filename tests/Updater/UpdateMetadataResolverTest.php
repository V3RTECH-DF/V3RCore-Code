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
 *
 * BUG CORRIGIDO, validado ao vivo: além de checar a RESPOSTA (o que os
 * testes já faziam e não bastava), agora também checamos o PEDIDO — que a
 * checagem de rotina nunca manda a versão instalada como pedido de
 * rollback. Ver LicenseManagerCheckForUpdateTest para o histórico completo.
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

		$availability = $this->resolver->resolve( '1.0.0' );

		self::assertFalse( $availability->isAvailable() );
		self::assertSame( 0, $this->transport->getCallCount() );
	}

	public function test_revoked_license_never_reaches_the_server_even_if_cached_state_is_stale(): void {
		$now = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$this->storage->save( new LicenseState( 'V3RL-AAAA', LicenseStatus::REVOKED, null, 1, 5, $now, null, 'v3rlgpd' ) );

		$availability = $this->resolver->resolve( '1.0.0' );

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

		$availability = $this->resolver->resolve( '1.0.0' );

		self::assertTrue( $availability->isAvailable() );
		self::assertSame( '2.3.0', $availability->getVersion() );
		self::assertSame( '6.0', $availability->getRequires() );
		self::assertSame( '8.0', $availability->getRequiresPhp() );
		self::assertSame( '6.7', $availability->getTested() );
		self::assertSame( 'https://licencas.example.com/download?token=abc', $availability->getPackageUrl() );
		self::assertNull( $availability->getIcons(), 'Payload sem `icons` deve resultar em getIcons() === null.' );
	}

	/**
	 * V3RLicense-Code#23 (segunda metade) — produto com ícone cadastrado:
	 * o servidor manda `icons` com as chaves `1x`/`2x`, e a biblioteca
	 * precisa repassar isso sem alteração.
	 */
	public function test_icons_are_reported_when_the_server_sends_them(): void {
		$now = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$this->activate( $now );

		$envelope = TestSigner::sign(
			array(
				'update_available' => true,
				'version'          => '2.3.0',
				'icons'            => array(
					'1x' => 'https://licencas.example.com/icons/v3rlgpd-128.png',
					'2x' => 'https://licencas.example.com/icons/v3rlgpd-256.png',
				),
				'checked_at'       => $now->format( DATE_ATOM ),
			)
		);
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$availability = $this->resolver->resolve( '1.0.0' );

		self::assertSame(
			array(
				'1x' => 'https://licencas.example.com/icons/v3rlgpd-128.png',
				'2x' => 'https://licencas.example.com/icons/v3rlgpd-256.png',
			),
			$availability->getIcons()
		);
	}

	/**
	 * Guardrail explícito do pedido: `icons` com formato inesperado (aqui,
	 * uma string em vez de um mapa tamanho => URL) não pode derrubar a
	 * checagem de atualização — atualização é o caminho crítico. O payload
	 * malformado é tratado como se `icons` não existisse.
	 */
	public function test_malformed_icons_field_does_not_break_the_update_check(): void {
		$now = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$this->activate( $now );

		$envelope = TestSigner::sign(
			array(
				'update_available' => true,
				'version'          => '2.3.0',
				'icons'            => 'https://licencas.example.com/icons/v3rlgpd.png',
				'checked_at'       => $now->format( DATE_ATOM ),
			)
		);
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$availability = $this->resolver->resolve( '1.0.0' );

		self::assertTrue( $availability->isAvailable(), 'icons malformado não pode impedir a checagem de atualização.' );
		self::assertSame( '2.3.0', $availability->getVersion() );
		self::assertNull( $availability->getIcons() );
	}

	/**
	 * O teste que faltava: a checagem de rotina do resolver (sem segundo
	 * argumento) nunca pode mandar a versão instalada como pedido de
	 * rollback. Só a resposta programada não bastava — o FakeHttpTransport
	 * não interpreta o parâmetro, então um pedido errado com resposta
	 * "há atualização" passava despercebido (era exatamente este o defeito).
	 */
	public function test_routine_resolve_sends_installed_version_only_as_plugin_version_never_as_rollback(): void {
		$now = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$this->activate( $now );

		$envelope = TestSigner::sign(
			array(
				'update_available' => true,
				'version'          => '2.3.0',
				'checked_at'       => $now->format( DATE_ATOM ),
			)
		);
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$this->resolver->resolve( '1.0.0' );

		$call = $this->transport->getCalls()[0];
		self::assertStringContainsString( 'plugin_version=1.0.0', $call['url'] );
		self::assertDoesNotMatchRegularExpression( '/(?<!plugin_)version=1\.0\.0/', $call['url'] );
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

		$availability = $this->resolver->resolve( '1.0.0' );

		self::assertFalse( $availability->isAvailable() );
	}

	public function test_server_failure_on_update_check_never_breaks_and_reports_none(): void {
		$now = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$this->activate( $now );

		$this->transport->enqueue( HttpTransportResult::failure( 'timeout' ) );

		$availability = $this->resolver->resolve( '1.0.0' );

		self::assertFalse( $availability->isAvailable() );
	}
}
