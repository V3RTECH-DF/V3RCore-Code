<?php
declare(strict_types=1);

namespace V3R\Core\Access;

/**
 * Segredo de uso único de um link temporário de acesso: 32 bytes
 * aleatórios em base64url, com o texto puro EFÊMERO (viaja no e-mail e na
 * URL de retorno) e apenas o `sha256` destinado à persistência.
 *
 * A regra que a classe existe para tornar difícil de quebrar: quem guarda
 * o token guarda `hash()`, nunca `plaintext()`. Vazamento da tabela não
 * entrega acesso a ninguém, porque o que está lá não é o que a URL leva.
 * Por isso a comparação é `matches()`, com `hash_equals()` — comparar com
 * `===` abriria uma diferença de tempo mensurável entre um hash que erra
 * no primeiro byte e outro que erra no último.
 *
 * A classe não sabe o que o token autoriza, nem quem é a pessoa, nem onde
 * o hash é guardado: identidade, tabela, TTL e consumo atômico são de cada
 * produto (ver docs/acesso-por-link-temporario.md).
 */
final class AccessToken {

	/** Bytes de entropia do segredo — 256 bits. */
	public const ENTROPY_BYTES = 32;

	/** @var string */
	private $plaintext;

	/** @var string */
	private $hash;

	private function __construct( string $plaintext, string $hash ) {
		$this->plaintext = $plaintext;
		$this->hash      = $hash;
	}

	/**
	 * Emite um token novo. O texto puro só existe nesta instância: depois
	 * de enviado, o que resta em qualquer lugar é o hash.
	 */
	public static function generate(): self {
		$plaintext = self::base64Url( random_bytes( self::ENTROPY_BYTES ) );

		return new self( $plaintext, hash( 'sha256', $plaintext ) );
	}

	/**
	 * Reconstrói a partir do que chegou na URL, para procurar o hash
	 * correspondente. Não valida nada além de "não é vazio" — validade,
	 * expiração e uso único são de quem guarda o registro.
	 *
	 * @throws \InvalidArgumentException Texto puro vazio.
	 */
	public static function fromPlaintext( string $plaintext ): self {
		if ( '' === $plaintext ) {
			throw new \InvalidArgumentException( 'Token vazio não é token — o hash de "" é um sha256 válido e procurá-lo na base é consulta que nunca deveria sair.' );
		}

		return new self( $plaintext, hash( 'sha256', $plaintext ) );
	}

	public function plaintext(): string {
		return $this->plaintext;
	}

	public function hash(): string {
		return $this->hash;
	}

	/**
	 * Comparação em tempo constante contra um hash já persistido.
	 */
	public function matches( string $storedHash ): bool {
		return hash_equals( $storedHash, $this->hash );
	}

	private static function base64Url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
