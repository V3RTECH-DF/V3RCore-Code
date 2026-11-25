<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Updater;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use V3R\Core\Bootstrap;
use V3R\Core\Licensing\HttpApiClient;
use V3R\Core\Licensing\LicenseManager;
use V3R\Core\Licensing\LicenseState;
use V3R\Core\Licensing\LicenseStatus;
use V3R\Core\Licensing\LicenseStorage;
use V3R\Core\Licensing\SignatureVerifier;
use V3R\Core\Licensing\Transport\HttpTransportResult;
use V3R\Core\Support\Logger;
use V3R\Core\Tests\Fixtures\Ed25519TestKeys;
use V3R\Core\Tests\Fixtures\TestSigner;
use V3R\Core\Tests\Licensing\Storage\InMemoryKeyValueStore;
use V3R\Core\Tests\Licensing\Transport\FakeHttpTransport;
use V3R\Core\Updater\PucBridge;
use V3R\Core\Updater\UpdateGate;
use V3R\Core\Updater\UpdateMetadataResolver;

/**
 * Modo produto gratuito (V3RCore-Code#55, docs/api-contract.md §2.4.1):
 * atualização sem chave. Os testes inspecionam a REQUISIÇÃO montada (URL
 * real recebida pelo transporte dublê) e a contagem de chamadas, não só a
 * resposta programada.
 */
final class FreeProductModeTest extends TestCase {

	private FakeHttpTransport $transport;

	private LicenseStorage $storage;

	/** @var array<int, array{0: string, 1: string}> */
	private array $logged = array();

	private function makeManager( string $siteUrl = 'https://cliente.example.com' ): LicenseManager {
		$this->transport = new FakeHttpTransport();
		$verifier        = new SignatureVerifier( Ed25519TestKeys::PUBLIC_KEY_BASE64 );
		$apiClient       = new HttpApiClient( 'https://licencas.example.com/wp-json/v3r-license/v1', $this->transport, $verifier, 5 );
		$this->storage   = new LicenseStorage( 'v3rmural', new InMemoryKeyValueStore(), new InMemoryKeyValueStore() );

		return new LicenseManager(
			'v3rmural',
			$apiClient,
			$this->storage,
			'0.0.0-CONSTRUTOR',
			static function () use ( $siteUrl ): string {
				return $siteUrl;
			}
		);
	}

