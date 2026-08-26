<?php
declare(strict_types=1);

namespace V3R\Core\Licensing\Transport;

/**
 * Resultado normalizado de uma chamada HTTP, independente de ter vindo de
 * wp_remote_post()/wp_remote_get() ou de um transporte falso de teste.
 *
 * `isFailure()` cobre timeout, DNS quebrado, ou qualquer erro de transporte
 * que nem chegou a produzir um código HTTP — o HttpApiClient trata isso como
 * falha de comunicação (docs/api-contract.md §7), nunca como resposta 200.
 */
final class HttpTransportResult {

	/** @var bool */
	private $failure;

	/** @var string */
	private $failureMessage;

	/** @var int */
	private $statusCode;

	/** @var string */
	private $body;

	private function __construct( bool $failure, string $failureMessage, int $statusCode, string $body ) {
		$this->failure        = $failure;
		$this->failureMessage = $failureMessage;
		$this->statusCode     = $statusCode;
		$this->body           = $body;
	}

	/**
	 * Falha de transporte: nunca chegou a haver resposta HTTP (timeout, DNS,
	 * conexão recusada, etc.).
	 */
	public static function failure( string $message ): self {
		return new self( true, $message, 0, '' );
	}

	/**
	 * Resposta HTTP recebida, qualquer que seja o código de status — 2xx,
	 * 4xx ou 5xx. Quem decide o que fazer com o status é o chamador.
	 */
	public static function success( int $statusCode, string $body ): self {
		return new self( false, '', $statusCode, $body );
	}

	public function isFailure(): bool {
		return $this->failure;
	}

	public function getFailureMessage(): string {
		return $this->failureMessage;
	}

	public function getStatusCode(): int {
		return $this->statusCode;
	}

	public function getBody(): string {
		return $this->body;
	}
}
