<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Updater;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use V3R\Core\Licensing\LicenseState;
use V3R\Core\Licensing\LicenseStatus;
use V3R\Core\Updater\UpdateGate;

final class UpdateGateTest extends TestCase {

	private UpdateGate $gate;

	protected function setUp(): void {
		$this->gate = new UpdateGate();
	}

	public function test_active_and_not_expired_receives_update(): void {
		$state = $this->makeState( LicenseStatus::ACTIVE );

		self::assertTrue( $this->gate->canUpdate( $state ) );
	}

	public function test_expired_never_receives_update_but_rule_is_about_updates_only(): void {
		$state = $this->makeState( LicenseStatus::EXPIRED );

		self::assertFalse( $this->gate->canUpdate( $state ) );
		// A regra central do produto: expirada não bloqueia nada além do
		// update em si — este gate não tem (nem deveria ter) um método que
		// desative o plugin. A ausência desse método é a prova de desenho.
	}

	public function test_revoked_never_receives_update(): void {
		$state = $this->makeState( LicenseStatus::REVOKED );

		self::assertFalse( $this->gate->canUpdate( $state ) );
	}

	public function test_invalid_never_receives_update(): void {
		$state = $this->makeState( LicenseStatus::INVALID );

		self::assertFalse( $this->gate->canUpdate( $state ) );
	}

	public function test_inactive_never_activated_does_not_receive_update(): void {
		$state = LicenseState::neutral( 'v3rlgpd' );

		self::assertFalse( $this->gate->canUpdate( $state ) );
	}

	public function test_active_past_its_own_expiry_date_does_not_receive_update(): void {
		$now       = new DateTimeImmutable( '2026-08-25' );
		$expiresAt = $now->modify( '-1 day' );

		$state = $this->makeState( LicenseStatus::ACTIVE, $expiresAt );

		self::assertFalse( $this->gate->canUpdate( $state, $now ) );
	}

	public function test_active_with_grace_period_running_receives_update(): void {
		$lastChecked = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$graceUntil  = $lastChecked->modify( '+' . UpdateGate::GRACE_PERIOD_DAYS . ' days' );
		$withinGrace = $graceUntil->modify( '-1 day' );

		$state = $this->makeState( LicenseStatus::ACTIVE, null, $graceUntil );

		self::assertTrue( $this->gate->canUpdate( $state, $withinGrace ) );
	}

	public function test_grace_period_day_14_boundary_still_receives_update(): void {
		$lastChecked = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$graceUntil  = $lastChecked->modify( '+' . UpdateGate::GRACE_PERIOD_DAYS . ' days' );

		$state = $this->makeState( LicenseStatus::ACTIVE, null, $graceUntil );

		self::assertTrue( $this->gate->canUpdate( $state, $graceUntil ) );
	}

	public function test_grace_period_day_15_boundary_stops_receiving_update(): void {
		$lastChecked = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$graceUntil  = $lastChecked->modify( '+' . UpdateGate::GRACE_PERIOD_DAYS . ' days' );
		$dayAfter    = $graceUntil->modify( '+1 day' );

		$state = $this->makeState( LicenseStatus::ACTIVE, null, $graceUntil );

		self::assertFalse( $this->gate->canUpdate( $state, $dayAfter ) );
	}

	public function test_gate_has_no_method_that_could_disable_the_plugin(): void {
		// O UpdateGate não tem, e nunca deve ter, um método "isPluginActive"
		// ou equivalente — a garantia de que o plugin continua funcionando é
		// estrutural (nenhuma classe desta lib desativa o plugin hospedeiro),
		// não uma decisão que este gate tome. Este teste documenta a
		// invariante negativa: canUpdate() é o único método público de
		// decisão do gate.
		$reflection      = new \ReflectionClass( UpdateGate::class );
		$publicMethods   = array_map(
			static function ( \ReflectionMethod $method ): string {
				return $method->getName();
			},
			$reflection->getMethods( \ReflectionMethod::IS_PUBLIC )
		);
		$decisionMethods = array_values( array_diff( $publicMethods, array( '__construct' ) ) );

		self::assertSame( array( 'canUpdate' ), $decisionMethods );
	}

	private function makeState(
		string $status,
		?DateTimeImmutable $expiresAt = null,
		?DateTimeImmutable $graceUntil = null
	): LicenseState {
		return new LicenseState(
			'V3R-TEST-KEY-0000',
			$status,
			$expiresAt,
			0,
			null,
			null,
			$graceUntil,
			'v3rlgpd'
		);
	}
}
