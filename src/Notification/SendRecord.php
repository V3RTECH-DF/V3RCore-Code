<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

use DateTimeImmutable;

/**
 * Um envio concluído (com sucesso ou falha), pronto para o consumidor
 * persistir no PRÓPRIO banco via SendLogInterface::record() — a biblioteca
 * não guarda isto em lugar nenhum (V3RCore não tem tabela própria nem
 * mecanismo de migração, decisão da issue #1 do RIT360 Flow).
 */
final class SendRecord {

	/** @var string */
	private $dispatchId;

	/** @var string */
	private $channel;

	/** @var string */
	private $recipient;

	/** @var string */
	private $subject;

	/** @var DateTimeImmutable */
	private $sentAt;

	/** @var bool */
	private $delivered;

	/** @var string|null */
	private $failureReason;

	public function __construct(
		string $dispatchId,
		string $channel,
		string $recipient,
		string $subject,
		DateTimeImmutable $sentAt,
		bool $delivered,
		?string $failureReason
	) {
		$this->dispatchId    = $dispatchId;
		$this->channel       = $channel;
		$this->recipient     = $recipient;
		$this->subject       = $subject;
		$this->sentAt        = $sentAt;
		$this->delivered     = $delivered;
		$this->failureReason = $failureReason;
	}

	public function getDispatchId(): string {
		return $this->dispatchId;
	}

	public function getChannel(): string {
		return $this->channel;
	}

	public function getRecipient(): string {
		return $this->recipient;
	}

	public function getSubject(): string {
		return $this->subject;
	}

	public function getSentAt(): DateTimeImmutable {
		return $this->sentAt;
	}

	public function wasDelivered(): bool {
		return $this->delivered;
	}

	public function getFailureReason(): ?string {
		return $this->failureReason;
	}
}
