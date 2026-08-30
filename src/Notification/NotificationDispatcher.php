<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

/**
 * Ponto único de envio: resolve o canal (ChannelRegistry), aplica a
 * preferência do destinatário, impede reenvio duplicado (SendLogInterface),
 * despacha e registra o resultado — sempre, inclusive falha.
 *
 * Ordem das checagens, importante: duplicidade ANTES de preferência. Se o
 * destinatário já recebeu, a pergunta "ele quer receber" é irrelevante (ele
 * já recebeu); e reconsultar a preferência depois de já ter enviado não deve
 * mudar o resultado de uma reexecução idêntica.
 */
final class NotificationDispatcher {

	/** @var ChannelRegistry */
	private $channels;

	/** @var SendLogInterface */
	private $log;

	/** @var RecipientPreferenceInterface */
	private $preferences;

	public function __construct(
		ChannelRegistry $channels,
		SendLogInterface $log,
		RecipientPreferenceInterface $preferences
	) {
		$this->channels    = $channels;
		$this->log         = $log;
		$this->preferences = $preferences;
	}

	public function dispatch( Message $message ): DispatchResult {
		if ( $this->log->wasDispatched( $message->getDispatchId() ) ) {
			return DispatchResult::skippedDuplicate();
		}

		if ( MessageCategory::DISPENSABLE === $message->getCategory()
			&& ! $this->preferences->mayReceive( $message->getRecipient(), $message->getCategory() )
		) {
			return DispatchResult::skippedPreference();
		}

		if ( ! $this->channels->has( $message->getChannel() ) ) {
			$result = DispatchResult::unknownChannel( $message->getChannel() );
			$this->recordResult( $message, $result );

			return $result;
		}

		$result = $this->channels->get( $message->getChannel() )->send( $message );
		$this->recordResult( $message, $result );

		return $result;
	}

	private function recordResult( Message $message, DispatchResult $result ): void {
		$this->log->record(
			new SendRecord(
				$message->getDispatchId(),
				$message->getChannel(),
				$message->getRecipient(),
				$message->getSubject(),
				$result->getOccurredAt(),
				$result->isDelivered(),
				$result->getFailureReason()
			)
		);
	}
}
