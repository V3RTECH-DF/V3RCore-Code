<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

/**
 * Falha declarada de SignerInterface::sign() — nunca um erro genérico. O
 * chamador precisa distinguir "a senha está errada" (recuperável: o
 * administrador recadastra) de "o certificado está corrompido" (idem) de
 * "a assinatura falhou por outro motivo" (pode exigir suporte).
 */
class SigningException extends \RuntimeException {

	/**
	 * O arquivo do certificado não pôde ser lido/interpretado como
	 * certificado (corrompido, formato inesperado).
	 */
	public const CERTIFICADO_ILEGIVEL = 'certificado_ilegivel';

	/**
	 * A senha fornecida não abre o certificado.
	 */
	public const SENHA_INVALIDA = 'senha_invalida';

	/**
	 * A assinatura em si falhou por um motivo que não é nem certificado
	 * ilegível nem senha inválida (ex.: biblioteca de assinatura do plugin
	 * indisponível, arquivo de origem corrompido).
	 */
	public const FALHA_NA_ASSINATURA = 'falha_na_assinatura';

	/** @var string */
	private $errorCode;

	public function __construct( string $errorCode, string $message, int $code = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
		$this->errorCode = $errorCode;
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}
}
