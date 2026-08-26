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

	/**
	 * Subcaso de falha de comunicação (fatia 2b, docs/api-contract.md
	 * §8.9/§8.10): a resposta chegou, mas o payload/signature não veio no
	 * formato esperado ou a assinatura ed25519 falhou a verificação.
	 * isCommunicationFailure() continua verdadeiro para este código — para
	 * o protocolo externo (§7) é exatamente a mesma coisa que
	 * COMMUNICATION_FAILURE, e o LicenseManager trata os dois de forma
	 * idêntica (mantém estado, entra em grace period). A distinção só
	 * importa para o protocolo interno (§8), que precisa devolver
	 * `signature_invalid` (502) separado de `server_unreachable` (503) —
	 * a tela nunca pode confundir "não deu para confirmar a assinatura"
	 * com "servidor fora do ar".
	 */
	public const SIGNATURE_INVALID = 'signature_invalid';

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
		return self::COMMUNICATION_FAILURE === $this->errorCode || self::SIGNATURE_INVALID === $this->errorCode;
	}

	/**
	 * Verdadeiro só para o subcaso "assinatura não confirmada" (ver
	 * self::SIGNATURE_INVALID) — usado pelo protocolo REST interno (§8.10)
	 * para responder `signature_invalid` (502) em vez de `server_unreachable`
	 * (503). Nunca usado pelo protocolo externo, que não distingue os dois.
	 */
	public function isSignatureInvalid(): bool {
		return self::SIGNATURE_INVALID === $this->errorCode;
	}
}
