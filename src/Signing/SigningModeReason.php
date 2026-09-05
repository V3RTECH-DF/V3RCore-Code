<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

/**
 * Por que SigningModeResolver chegou naquele modo — importa tanto quanto o
 * modo em si (issue #27): é o que a tela mostra e o que explica ao
 * administrador por que a assinatura não saiu como ele esperava (ex.:
 * certificado cadastrado, mas vencido — não é a mesma situação de "nunca
 * cadastrou nada", mesmo que as duas caiam no mesmo modo degradado).
 */
final class SigningModeReason {

	/**
	 * Nenhum arquivo de certificado cadastrado.
	 */
	public const SEM_CERTIFICADO = 'sem_certificado';

	/**
	 * Há arquivo de certificado, mas a validade dele é desconhecida.
	 * Deliberadamente tratado como degradado, nunca como "certificado
	 * bom" — sem saber a validade não há como confirmar que ele ainda
	 * vale (issue #27: "sem data de validade conhecida, o resultado é o
	 * modo degradado — conservador, nunca o contrário").
	 */
	public const SEM_VALIDADE_CONHECIDA = 'sem_validade_conhecida';

	/**
	 * Há arquivo e validade conhecida, mas a validade já passou.
	 */
	public const CERTIFICADO_VENCIDO = 'certificado_vencido';

	/**
	 * Há arquivo de certificado com validade conhecida e futura — o único
	 * motivo que leva ao modo SigningMode::CERTIFICADO_DIGITAL.
	 */
	public const CERTIFICADO_VALIDO = 'certificado_valido';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::SEM_CERTIFICADO,
			self::SEM_VALIDADE_CONHECIDA,
			self::CERTIFICADO_VENCIDO,
			self::CERTIFICADO_VALIDO,
		);
	}

	public static function isValid( string $reason ): bool {
		return in_array( $reason, self::all(), true );
	}

	private function __construct() {
		// Classe estática — não instanciável.
	}
}
