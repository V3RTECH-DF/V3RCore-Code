<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

use DateTimeImmutable;

/**
 * Resultado imutável de um pedido de envio. $failureReason é preenchido só
 * em FAILED — é o que torna a falha visível para o consumidor decidir como
 * reexecutar, nunca engolida.
 */
final class DispatchResult {

	/** @var string */
	private $status;

	/** @var string|null */
	private $failureReason;

	/** @var DateTimeImmutable */
	private $occurredAt;

	private function __construct( string $status, ?string $failureReason, DateTimeImmutable $occurredAt ) {
		$this->status        = $status;
		$this->failureReason = $failureReason;
		$this->occurredAt    = $occurredAt;
	}

	public static function sent( ?DateTimeImmutable $occurredAt = null ): self {
		return new self( DispatchStatus::SENT, null, $occurredAt ?? new DateTimeImmutable() );
	}

	public static function failed( string $reason, ?DateTimeImmutable $occurredAt = null ): self {
		return new self( DispatchStatus::FAILED, $reason, $occurredAt ?? new DateTimeImmutable() );
	}

	public static function skippedDuplicate( ?DateTimeImmutable $occurredAt = null ): self {
		return new self( DispatchStatus::SKIPPED_DUPLICATE, null, $occurredAt ?? new DateTimeImmutable() );
	}

	public static function skippedPreference( ?DateTimeImmutable $occurredAt = null ): self {
		return new self( DispatchStatus::SKIPPED_PREFERENCE, null, $occurredAt ?? new DateTimeImmutable() );
	}

	public static function unknownChannel( string $channel, ?DateTimeImmutable $occurredAt = null ): self {
		return new self( DispatchStatus::UNKNOWN_CHANNEL, "Canal não registrado: {$channel}", $occurredAt ?? new DateTimeImmutable() );
	}

	public function getStatus(): string {
		return $this->status;
	}

	public function isDelivered(): bool {
		return DispatchStatus::SENT === $this->status;
	}

	public function isFailure(): bool {
		return DispatchStatus::FAILED === $this->status;
	}

	public function getFailureReason(): ?string {
		return $this->failureReason;
	}

	public function getOccurredAt(): DateTimeImmutable {
		return $this->occurredAt;
	}
}
