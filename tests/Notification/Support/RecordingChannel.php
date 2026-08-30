<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Notification\Support;

use V3R\Core\Notification\ChannelInterface;
use V3R\Core\Notification\DispatchResult;
use V3R\Core\Notification\Message;

/**
 * Canal double que grava cada mensagem recebida (para provar QUANTAS vezes
 * foi chamado — é isso que prova a não-duplicidade, não só o resultado
 * devolvido) e permite programar sucesso/falha.
 */
final class RecordingChannel implements ChannelInterface {

	/** @var list<Message> */
	private array $received = array();

	private bool $shouldSucceed;

	public function __construct( bool $shouldSucceed = true ) {
		$this->shouldSucceed = $shouldSucceed;
	}

	public function name(): string {
		return 'email';
	}

	public function send( Message $message ): DispatchResult {
		$this->received[] = $message;

		return $this->shouldSucceed
			? DispatchResult::sent()
			: DispatchResult::failed( 'falha simulada' );
	}

	public function callCount(): int {
		return count( $this->received );
	}

	/** @return list<Message> */
	public function received(): array {
		return $this->received;
	}
}
