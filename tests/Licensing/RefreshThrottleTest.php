<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Licensing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use V3R\Core\Licensing\RefreshThrottle;
use V3R\Core\Tests\Licensing\Storage\InMemoryKeyValueStore;

/**
 * Cobre docs/api-contract.md §8.8.1 — no máximo um refresh de verdade por
 * minuto, por produto.
 */
final class RefreshThrottleTest extends TestCase {

	public function test_first_attempt_is_never_throttled(): void {
		$throttle = new RefreshThrottle( 'v3rlgpd', new InMemoryKeyValueStore() );

		self::assertNull( $throttle->secondsRemaining( new DateTimeImmutable( '2026-08-25T12:00:00+00:00' ) ) );
	}

	public function test_second_attempt_within_the_window_is_throttled(): void {
		$store    = new InMemoryKeyValueStore();
		$throttle = new RefreshThrottle( 'v3rlgpd', $store );

		$t0 = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$throttle->markAttempt( $t0 );

		$t1 = $t0->modify( '+30 seconds' );

		self::assertSame( 30, $throttle->secondsRemaining( $t1 ) );
	}

	public function test_attempt_after_the_window_is_no_longer_throttled(): void {
		$store    = new InMemoryKeyValueStore();
		$throttle = new RefreshThrottle( 'v3rlgpd', $store );

		$t0 = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$throttle->markAttempt( $t0 );

		$t1 = $t0->modify( '+61 seconds' );

		self::assertNull( $throttle->secondsRemaining( $t1 ) );
	}

	public function test_different_products_are_throttled_independently(): void {
		$store = new InMemoryKeyValueStore();

		$throttleA = new RefreshThrottle( 'v3rlgpd', $store );
		$throttleB = new RefreshThrottle( 'rit360-premiado', $store );

		$now = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$throttleA->markAttempt( $now );

		self::assertNull( $throttleB->secondsRemaining( $now ) );
	}
}
