<?php
declare(strict_types=1);

namespace V3R\Core\Tests;

use PHPUnit\Framework\TestCase;
use V3R\Core\Bootstrap;
use V3R\Core\Licensing\LicenseStatus;

/**
 * Critério de aceite: Bootstrap instanciável sem rede e sem estado salvo,
 * sem exceção — mesmo fora do WordPress de verdade (ver stubs de
 * get_option/get_transient em tests/bootstrap.php).
 */
final class BootstrapTest extends TestCase {

	public function test_instantiates_and_boots_without_network_or_saved_state(): void {
		$bootstrap = new Bootstrap(
			'v3rlgpd',
			__FILE__,
			'https://licencas.example.com/wp-json/v3r-license/v1',
			'chave-publica-fake-base64',
			'1.0.0'
		);

		$bootstrap->withCapabilityDecider(
			static function ( int $userId, string $capability ): bool {
				return true;
			}
		);
		$bootstrap->boot();

		self::assertSame( 'v3rlgpd', $bootstrap->getProductSlug() );
		self::assertSame( __FILE__, $bootstrap->getPluginFile() );

		$state = $bootstrap->getLicenseManager()->getState();
		self::assertSame( LicenseStatus::INACTIVE, $state->getStatus() );
	}

	public function test_default_capability_is_manage_options(): void {
		$bootstrap = new Bootstrap( 'v3rlgpd', __FILE__, 'https://licencas.example.com', 'chave', '1.0.0' );

		self::assertSame( 'manage_options', $bootstrap->getCapability() );
		self::assertSame( 'manage_options', $bootstrap->getReadCapability() );
		self::assertSame( 'manage_options', $bootstrap->getManageCapability() );
	}

	public function test_capability_is_configurable_per_host_plugin(): void {
		$bootstrap = new Bootstrap( 'rit360-premiado', __FILE__, 'https://licencas.example.com', 'chave', '2.0.0', 'manage_rit360_premiado' );

		self::assertSame( 'manage_rit360_premiado', $bootstrap->getCapability() );
	}

	/**
	 * Critério de aceite da issue #9: quem passa uma capability só continua
	 * se comportando exatamente como antes — leitura e gestão caem na
	 * mesma capability, sem precisar do sétimo argumento.
	 */
	public function test_single_capability_falls_back_to_same_value_for_read_and_manage(): void {
		$bootstrap = new Bootstrap( 'rit360-premiado', __FILE__, 'https://licencas.example.com', 'chave', '2.0.0', 'manage_rit360_premiado' );

		self::assertSame( 'manage_rit360_premiado', $bootstrap->getReadCapability() );
		self::assertSame( 'manage_rit360_premiado', $bootstrap->getManageCapability() );
	}

	/**
	 * Issue #9: as duas capabilities podem ser informadas separadamente —
	 * é o que permite ao hospedeiro dar leitura a quem só consulta e
	 * reservar ativar/desativar a quem administra o plugin.
	 */
	public function test_read_and_manage_capabilities_can_be_configured_separately(): void {
		$bootstrap = new Bootstrap(
			'v3rlgpd',
			__FILE__,
			'https://licencas.example.com',
			'chave',
			'1.0.0',
			'v3rlgpd_settings_view',
			'v3rlgpd_settings_manage'
		);

		self::assertSame( 'v3rlgpd_settings_view', $bootstrap->getReadCapability() );
		self::assertSame( 'v3rlgpd_settings_manage', $bootstrap->getManageCapability() );
		self::assertSame( 'v3rlgpd_settings_manage', $bootstrap->getCapability() );
	}

	/**
	 * V3RCore-Code#12, item 4: sem função de decisão, o comportamento
	 * precisa ser explícito e diagnosticável — nunca um site que
	 * silenciosamente não concede a capability. `boot()` recusa e diz o
	 * que falta.
	 */
	public function test_boot_without_capability_decider_throws_explicit_exception(): void {
		$bootstrap = new Bootstrap( 'v3rlgpd', __FILE__, 'https://licencas.example.com', 'chave', '1.0.0' );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'withCapabilityDecider' );

		$bootstrap->boot();
	}

	/** WithCapabilityDecider() devolve $this — chamada fluente com boot(). */
	public function test_with_capability_decider_is_fluent(): void {
		$bootstrap = new Bootstrap( 'v3rlgpd', __FILE__, 'https://licencas.example.com', 'chave', '1.0.0' );

		$returned = $bootstrap->withCapabilityDecider(
			static function ( int $userId, string $capability ): bool {
				return false;
			}
		);

		self::assertSame( $bootstrap, $returned );

		// Não lança mais, agora que a função de decisão foi configurada.
		$returned->boot();
		$this->addToAssertionCount( 1 );
	}
}
