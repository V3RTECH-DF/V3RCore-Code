<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

/**
 * Erro de comunicação com o servidor de licenças — falha de rede, timeout,
 * 5xx, ou corpo de erro no formato REST do WordPress (code/message/data.status,
 * ver docs/api-contract.md).
 */
class ApiException extends \RuntimeException {

	/** @var string */
	private $errorCode;

	public function __construct( string $errorCode, string $message, int $httpStatus = 0 ) {
		parent::__construct( $message, $httpStatus );
		$this->errorCode = $errorCode;
	}

	/**
	 * Código de erro estável (ex.: invalid_key, rate_limited) — ver
	 * docs/api-contract.md para a lista completa.
	 */
	public function getErrorCode(): string {
		return $this->errorCode;
	}
}
