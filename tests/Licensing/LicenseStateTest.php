<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Licensing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use V3R\Core\Licensing\LicenseState;
use V3R\Core\Licensing\LicenseStatus;

final class LicenseStateTest extends TestCase {

	public function test_neutral_state_is_inactive_and_never_valid(): void {
		$state = LicenseState::neutral( 'v3rlgpd' );

		self::assertSame( LicenseStatus::INACTIVE, $state->getStatus() );
		self::assertFalse( $state->isValid() );
		self::assertFalse( $state->canReceiveUpdates() );
		self::assertNull( $state->daysUntilExpiry() );
	}

	public function test_active_state_without_expiry_is_valid(): void {
		$state = $this->makeState( LicenseStatus::ACTIVE, null );

		self::assertTrue( $state->isValid() );
		self::assertTrue( $state->canReceiveUpdates() );
		self::assertNull( $state->daysUntilExpiry() );
	}

	public function test_active_state_before_expiry_is_valid(): void {
		$now       = new DateTimeImmutable( '2026-08-25' );
		$expiresAt = $now->modify( '+10 days' );

		$state = $this->makeState( LicenseStatus::ACTIVE, $expiresAt );

		self::assertTrue( $state->isValid( $now ) );
		self::assertSame( 10, $state->daysUntilExpiry( $now ) );
	}

	public function test_active_state_after_expiry_is_not_valid(): void {
		$now       = new DateTimeImmutable( '2026-08-25' );
		$expiresAt = $now->modify( '-1 day' );

		$state = $this->makeState( LicenseStatus::ACTIVE, $expiresAt );

		self::assertFalse( $state->isValid( $now ) );
		self::assertFalse( $state->canReceiveUpdates( $now ) );
		self::assertSame( -1, $state->daysUntilExpiry( $now ) );
	}

	/**
	 * @dataProvider nonActiveStatusesProvider
	 */
	public function test_non_active_statuses_are_never_valid( string $status ): void {
		$state = $this->makeState( $status, null );

		self::assertFalse( $state->isValid() );
		self::assertFalse( $state->canReceiveUpdates() );
	}

	/**
	 * @return array<int, array{string}>
	 */
	public function nonActiveStatusesProvider(): array {
		return array(
			array( LicenseStatus::EXPIRED ),
			array( LicenseStatus::REVOKED ),
			array( LicenseStatus::INVALID ),
			array( LicenseStatus::INACTIVE ),
		);
	}

	public function test_unknown_status_string_falls_back_to_invalid(): void {
		$state = $this->makeState( 'algo-desconhecido', null );

		self::assertSame( LicenseStatus::INVALID, $state->getStatus() );
	}

	public function test_grace_period_boundary_day_14_is_still_in_grace(): void {
		$lastChecked = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$graceUntil  = $lastChecked->modify( '+14 days' );
		$day14       = $graceUntil; // Exatamente no limite.

		$state = $this->makeState( LicenseStatus::ACTIVE, null, $lastChecked, $graceUntil );

		self::assertTrue( $state->isInGracePeriod( $day14 ) );
	}

	public function test_grace_period_boundary_day_15_is_out_of_grace(): void {
		$lastChecked = new DateTimeImmutable( '2026-08-01T00:00:00+00:00' );
		$graceUntil  = $lastChecked->modify( '+14 days' );
		$day15       = $graceUntil->modify( '+1 day' );

		$state = $this->makeState( LicenseStatus::ACTIVE, null, $lastChecked, $graceUntil );

		self::assertFalse( $state->isInGracePeriod( $day15 ) );
	}

	public function test_no_grace_until_means_not_in_grace_period(): void {
		$state = $this->makeState( LicenseStatus::ACTIVE, null );

		self::assertFalse( $state->isInGracePeriod() );
	}

	public function test_masked_key_never_exposes_raw_key(): void {
		$state = new LicenseState(
			'V3R-ABCD-EFGH-4F2A',
			LicenseStatus::ACTIVE,
			null,
			1,
			5,
			null,
			null,
			'v3rlgpd'
		);

		self::assertSame( 'V3R-XXXX-...-4F2A', $state->getMaskedKey() );
		self::assertSame( 'V3R-XXXX-...-4F2A', $state->toArray()['key'] );
		$encoded = json_encode( $state );
		self::assertIsString( $encoded );
		self::assertStringNotContainsString( 'ABCD-EFGH', $encoded );
	}

	public function test_with_status_returns_new_immutable_instance(): void {
		$original = $this->makeState( LicenseStatus::ACTIVE, null );
		$expired  = $original->withStatus( LicenseStatus::EXPIRED );

		self::assertSame( LicenseStatus::ACTIVE, $original->getStatus() );
		self::assertSame( LicenseStatus::EXPIRED, $expired->getStatus() );
		self::assertNotSame( $original, $expired );
	}

	private function makeState(
		string $status,
		?DateTimeImmutable $expiresAt,
		?DateTimeImmutable $lastCheckedAt = null,
		?DateTimeImmutable $graceUntil = null
	): LicenseState {
		return new LicenseState(
			'V3R-TEST-KEY-0000',
			$status,
			$expiresAt,
			0,
			null,
			$lastCheckedAt,
			$graceUntil,
			'v3rlgpd'
		);
	}
}
