<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Notification\Support;

use V3R\Core\Notification\SendLogInterface;
use V3R\Core\Notification\SendRecord;

final class InMemorySendLog implements SendLogInterface {

	/** @var array<string, SendRecord> */
	private array $records = array();

	public function wasDispatched( string $dispatchId ): bool {
		return isset( $this->records[ $dispatchId ] ) && $this->records[ $dispatchId ]->wasDelivered();
	}

	public function record( SendRecord $record ): void {
		$this->records[ $record->getDispatchId() ] = $record;
	}

	/**
	 * @return array<string, SendRecord>
	 */
	public function all(): array {
		return $this->records;
	}

	public function get( string $dispatchId ): ?SendRecord {
		return $this->records[ $dispatchId ] ?? null;
	}
}
