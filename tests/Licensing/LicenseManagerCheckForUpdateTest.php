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
 *
 * BUG CORRIGIDO, validado ao vivo num WordPress real: a assinatura antiga
 * (`checkForUpdate(?string $version = null)`, com `plugin_version` fixo do
 * construtor) permitia — e o resolver chegou a fazer isso — mandar a
 * própria versão instalada também como pedido de rollback (`version`), o
 * que faz o servidor responder update_available=false mesmo com release
 * mais nova publicada. Os testes abaixo verificam o que é ENVIADO, não só
 * o que a resposta programada devolve — é essa checagem que faltava.
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
		// pluginVersion do construtor propositalmente "velha"/divergente:
		// nenhum teste abaixo pode ver este valor aparecer em plugin_version
		// — só o $installedVersion passado explicitamente a checkForUpdate()
		// pode aparecer lá (é exatamente o bug que foi corrigido).
		$this->manager = new LicenseManager( 'v3rlgpd', $apiClient, $this->storage, '0.0.0-CONSTRUTOR-NUNCA-USADO-AQUI' );
	}

	private function activate(): void {
		$this->storage->save( new LicenseState( 'V3RL-AAAA', LicenseStatus::ACTIVE, null, 0, null, new DateTimeImmutable( '2020-01-01' ), null, 'v3rlgpd' ) );
	}

	private function enqueueNoUpdateResponse(): void {
		$envelope = TestSigner::sign(
			array(
				'update_available' => false,
				'checked_at'       => '2026-08-25T12:00:00+00:00',
			)
		);
		$this->transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );
	}

	public function test_inactive_site_never_contacts_server(): void {
		$result = $this->manager->checkForUpdate( '1.0.0' );

		self::assertNull( $result );
		self::assertSame( 0, $this->transport->getCallCount() );
	}

	public function test_routine_check_sends_installed_version_as_plugin_version(): void {
		$this->activate();
		$this->enqueueNoUpdateResponse();

		$this->manager->checkForUpdate( '2.5.0' );

		$call = $this->transport->getCalls()[0];
		self::assertStringContainsString( 'product_slug=v3rlgpd', $call['url'] );
		self::assertStringContainsString( 'license_key=V3RL-AAAA', $call['url'] );
		self::assertStringContainsString( 'plugin_version=2.5.0', $call['url'] );
		// Nunca a versão do construtor (obsoleta/divergente de propósito).
		self::assertStringNotContainsString( 'CONSTRUTOR', $call['url'] );
	}

	/**
	 * O teste que faltava (achado da validação ao vivo): a checagem de
	 * rotina — sem pedido de rollback — NUNCA pode mandar o campo `version`,
	 * nem mesmo com o valor da versão instalada. `version` é só para
	 * rollback explícito (§2.4).
	 */
	public function test_routine_check_never_sends_the_rollback_version_field(): void {
		$this->activate();
		$this->enqueueNoUpdateResponse();

		$this->manager->checkForUpdate( '2.5.0' );

		$call = $this->transport->getCalls()[0];
		self::assertStringNotContainsString( 'version=2.5.0', str_replace( 'plugin_version=2.5.0', '', $call['url'] ) );
		self::assertDoesNotMatchRegularExpression( '/(?<!plugin_)version=/', $call['url'] );
	}

	public function test_explicit_rollback_sends_both_installed_and_requested_version_distinctly(): void {
		$this->activate();
		$this->enqueueNoUpdateResponse();

		$this->manager->checkForUpdate( '2.5.0', '2.1.0' );

		$call = $this->transport->getCalls()[0];
		self::assertStringContainsString( 'plugin_version=2.5.0', $call['url'] );
		self::assertMatchesRegularExpression( '/(?<!plugin_)version=2\.1\.0/', $call['url'] );
	}

	public function test_business_error_from_server_is_repassed(): void {
		$this->activate();

		$body = array(
			'code'    => 'license_expired',
			'message' => 'Licença expirada.',
			'data'    => array( 'status' => 403 ),
		);
		$this->transport->enqueue( HttpTransportResult::success( 403, (string) json_encode( $body ) ) );

		$this->expectException( ApiException::class );

		$this->manager->checkForUpdate( '2.5.0' );
	}
}
