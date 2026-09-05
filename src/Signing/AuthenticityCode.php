<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

/**
 * O código de autenticidade — emitido, nunca derivado (issue #27, defeito
 * 2 do V3RProp). Gerado com random_bytes() no momento da emissão: não há
 * como reproduzi-lo a partir de campo nenhum do documento, então quem
 * conhece os dados do documento não conhece o código, e quem tem o código
 * não aprende nada sobre o documento sem consultar o registro
 * (AuthenticityRegistry).
 *
 * Alfabeto pensado para ser ditado por telefone e digitado de um papel:
 * maiúsculas e dígitos, excluindo os cinco caracteres mais confundidos a
 * olho e a ouvido — 0/O, 1/I/L. Restam 31 símbolos, todos claramente
 * distintos entre si. Não é Crockford Base32 completo (não há bit de
 * checksum nem correção automática de I/L→1, O→0 na leitura) — só o
 * alfabeto reduzido; a checagem de formato em fromString() é estrita, de
 * propósito: um código mal transcrito precisa ser rejeitado, não
 * "corrigido" silenciosamente para outro código válido por acidente.
 */
final class AuthenticityCode {

	private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

	/**
	 * Quatro grupos de quatro caracteres, formatados como "XXXX-XXXX-XXXX-XXXX"
	 * — legível em voz alta em blocos curtos, com pausa natural a cada grupo.
	 */
	private const GROUP_LENGTH = 4;

	private const GROUPS = 4;

	/** @var string Já formatado, com hífens. */
	private $value;

	private function __construct( string $value ) {
		$this->value = $value;
	}

	/**
	 * Gera um código novo, imprevisível. Usa random_int() (CSPRNG) sobre o
	 * alfabeto reduzido — nunca deriva de mt_rand()/uniqid() nem de campo
	 * nenhum do documento.
	 */
	public static function generate(): self {
		$alphabetLength = strlen( self::ALPHABET );
		$totalChars     = self::GROUP_LENGTH * self::GROUPS;

		$chars = '';
		for ( $i = 0; $i < $totalChars; $i++ ) {
			$chars .= self::ALPHABET[ random_int( 0, $alphabetLength - 1 ) ];
		}

		return new self( self::format( $chars ) );
	}

	/**
	 * Reconstrói a partir de um texto digitado por humano — tolerante a
	 * espaços, hífens e minúsculas, mas estrito quanto ao alfabeto: um
	 * caractere fora dele (incluindo 0, 1, I, L, O) é rejeitado, nunca
	 * mapeado para outra coisa.
	 *
	 * @throws \InvalidArgumentException Formato ou alfabeto inválido.
	 */
	public static function fromString( string $value ): self {
		$stripped = strtoupper( preg_replace( '/[\s-]+/', '', $value ) ?? '' );

		if ( self::GROUP_LENGTH * self::GROUPS !== strlen( $stripped ) ) {
			throw new \InvalidArgumentException( 'AuthenticityCode: comprimento inválido.' );
		}

		if ( 1 !== preg_match( '/\A[' . preg_quote( self::ALPHABET, '/' ) . ']+\z/', $stripped ) ) {
			throw new \InvalidArgumentException( 'AuthenticityCode: caractere fora do alfabeto aceito.' );
		}

		return new self( self::format( $stripped ) );
	}

	public function value(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}

	private static function format( string $rawChars ): string {
		return implode( '-', str_split( $rawChars, self::GROUP_LENGTH ) );
	}
}
