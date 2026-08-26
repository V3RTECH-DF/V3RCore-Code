<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Licensing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use V3R\Core\Licensing\LicenseState;
use V3R\Core\Licensing\LicenseStatePresenter;
use V3R\Core\Licensing\LicenseStatus;
use V3R\Core\Updater\UpdateGate;

/**
 * Cobre o schema de docs/api-contract.md §8.3 e o mapeamento de
 * `status_message` de §8.4 — inclusive a regra de §8.11 (nunca "bloqueado"/
 * "desativado"/"suspenso" referindo-se ao plugin).
 */
final class LicenseStatePresenterTest extends TestCase {

	private LicenseStatePresenter $presenter;

	protected function setUp(): void {
		$this->presenter = new LicenseStatePresenter( new UpdateGate() );
	}

	public function test_active_within_validity_without_grace(): void {
		$now   = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$state = new LicenseState( 'V3RL-AAAA-BBBB-2D5C', LicenseStatus::ACTIVE, $now->modify( '+1 year' ), 2, 5, $now, null, 'v3rlgpd' );

		$result = $this->presenter->present( $state, $now );

		self::assertSame( 'V3RL-XXXX-...-2D5C', $result['license_key_masked'] );
		self::assertSame( LicenseStatus::ACTIVE, $result['status'] );
		self::assertTrue( $result['receives_updates'] );
		self::assertFalse( $result['in_grace_period'] );
		self::assertSame( 'Licença ativa. Você recebe atualizações normalmente.', $result['status_message'] );
	}

	public function test_active_in_grace_period_still_receives_updates(): void {
		$now        = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$graceUntil = $now->modify( '+10 days' );
		$state      = ( new LicenseState( 'V3RL-AAAA', LicenseStatus::ACTIVE, null, 1, 5, $now->modify( '-4 days' ), $graceUntil, 'v3rlgpd' ) );

		$result = $this->presenter->present( $state, $now );

		self::assertTrue( $result['in_grace_period'] );
		self::assertTrue( $result['receives_updates'] );
		self::assertStringContainsString( 'tolerância', $result['status_message'] );
	}

	public function test_active_with_grace_expired_stops_receiving_updates(): void {
		$now   = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$state = new LicenseState( 'V3RL-AAAA', LicenseStatus::ACTIVE, null, 1, 5, $now->modify( '-20 days' ), $now->modify( '-6 days' ), 'v3rlgpd' );

		$result = $this->presenter->present( $state, $now );

		self::assertFalse( $result['receives_updates'] );
		self::assertStringContainsString( 'pausadas', $result['status_message'] );
	}

	public function test_expired_never_says_blocked_or_disabled(): void {
		$state  = new LicenseState( 'V3RL-AAAA', LicenseStatus::EXPIRED, null, 1, 5, new DateTimeImmutable(), null, 'v3rlgpd' );
		$result = $this->presenter->present( $state );

		self::assertFalse( $result['receives_updates'] );
		self::assertStringNotContainsStringIgnoringCase( 'bloque', $result['status_message'] );
		self::assertStringNotContainsStringIgnoringCase( 'desativ', $result['status_message'] );
		self::assertStringNotContainsStringIgnoringCase( 'suspens', $result['status_message'] );
	}

	public function test_revoked_never_says_blocked_or_disabled(): void {
		$state  = new LicenseState( 'V3RL-AAAA', LicenseStatus::REVOKED, null, 1, 5, new DateTimeImmutable(), null, 'v3rlgpd' );
		$result = $this->presenter->present( $state );

		self::assertFalse( $result['receives_updates'] );
		self::assertStringNotContainsStringIgnoringCase( 'bloque', $result['status_message'] );
		self::assertStringNotContainsStringIgnoringCase( 'desativ', $result['status_message'] );
		self::assertStringNotContainsStringIgnoringCase( 'suspens', $result['status_message'] );
	}

	public function test_inactive_reports_empty_masked_key(): void {
		$state  = LicenseState::neutral( 'v3rlgpd' );
		$result = $this->presenter->present( $state );

		self::assertSame( '', $result['license_key_masked'] );
		self::assertSame( LicenseStatus::INACTIVE, $result['status'] );
		self::assertFalse( $result['receives_updates'] );
		self::assertSame( 'Nenhuma licença ativada neste site.', $result['status_message'] );
	}

	public function test_invalid_reports_verification_message(): void {
		$state  = new LicenseState( 'V3RL-AAAA', LicenseStatus::INVALID, null, 0, null, null, null, 'v3rlgpd' );
		$result = $this->presenter->present( $state );

		self::assertStringContainsString( 'Verifique a chave', $result['status_message'] );
	}
}