	private function makeResolver( LicenseManager $manager, bool $free = true ): UpdateMetadataResolver {
		$logger = new Logger(
			function ( string $level, string $message ): void {
				$this->logged[] = array( $level, $message );
			}
		);

		return new UpdateMetadataResolver( $manager, new UpdateGate(), $free, $logger );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function updatePayload(): array {
		return array(
			'update_available' => true,
			'version'          => '2.0.0',
			'requires'         => '6.0',
			'requires_php'     => '8.0',
			'package_url'      => 'https://licencas.example.com/download?token=abc',
			'checked_at'       => '2026-11-25T12:00:00+00:00',
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function enqueueSigned( array $payload ): void {
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( TestSigner::sign( $payload ) ) ) );
	}

	/** Critério 1: sem chave, a requisição real não leva license_key e leva os três campos. */
	public function test_without_key_request_omits_license_key_and_sends_slug_site_and_version(): void {
		$manager  = $this->makeManager();
		$resolver = $this->makeResolver( $manager );
		$this->enqueueSigned( $this->updatePayload() );

		$availability = $resolver->resolve( '1.0.0' );

		self::assertTrue( $availability->isAvailable() );
		self::assertSame( '2.0.0', $availability->getVersion() );
		self::assertSame( 1, $this->transport->getCallCount() );

		$call = $this->transport->getCalls()[0];
		self::assertSame( 'GET', $call['method'] );
		self::assertStringContainsString( '/update-check?', $call['url'] );
		parse_str( (string) parse_url( $call['url'], PHP_URL_QUERY ), $query );
		self::assertSame(
			array(
				'plugin_version' => '1.0.0',
				'product_slug'   => 'v3rmural',
				'site_url'       => 'https://cliente.example.com',
			),
			$this->sorted( $query )
		);
		self::assertStringNotContainsString( 'license_key', $call['url'] );
	}

	/**
	 * @param array<int|string, mixed> $a
	 * @return array<int|string, mixed>
	 */
	private function sorted( array $a ): array {
		ksort( $a );

		return $a;
	}

	/** Critério 1 (ponta a ponta no PUC): a resposta assinada vira update no transiente do WordPress. */
	public function test_signed_response_becomes_an_update_in_the_wordpress_transient(): void {
		$manager  = $this->makeManager();
		$resolver = $this->makeResolver( $manager );
		$this->enqueueSigned( $this->updatePayload() );

		$bridge = new PucBridge( 'https://v3r-core.invalid/v3rmural/update-check', __FILE__, 'v3rmural', $resolver, 'V3RMural' );
		$bridge->checkForUpdates();

		$transient            = new \stdClass();
		$transient->response  = array();
		$transient->no_update = array();
		$transient->checked   = array();
		$result               = $bridge->injectUpdate( $transient );

		$plugin = array_key_first( (array) $result->response );
		self::assertNotNull( $plugin, 'Nenhuma atualização chegou ao transiente.' );
		self::assertSame( '2.0.0', $result->response[ $plugin ]->new_version );

		$call = $this->transport->getCalls()[0];
		self::assertStringNotContainsString( 'license_key', $call['url'] );
		self::assertStringContainsString( 'site_url=', $call['url'] );
	}

	/** Critério 2: nenhuma chamada a /activate nem /validate; só /update-check. */
	public function test_free_mode_only_ever_calls_update_check(): void {
		$manager  = $this->makeManager();
		$resolver = $this->makeResolver( $manager );
		$this->enqueueSigned( $this->updatePayload() );
		$this->enqueueSigned(
			array(
				'update_available' => false,
				'checked_at' => '2026-11-25T12:00:00+00:00',
			)
		);

		$resolver->resolve( '1.0.0' );
		$resolver->resolve( '2.0.0' );
		$manager->refresh( true ); // INACTIVE: nunca vai à rede.

		self::assertSame( 2, $this->transport->getCallCount() );
		foreach ( $this->transport->getCalls() as $call ) {
			self::assertStringContainsString( '/update-check?', $call['url'] );
			self::assertStringNotContainsString( '/activate', $call['url'] );
			self::assertStringNotContainsString( '/validate', $call['url'] );
			self::assertSame( 'GET', $call['method'] );
		}
	}

	/** Critério 2: Bootstrap gratuito dispensa decider, sem tela de licença. */
	public function test_bootstrap_free_mode_boots_without_decider_and_refuses_license_screen(): void {
		$bootstrap = ( new Bootstrap( 'v3rmural', __FILE__, 'https://licencas.example.com/wp-json/v3r-license/v1', 'chave', '1.0.0' ) )
			->withFreeProduct( static function (): void {} );

		self::assertTrue( $bootstrap->isFreeProduct() );

		$bootstrap->boot(); // sem withCapabilityDecider(): não lança.

		$this->expectException( \LogicException::class );
		$bootstrap->createAdminPage();
	}

	/** Controle negativo do critério 3: sem o modo, boot() continua exigindo o decider e a tela existe. */
	public function test_bootstrap_default_mode_is_unchanged(): void {
		$bootstrap = new Bootstrap( 'v3rlgpd', __FILE__, 'https://licencas.example.com/wp-json/v3r-license/v1', 'chave', '1.0.0', 'v3rlgpd_license' );

		self::assertFalse( $bootstrap->isFreeProduct() );
		self::assertNotNull( $bootstrap->createAdminPage() );

		$this->expectException( \LogicException::class );
		$bootstrap->boot();
	}

	/** Critério 3: sem o modo (padrão), INACTIVE não toca a rede — mesmo com o dublê pronto para responder. */
	public function test_default_mode_inactive_never_reaches_the_network(): void {
		$manager  = $this->makeManager();
		$resolver = $this->makeResolver( $manager, false );
		$this->enqueueSigned( $this->updatePayload() );

		$availability = $resolver->resolve( '1.0.0' );

		self::assertFalse( $availability->isAvailable() );
		self::assertSame( 0, $this->transport->getCallCount() );

		// Construtor sem os argumentos novos (forma antiga) = mesmo padrão.
		$legacy = new UpdateMetadataResolver( $manager, new UpdateGate() );
		self::assertFalse( $legacy->resolve( '1.0.0' )->isAvailable() );
		self::assertSame( 0, $this->transport->getCallCount() );
	}

	/** Critério 4: resposta sem assinatura, com assinatura inválida ou adulterada nunca vira atualização. */
	public function test_unsigned_or_badly_signed_responses_never_become_an_update(): void {
		$manager  = $this->makeManager();
		$resolver = $this->makeResolver( $manager );

		// Sem assinatura.
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( array( 'payload' => $this->updatePayload() ) ) ) );
		self::assertFalse( $resolver->resolve( '1.0.0' )->isAvailable() );

