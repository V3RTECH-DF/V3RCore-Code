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
use V3R\Core\Licensing\Transport\HttpTransportResult;
use V3R\Core\Tests\Fixtures\Ed25519TestKeys;
use V3R\Core\Tests\Fixtures\TestSigner;
use V3R\Core\Tests\Licensing\Storage\InMemoryKeyValueStore;
use V3R\Core\Tests\Licensing\Transport\FakeHttpTransport;
use V3R\Core\Updater\PucBridge;
use V3R\Core\Updater\UpdateGate;
use V3R\Core\Updater\UpdateMetadataResolver;

/**
 * V3RCore-Code#8 e #10 — mapeamento de campos entre o payload de
 * `GET /update-check` e o transiente `update_plugins` do WordPress, via o
 * Plugin Update Checker de verdade (yahnis-elsts/plugin-update-checker,
 * dependência real, não a cópia prefixada de vendor-prefixed/).
 *
 * Diferente de UpdateCheckerTest (que mantém register() um no-op sem
 * WordPress), estes testes precisam do PUC de verdade construído e
 * exercitado ponta a ponta — os stubs mínimos de WP estão em
 * tests/Support/PucFunctionStubs.php. É a única forma de reproduzir o bug
 * real: ele mora na conversão interna do PUC (PluginInfo -> Update ->
 * toWpFormat()), não em nada que este pacote decida sozinho.
 */
final class PucBridgeTest extends TestCase {

	/**
	 * @return array{0: PucBridge, 1: FakeHttpTransport}
	 */
	private function makeBridgeWithAvailableUpdate( ?string $changelogUrl = 'https://manual.example.com/v3rlgpd/novidades' ): array {
		$transport = new FakeHttpTransport();
		$verifier  = new SignatureVerifier( Ed25519TestKeys::PUBLIC_KEY_BASE64 );
		$apiClient = new HttpApiClient( 'https://licencas.example.com/wp-json/v3r-license/v1', $transport, $verifier, 5 );
		$storage   = new LicenseStorage( 'v3rlgpd', new InMemoryKeyValueStore(), new InMemoryKeyValueStore() );
		$storage->save( new LicenseState( 'V3RL-AAAA', LicenseStatus::ACTIVE, null, 0, null, new DateTimeImmutable( '2020-01-01' ), null, 'v3rlgpd' ) );

		$manager  = new LicenseManager( 'v3rlgpd', $apiClient, $storage, '1.0.0' );
		$resolver = new UpdateMetadataResolver( $manager, new UpdateGate() );

		$payload = array(
			'update_available' => true,
			'version'          => '2.0.0',
			'requires'         => '5.8',
			'requires_php'     => '7.4',
			'tested'           => '6.7',
			'package_url'      => 'https://licencas.example.com/download/xyz',
			'checked_at'       => '2026-08-26T12:00:00+00:00',
		);
		if ( null !== $changelogUrl ) {
			$payload['changelog_url'] = $changelogUrl;
		}

		$envelope = TestSigner::sign( $payload );
		$transport->enqueue( HttpTransportResult::success( 200, (string) json_encode( $envelope ) ) );

		$bridge = new PucBridge(
			'https://v3r-core.invalid/v3rlgpd/update-check',
			__FILE__,
			'v3rlgpd',
			$resolver,
			'V3RLGPD'
		);

		return array( $bridge, $transport );
	}

	/**
	 * Roda o ciclo completo que o WordPress executa de verdade: popula o
	 * cache (checkForUpdates(), que chama requestUpdate() por baixo) e
	 * depois lê o transiente através do mesmo filtro que o WP dispara
	 * (injectUpdate(), que aplica pre_inject_update e só então converte
	 * com toWpFormat()) — é só nesse caminho que o bug de #8 aparece.
	 */
	private function injectIntoTransient( PucBridge $bridge ): \stdClass {
		$bridge->checkForUpdates();

		$transient            = new \stdClass();
		$transient->response  = array();
		$transient->no_update = array();
		$transient->checked   = array();

		return $bridge->injectUpdate( $transient );
	}

	/**
	 * BUG CORRIGIDO (V3RCore-Code#8): `requires` chega certo em
	 * PluginInfo (PucBridge::requestInfo()), mas o PUC upstream perde o
	 * campo ao converter para Update — `Plugin\Update::getFieldNames()`
	 * não o lista nem na lista base nem em $extraFields, e
	 * `toWpFormat()` só copia os campos que conhece pelo nome.
	 */
	public function test_requires_field_survives_into_the_update_plugins_transient(): void {
		list( $bridge ) = $this->makeBridgeWithAvailableUpdate();

		$result = $this->injectIntoTransient( $bridge );

		$plugin = array_key_first( (array) $result->response );
		self::assertNotNull( $plugin, 'Nenhuma entrada de update foi injetada no transiente.' );

		$wpUpdate = $result->response[ $plugin ];

		self::assertSame( '5.8', $wpUpdate->requires );
		// Os campos que já funcionavam continuam funcionando.
		self::assertSame( '2.0.0', $wpUpdate->new_version );
		self::assertSame( '7.4', $wpUpdate->requires_php );
	}

	/**
	 * BUG CORRIGIDO (V3RCore-Code#10): PucBridge não preenchia
	 * `PluginInfo->homepage`, e é dele que `Plugin\Update::toWpFormat()`
	 * tira o `url` do transiente — o link "Ver detalhes da versão" da
	 * lista de plugins ficava sem destino.
	 */
	public function test_url_is_filled_from_the_changelog_url_sent_by_the_server(): void {
		list( $bridge ) = $this->makeBridgeWithAvailableUpdate( 'https://manual.example.com/v3rlgpd/novidades' );

		$result = $this->injectIntoTransient( $bridge );

		$plugin   = array_key_first( (array) $result->response );
		$wpUpdate = $result->response[ $plugin ];

		self::assertSame( 'https://manual.example.com/v3rlgpd/novidades', $wpUpdate->url );
	}

	/**
	 * Guardrail explícito do pedido: sem changelog_url, `url` deve
	 * continuar ausente/nulo — nunca virar string vazia (link para lugar
	 * nenhum é pior que link ausente).
	 */
	public function test_url_stays_absent_when_server_sends_no_changelog_url(): void {
		list( $bridge ) = $this->makeBridgeWithAvailableUpdate( null );

		$result = $this->injectIntoTransient( $bridge );

		$plugin   = array_key_first( (array) $result->response );
		$wpUpdate = $result->response[ $plugin ];

		self::assertTrue(
			! isset( $wpUpdate->url ) || null === $wpUpdate->url,
			'url deveria ficar ausente/nulo sem changelog_url, nunca string vazia.'
		);
	}
}
