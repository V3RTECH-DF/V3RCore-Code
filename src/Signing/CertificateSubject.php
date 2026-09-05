<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

use V3R\Core\Documents\Cnpj;

/**
 * O titular de um certificado, LIDO do arquivo por `CertificateInspector`
 * — quem é, qual documento identificador ele traz, quem o emitiu, e se
 * essa identidade foi atestada por outra parte ou apenas declarada por
 * quem gerou o arquivo (V3RCore-Code#29).
 *
 * ⚠️ **`attested` significa "não autoassinado", não "emitido pela
 * ICP-Brasil".** Um certificado emitido por autoridade certificadora teve
 * o nome do titular conferido por alguém antes de virar certificado. Num
 * autoassinado, o nome é o que quem gerou o arquivo digitou — e um
 * autoassinado com `CN=RIT:12345678000195` é trivial de fabricar.
 * Restringir a lista de emissores confiáveis à ICP-Brasil é decisão de
 * produto que não foi tomada aqui: um certificado de AC privada conta
 * como atestado do mesmo jeito. Emissor ausente ou ilegível é tratado
 * como declarado — o lado conservador.
 *
 * ⚠️ **CPF sai mascarado, CNPJ sai inteiro.** CNPJ é dado público de
 * pessoa jurídica; CPF identifica pessoa natural e o documento assinado
 * circula. `maskedDocument()` é o ÚNICO acessor de documento para
 * exibição — os dígitos crus ficam em `documentDigits()`, que existe para
 * persistência, nunca para tela, log ou URL.
 */
final class CertificateSubject {

	public const DOCUMENT_CNPJ = 'cnpj';
	public const DOCUMENT_CPF  = 'cpf';

	/** @var string */
	private $name;

	/** @var string|null */
	private $documentType;

	/** @var string|null */
	private $documentDigits;

	/** @var string|null */
	private $issuer;

	/** @var bool */
	private $attested;

	/**
	 * @param string      $name           Nome do titular, já sem o documento grudado.
	 * @param string|null $documentType   self::DOCUMENT_CNPJ, self::DOCUMENT_CPF ou null
	 *                                    quando o certificado não traz documento identificável.
	 * @param string|null $documentDigits Só dígitos; null junto com $documentType.
	 * @param string|null $issuer         Quem emitiu, como consta no certificado.
	 * @param bool        $attested       Falso para autoassinado — identidade declarada, não verificada.
	 */
	public function __construct( string $name, ?string $documentType, ?string $documentDigits, ?string $issuer, bool $attested ) {
		$name   = trim( $name );
		$digits = null !== $documentDigits ? (string) preg_replace( '/\D/', '', $documentDigits ) : null;

		if ( null === $digits || '' === $digits ) {
			$documentType = null;
			$digits       = null;
		}

		$this->name           = $name;
		$this->documentType   = $documentType;
		$this->documentDigits = $digits;
		$this->issuer         = null !== $issuer && '' !== trim( $issuer ) ? trim( $issuer ) : null;
		$this->attested       = $attested;
	}

	public function name(): string {
		return $this->name;
	}

	/** Tipo do documento: `self::DOCUMENT_CNPJ`, `self::DOCUMENT_CPF` ou `null`. */
	public function documentType(): ?string {
		return $this->documentType;
	}

	/** Só dígitos — para persistir, NUNCA para exibir (ver o ⚠️ da classe). */
	public function documentDigits(): ?string {
		return $this->documentDigits;
	}

	public function issuer(): ?string {
		return $this->issuer;
	}

	/** Identidade verificada por um emissor externo (não autoassinado). */
	public function isAttested(): bool {
		return $this->attested;
	}

	/**
	 * O documento como ele pode aparecer em documento e em tela: CNPJ
	 * inteiro e formatado (delega a `Documents\Cnpj::format()` — não
	 * reimplementa a máscara); CPF com os grupos das pontas ocultos
	 * (`***.982.247-**`). Null quando não há documento.
	 */
	public function maskedDocument(): ?string {
		if ( null === $this->documentDigits || null === $this->documentType ) {
			return null;
		}

		if ( self::DOCUMENT_CNPJ === $this->documentType ) {
			return Cnpj::format( $this->documentDigits );
		}

		if ( 11 !== strlen( $this->documentDigits ) ) {
			return null;
		}

		return sprintf(
			'***.%s.%s-**',
			substr( $this->documentDigits, 3, 3 ),
			substr( $this->documentDigits, 6, 3 )
		);
	}
}