		// Assinatura lixo.
		$this->transport->enqueue(
			HttpTransportResult::success(
				200,
				(string) json_encode(
					array(
						'payload' => $this->updatePayload(),
						'signature' => base64_encode( str_repeat( 'x', 64 ) ),
					)
				)
			)
		);
		self::assertFalse( $resolver->resolve( '1.0.0' )->isAvailable() );

		// Payload adulterado depois de assinado.
		$envelope                           = TestSigner::sign( $this->updatePayload() );
		$envelope['payload']['package_url'] = 'https://atacante.example.com/malware.zip';
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );
		self::assertFalse( $resolver->resolve( '1.0.0' )->isAvailable() );

		self::assertSame( 3, $this->transport->getCallCount() );

		// Controle: a mesma resposta, bem assinada, É aceita.
		$this->enqueueSigned( $this->updatePayload() );
		self::assertTrue( $resolver->resolve( '1.0.0' )->isAvailable() );
	}

	/** Produto pago sem chave: o servidor responde 404 invalid_key, que equivale a "sem novidade". */
	public function test_paid_product_rejection_means_no_update(): void {
		$manager  = $this->makeManager();
		$resolver = $this->makeResolver( $manager );
		$this->transport->enqueue(
			HttpTransportResult::success(
				404,
				(string) json_encode(
					array(
						'code' => 'invalid_key',
						'message' => 'Chave inválida.',
						'data' => array( 'status' => 404 ),
					)
				)
			)
		);

		self::assertFalse( $resolver->resolve( '1.0.0' )->isAvailable() );
		self::assertSame( 1, $this->transport->getCallCount() );
	}

	/** Critério 5: site_url vazio não chama a rede e registra o motivo. */
	public function test_empty_site_url_skips_network_and_logs_the_reason(): void {
		$manager  = $this->makeManager( '' );
		$resolver = $this->makeResolver( $manager );
		$this->enqueueSigned( $this->updatePayload() );

		$availability = $resolver->resolve( '1.0.0' );

		self::assertFalse( $availability->isAvailable() );
		self::assertSame( 0, $this->transport->getCallCount() );
		self::assertCount( 1, $this->logged );
		self::assertSame( 'warning', $this->logged[0][0] );
		self::assertStringContainsString( 'site_url vazio', $this->logged[0][1] );
	}

	/**
	 * Critério 6 (decisão: o modo gratuito IGNORA a chave salva): com chave
	 * ativa, expirada ou revogada gravada, a consulta sai igual — sem
	 * license_key, sem a chave em lugar nenhum — e o estado não bloqueia.
	 */
	public function test_saved_key_is_ignored_in_free_mode(): void {
		foreach ( array( LicenseStatus::ACTIVE, LicenseStatus::REVOKED, LicenseStatus::EXPIRED ) as $status ) {
			$manager  = $this->makeManager();
			$resolver = $this->makeResolver( $manager );
			$this->storage->save( new LicenseState( 'V3RL-SEGREDO-1234', $status, null, 1, 5, new DateTimeImmutable( '2020-01-01' ), null, 'v3rmural' ) );
			$this->enqueueSigned( $this->updatePayload() );

			$availability = $resolver->resolve( '1.0.0' );

			self::assertTrue( $availability->isAvailable(), "Com chave em estado {$status} o modo gratuito deveria atualizar." );
			$url = $this->transport->getCalls()[0]['url'];
			self::assertStringNotContainsString( 'license_key', $url );
			self::assertStringNotContainsString( 'SEGREDO', $url );
			// A chave salva continua intacta (o modo não a apaga nem altera).
			self::assertSame( 'V3RL-SEGREDO-1234', $this->storage->load( 'v3rmural' )->getKey() );
		}
	}

	/** Rollback explícito também funciona e continua distinguindo plugin_version de version. */
	public function test_explicit_requested_version_is_sent_as_version(): void {
		$manager = $this->makeManager();
		$this->enqueueSigned(
			array(
				'update_available' => false,
				'checked_at' => '2026-11-25T12:00:00+00:00',
			)
		);

		$manager->checkForFreeProductUpdate( '2.5.0', '2.1.0' );

		$url = $this->transport->getCalls()[0]['url'];
		self::assertStringContainsString( 'plugin_version=2.5.0', $url );
		self::assertMatchesRegularExpression( '/(?<!plugin_)version=2\.1\.0/', $url );
	}
}
