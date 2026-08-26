<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Licensing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use V3R\Core\Licensing\LicenseState;
use V3R\Core\Licensing\LicenseStatus;
use V3R\Core\Licensing\LicenseStorage;
use V3R\Core\Tests\Licensing\Storage\InMemoryKeyValueStore;

final class LicenseStorageTest extends TestCase {

	private InMemoryKeyValueStore $options;

	private InMemoryKeyValueStore $cache;

	private LicenseStorage $storage;

	protected function setUp(): void {
		$this->options = new InMemoryKeyValueStore();
		$this->cache   = new InMemoryKeyValueStore();
		$this->storage = new LicenseStorage( 'v3rlgpd', $this->options, $this->cache );
	}

	public function test_load_without_prior_save_is_neutral(): void {
		$state = $this->storage->load( 'v3rlgpd' );

		self::assertSame( LicenseStatus::INACTIVE, $state->getStatus() );
	}

	public function test_save_then_load_round_trips_every_field(): void {
		$expiresAt     = new DateTimeImmutable( '2027-08-25T00:00:00+00:00' );
		$lastCheckedAt = new DateTimeImmutable( '2026-08-25T12:00:00+00:00' );
		$graceUntil    = new DateTimeImmutable( '2026-09-08T12:00:00+00:00' );

		$state = new LicenseState(
			'V3RL-FULL-KEY-2D5C',
			LicenseStatus::ACTIVE,
			$expiresAt,
			2,
			5,
			$lastCheckedAt,
			$graceUntil,
			'v3rlgpd'
		);

		$this->storage->save( $state );
		$loaded = $this->storage->load( 'v3rlgpd' );

		self::assertSame( 'V3RL-FULL-KEY-2D5C', $loaded->getKey() );
		self::assertSame( LicenseStatus::ACTIVE, $loaded->getStatus() );
		self::assertEquals( $expiresAt, $loaded->getExpiresAt() );
		self::assertSame( 2, $loaded->getActivationsUsed() );
		self::assertSame( 5, $loaded->getActivationsMax() );
		self::assertEquals( $lastCheckedAt, $loaded->getLastCheckedAt() );
		self::assertEquals( $graceUntil, $loaded->getGraceUntil() );
		self::assertSame( 'v3rlgpd', $loaded->getProductSlug() );
	}

	public function test_save_with_unlimited_activations_round_trips_null(): void {
		$state = new LicenseState( 'V3RL-KEY', LicenseStatus::ACTIVE, null, 1, null, null, null, 'v3rlgpd' );

		$this->storage->save( $state );
		$loaded = $this->storage->load( 'v3rlgpd' );

		self::assertNull( $loaded->getActivationsMax() );
		self::assertNull( $loaded->getExpiresAt() );
	}

	public function test_clear_removes_state_and_validation_cache(): void {
		$state = new LicenseState( 'V3RL-KEY', LicenseStatus::ACTIVE, null, 1, null, null, null, 'v3rlgpd' );
		$this->storage->save( $state );
		$this->storage->markValidationCacheFresh();

		self::assertTrue( $this->storage->hasFreshValidationCache() );

		$this->storage->clear();

		self::assertSame( LicenseStatus::INACTIVE, $this->storage->load( 'v3rlgpd' )->getStatus() );
		self::assertFalse( $this->storage->hasFreshValidationCache() );
	}

	public function test_validation_cache_starts_stale(): void {
		self::assertFalse( $this->storage->hasFreshValidationCache() );
	}

	public function test_validation_cache_is_fresh_right_after_marking(): void {
		$this->storage->markValidationCacheFresh();

		self::assertTrue( $this->storage->hasFreshValidationCache() );
	}

	public function test_validation_cache_expires_after_ttl(): void {
		$now     = 1000;
		$clock   = static function () use ( &$now ): int {
			return $now;
		};
		$cache   = new InMemoryKeyValueStore( $clock );
		$storage = new LicenseStorage( 'v3rlgpd', new InMemoryKeyValueStore(), $cache );

		$storage->markValidationCacheFresh();
		self::assertTrue( $storage->hasFreshValidationCache() );

		$now += LicenseStorage::CACHE_TTL_SECONDS + 1;

		self::assertFalse( $storage->hasFreshValidationCache() );
	}

	public function test_two_products_never_share_option_or_transient_names(): void {
		$storageA = new LicenseStorage( 'v3rlgpd' );
		$storageB = new LicenseStorage( 'rit360-premiado' );

		self::assertNotSame( $storageA->getOptionName(), $storageB->getOptionName() );
		self::assertNotSame( $storageA->getTransientName(), $storageB->getTransientName() );
	}

	public function test_corrupted_stored_data_falls_back_to_neutral_instead_of_throwing(): void {
		$this->options->set( $this->storage->getOptionName(), 'isto não é um array' );

		$state = $this->storage->load( 'v3rlgpd' );

		self::assertSame( LicenseStatus::INACTIVE, $state->getStatus() );
	}
}
