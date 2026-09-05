<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

/**
 * Resultado imutável de SigningModeResolver::decide(): o modo e o motivo,
 * sempre juntos. Nunca existe modo sem motivo — é o que impede a
 * degradação silenciosa que o V3RProp tinha (issue #27, defeito 1).
 */
final class SigningModeDecision {

	/** @var string */
	private $mode;

	/** @var string */
	private $reason;

	/**
	 * @throws \InvalidArgumentException Modo ou motivo fora do vocabulário conhecido —
	 *                                    erro de programação de quem monta a decisão, nunca
	 *                                    de quem só consome o resultado de decide().
	 */
	public function __construct( string $mode, string $reason ) {
		if ( ! SigningMode::isValid( $mode ) ) {
			throw new \InvalidArgumentException( "SigningModeDecision: modo desconhecido '{$mode}'." );
		}

		if ( ! SigningModeReason::isValid( $reason ) ) {
			throw new \InvalidArgumentException( "SigningModeDecision: motivo desconhecido '{$reason}'." );
		}

		$this->mode   = $mode;
		$this->reason = $reason;
	}

	public function mode(): string {
		return $this->mode;
	}

	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Verdadeiro quando o modo é o degradado (SigningMode::REGISTRO_ELETRONICO)
	 * — conveniência para quem só quer decidir se mostra o aviso de degradação,
	 * sem comparar a constante à mão em cada ponto de chamada.
	 */
	public function isDegraded(): bool {
		return SigningMode::REGISTRO_ELETRONICO === $this->mode;
	}
}
