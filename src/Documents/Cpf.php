<?php
declare(strict_types=1);

namespace V3R\Core\Documents;

/**
 * CPF — normalização, validação e formatação (V3RCore-Code#22). Promovido
 * pelo mesmo motivo do CNPJ: quatro cópias na casa (GE Associados,
 * V3REvent, RIT360 Solidário e RIT360 Flow), num campo que alimenta
 * documento com validade jurídica.
 *
 * O CPF **não** tem formato alfanumérico: a mudança da Receita Federal
 * alcança só a pessoa jurídica. Onze dígitos, dois verificadores por
 * módulo 11 — regra estável, e é por isso que a peça é pequena.
 *
 * Classe pura, sem WordPress, espelhando a API de `Cnpj` (mesmos três
 * métodos, mesma semântica) — divergir no dialeto entre as duas seria
 * reintroduzir, dentro da biblioteca, a diferença que a promoção veio
 * eliminar.
 */
final class Cpf {

	/** Mantém só dígitos: remove máscara, espaço e qualquer outro símbolo. */
	public static function normalize( string $raw ): string {
		return (string) preg_replace( '/\D/', '', $raw );
	}

	/**
	 * Onze dígitos, não todos iguais, com os dois verificadores conferindo.
	 *
	 * A recusa de "todos os dígitos iguais" não é firula: `00000000000` e
	 * `11111111111` **passam** pelo cálculo do dígito verificador por
	 * acidente aritmético, e são justamente os valores que alguém digita
	 * para preencher um campo obrigatório sem informar nada.
	 */
	public static function isValid( string $raw ): bool {
		$value = self::normalize( $raw );

		if ( 1 !== preg_match( '/^\d{11}$/', $value ) ) {
			return false;
		}

		if ( 1 === preg_match( '/^(\d)\1{10}$/', $value ) ) {
			return false;
		}

		if ( self::checkDigit( $value, 9, 10 ) !== (int) $value[9] ) {
			return false;
		}

		return self::checkDigit( $value, 10, 11 ) === (int) $value[10];
	}

	/**
	 * Máscara `XXX.XXX.XXX-DD`. Entrada que não chega a 11 dígitos devolve
	 * o **normalizado**, nunca o texto cru — mesma decisão de `Cnpj`, e a
	 * única divergência de comportamento encontrada entre as cópias: a do
	 * RIT360 Solidário devolvia a entrada original.
	 */
	public static function format( string $raw ): string {
		$value = self::normalize( $raw );

		if ( 11 !== strlen( $value ) ) {
			return $value;
		}

		return sprintf(
			'%s.%s.%s-%s',
			substr( $value, 0, 3 ),
			substr( $value, 3, 3 ),
			substr( $value, 6, 3 ),
			substr( $value, 9, 2 )
		);
	}

	private static function checkDigit( string $digits, int $length, int $startWeight ): int {
		$sum = 0;

		for ( $i = 0; $i < $length; $i++ ) {
			$sum += (int) $digits[ $i ] * ( $startWeight - $i );
		}

		$remainder = $sum % 11;

		return $remainder < 2 ? 0 : 11 - $remainder;
	}
}
