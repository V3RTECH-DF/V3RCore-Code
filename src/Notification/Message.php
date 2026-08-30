<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

/**
 * Um pedido de envio, já com o texto montado (ver TemplateRenderer) —
 * imutável, canal-agnóstico na identificação do destinatário (endereço de
 * e-mail hoje; outro canal define o formato que aceita).
 *
 * $dispatchId é a chave de idempotência (ver NotificationDispatcher):
 * reenviar com o MESMO id não duplica para quem já recebeu. É
 * responsabilidade de quem monta a mensagem escolher um id estável para o
 * mesmo evento de negócio (ex.: "credencial-revogada:{$personId}"), nunca
 * gerado aleatoriamente a cada tentativa — um id aleatório tornaria a
 * proteção contra duplicidade inoperante.
 */
final class Message {

	/** @var string */
	private $channel;

	/** @var string */
	private $recipient;

	/** @var string */
	private $subject;

	/** @var string */
	private $body;

	/** @var string */
	private $category;

	/** @var string */
	private $dispatchId;

	public function __construct(
		string $channel,
		string $recipient,
		string $subject,
		string $body,
		string $category,
		string $dispatchId
	) {
		if ( ! MessageCategory::isValid( $category ) ) {
			throw new \InvalidArgumentException( "Categoria de mensagem inválida: {$category}" );
		}

		if ( '' === $dispatchId ) {
			throw new \InvalidArgumentException( 'dispatchId não pode ser vazio — é a chave que impede reenvio duplicado.' );
		}

		$this->channel    = $channel;
		$this->recipient  = $recipient;
		$this->subject    = $subject;
		$this->body       = $body;
		$this->category   = $category;
		$this->dispatchId = $dispatchId;
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

	public function getBody(): string {
		return $this->body;
	}

	public function getCategory(): string {
		return $this->category;
	}

	public function getDispatchId(): string {
		return $this->dispatchId;
	}
}
