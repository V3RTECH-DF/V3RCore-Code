<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

/**
 * Erro de comunicação com o servidor de licenças — falha de rede, timeout,
 * 5xx, ou corpo de erro no formato REST do WordPress (code/message/data.status,
 * ver docs/api-contract.md).
 */
class ApiException extends \RuntimeException {

	/**
	 * Código interno (não faz parte do protocolo — nunca vem do servidor):
	 * marca qualquer situação em que não sabemos de verdade o estado da
	 * licença — timeout, 5xx, JSON malformado ou assinatura inválida/ausente.
	 * Todas essas situações levam ao MESMO tratamento no LicenseManager
	 * (mantém último estado conhecido, entra/permanece em grace period),
	 * exatamente como a tabela de docs/api-contract.md §7 exige.
	 */
	public const COMMUNICATION_FAILURE = 'communication_failure';

	/** @var string */
	private $errorCode;

	public function __construct( string $errorCode, string $message, int $httpStatus = 0 ) {
		parent::__construct( $message, $httpStatus );
		$this->errorCode = $errorCode;
	}

	/**
	 * Código de erro estável (ex.: invalid_key, rate_limited, ou
	 * self::COMMUNICATION_FAILURE) — ver docs/api-contract.md para a lista
	 * completa dos códigos que vêm do protocolo.
	 */
	public function getErrorCode(): string {
		return $this->errorCode;
	}

	/**
	 * Verdadeiro quando esta exceção representa "não sei se a licença ainda
	 * vale" (falha de comunicação) — nunca "sei que não vale mais". É essa
	 * distinção que decide se o grace period se aplica (docs/api-contract.md §6/§7).
	 */
	public function isCommunicationFailure(): bool {
		return self::COMMUNICATION_FAILURE === $this->errorCode;
	}
}
