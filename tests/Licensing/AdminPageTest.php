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
 *
 * Também cobre AdminPage::registerMenu() (V3RCore-Code#11 — rótulo do menu
 * por produto), via o stub de add_options_page() em
 * tests/Support/AdminMenuFunctionStubs.php.
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

	/**
	 * V3RCore-Code#11: o rótulo do menu e o título da página nomeiam o
	 * produto — não só "Licença" genérico, indistinguível entre plugins.
	 */
	public function test_registerMenu_uses_product_name_in_label_and_title(): void {
		$adminPage = $this->buildAdminPageWithProductName( 'V3REvent' );

		$adminPage->registerMenu();
		$registered = $this->lastRegisteredOptionsPage();

		self::assertStringContainsString( 'V3REvent', $registered['page_title'] );
		self::assertStringContainsString( 'V3REvent', $registered['menu_title'] );
		self::assertNotSame( 'Licença', $registered['page_title'] );
		self::assertNotSame( 'Licença', $registered['menu_title'] );
	}

	/**
	 * Fallback (V3RCore-Code#11): sem nome informado, o rótulo usa o
	 * productSlug — nunca o texto genérico "Licença" sozinho.
	 */
	public function test_registerMenu_falls_back_to_product_slug_without_name(): void {
		// $this->adminPage (setUp) usa o manager com productSlug 'v3rlgpd'
		// e não recebe $productName.
		$this->adminPage->registerMenu();
		$registered = $this->lastRegisteredOptionsPage();

		self::assertStringContainsString( 'v3rlgpd', $registered['page_title'] );
		self::assertStringContainsString( 'v3rlgpd', $registered['menu_title'] );
		self::assertNotSame( 'Licença', $registered['page_title'] );
		self::assertNotSame( 'Licença', $registered['menu_title'] );
	}

	/**
	 * Controle negativo (V3RCore-Code#11, seção Verificação da issue):
	 * dois produtos distintos produzem rótulos distintos — é o estado que
	 * resolve a colisão visual entre dois plugins da casa no mesmo site.
	 */
	public function test_two_products_produce_distinct_labels(): void {
		$adminPageEvent = $this->buildAdminPageWithProductName( 'V3REvent' );
		$adminPageLgpd  = $this->buildAdminPageWithProductName( 'V3RLGPD' );

		$adminPageEvent->registerMenu();
		$labelEvent = $this->lastRegisteredOptionsPage()['menu_title'];

		$adminPageLgpd->registerMenu();
		$labelLgpd = $this->lastRegisteredOptionsPage()['menu_title'];

		self::assertNotSame( $labelEvent, $labelLgpd );
	}

	/**
	 * Lê o último registro capturado pelo stub de add_options_page()
	 * (tests/Support/AdminMenuFunctionStubs.php) — tipado aqui, e não
	 * inline nos testes, para o PHPStan conhecer o shape do array sem
	 * precisar inferir a partir de $GLOBALS.
	 *
	 * @return array{page_title: string, menu_title: string, capability: string, menu_slug: string}
	 */
	private function lastRegisteredOptionsPage(): array {
		$pages = $GLOBALS['v3r_core_test_registered_options_pages'] ?? array();

		return $pages[ count( $pages ) - 1 ];
	}

	private function buildAdminPageWithProductName( string $productName ): AdminPage {
		$transport = new FakeHttpTransport();
		$verifier  = new SignatureVerifier( Ed25519TestKeys::PUBLIC_KEY_BASE64 );
		$apiClient = new HttpApiClient( 'https://licencas.example.com/wp-json/v3r-license/v1', $transport, $verifier, 5 );
		$storage   = new LicenseStorage( 'v3rlgpd', new InMemoryKeyValueStore(), new InMemoryKeyValueStore() );
		$manager   = new LicenseManager( 'v3rlgpd', $apiClient, $storage, '1.0.0' );

		return new AdminPage( $manager, new UpdateGate(), 'manage_options', $productName );
	}
}
