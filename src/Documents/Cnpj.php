<?php
declare(strict_types=1);

namespace V3R\Core\Documents;

/**
 * CNPJ — normalização, validação e formatação, **numérico e alfanumérico**
 * (V3RCore-Code#22). Promovido para a biblioteca porque já eram quatro
 * cópias na casa (GE Associados, V3REvent, V3RLGPD e RIT360 Flow), e o
 * campo alimenta documento com validade jurídica: a cópia que ficar para
 * trás vai recusar CNPJ válido ou aceitar inválido, calada.
 *
 * Regra da Receita Federal, em produção a partir de julho de 2026: as 12
 * primeiras posições aceitam `0-9` e `A-Z`; os dois dígitos verificadores
 * continuam numéricos; a máscara não muda (`XX.XXX.XXX/XXXX-DV`).
 *
 * O dígito verificador é módulo 11 com os pesos clássicos, sobre o valor
 * `ASCII(c) - 48` de cada caractere (`0`-`9` → 0-9, `A`-`Z` → 17-42).
 * Como para dígitos esse valor é o próprio dígito, **o CNPJ numérico é
 * caso particular do alfanumérico**: uma implementação valida os dois, e a
 * retrocompatibilidade é por construção, não por ramo separado.
 *
 * Classe pura, sem WordPress. A biblioteca entrega a regra, não o modelo:
 * quem quiser objeto-valor com identidade (`from()`, `equals()`) constrói
 * no próprio domínio e delega a validação aqui — foi o que evitou obrigar
 * o GE Associados a reescrever o domínio dele para consumir isto.
 */
final class Cnpj {

	/** Pesos do 1º dígito verificador (12 posições). */
	private const WEIGHTS_FIRST = array( 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );

	/** Pesos do 2º dígito verificador (13 posições). */
	private const WEIGHTS_SECOND = array( 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );

	/**
	 * Mantém só `[0-9A-Z]`, em caixa alta: remove máscara, espaço e
	 * qualquer outro símbolo, e aceita o que foi digitado em minúsculas.
	 */
	public static function normalize( string $raw ): string {
		return (string) preg_replace( '/[^0-9A-Z]/', '', strtoupper( trim( $raw ) ) );
	}

	/**
	 * 14 caracteres, os 12 primeiros em `[0-9A-Z]` e os 2 últimos em
	 * `[0-9]`, não todos iguais, com os dois dígitos verificadores
	 * conferindo.
	 *
	 * A recusa de "todos os caracteres iguais" é convenção da casa, não
	 * regra da Receita: é o padrão de placeholder (`11111111111111`) que o
	 * formato numérico já rejeitava. Ela **não** alcança nenhum
	 * alfanumérico legítimo, e isso é por construção — os verificadores são
	 * numéricos, então um CNPJ com letra nunca tem os 14 caracteres iguais.
	 */
	public static function isValid( string $raw ): bool {
		$value = self::normalize( $raw );

		if ( 1 !== preg_match( '/^[0-9A-Z]{12}[0-9]{2}$/', $value ) ) {
			return false;
		}

		if ( 1 === preg_match( '/^(.)\1{13}$/', $value ) ) {
			return false;
		}

		if ( self::checkDigit( substr( $value, 0, 12 ), self::WEIGHTS_FIRST ) !== (int) $value[12] ) {
			return false;
		}

		return self::checkDigit( substr( $value, 0, 13 ), self::WEIGHTS_SECOND ) === (int) $value[13];
	}

	/**
	 * Máscara `XX.XXX.XXX/XXXX-DV`. Entrada que não chega a 14 caracteres
	 * devolve o **normalizado**, nunca o texto cru: formatar é operação de
	 * exibição, e devolver o cru jogaria de volta na tela exatamente o que
	 * o usuário digitou, incluindo o que a normalização existe para tirar.
	 */
	public static function format( string $raw ): string {
		$value = self::normalize( $raw );

		if ( 14 !== strlen( $value ) ) {
			return $value;
		}

		return sprintf(
			'%s.%s.%s/%s-%s',
			substr( $value, 0, 2 ),
			substr( $value, 2, 3 ),
			substr( $value, 5, 3 ),
			substr( $value, 8, 4 ),
			substr( $value, 12, 2 )
		);
	}

	/**
	 * @param string     $base
	 * @param array<int> $weights
	 */
	private static function checkDigit( string $base, array $weights ): int {
		$sum    = 0;
		$length = strlen( $base );

		for ( $i = 0; $i < $length; $i++ ) {
			$sum += ( ord( $base[ $i ] ) - 48 ) * $weights[ $i ];
		}

		$remainder = $sum % 11;

		return $remainder < 2 ? 0 : 11 - $remainder;
	}
}
