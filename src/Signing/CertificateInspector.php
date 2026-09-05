<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

use DateTimeImmutable;

/**
 * Abre um PKCS#12 (.p12/.pfx) a partir de `CertificateMaterial` e diz se é
 * utilizável, até quando, e de quem é o titular (V3RCore-Code#29,
 * promovida do RIT360 Flow). Único ponto da biblioteca que chama
 * `openssl_pkcs12_read()` — a mesma abertura confirma DUAS coisas de uma
 * vez: que a senha bate (senão a extensão recusa abrir) e que o conteúdo
 * é mesmo um certificado com chave privada.
 *
 * O resultado alimenta `SigningModeResolver::decide()` direto: sem
 * validade reconhecida no certificado (campo ausente/ilegível, ou a
 * extensão `openssl` indisponível no ambiente), `expiresAt() === null` —
 * nunca uma data inventada; é esse `null` que faz o resolver cair no modo
 * degradado por `SigningModeReason::SEM_VALIDADE_CONHECIDA`, conservador
 * de propósito.
 *
 * ⚠️ **A ausência da extensão `openssl` NÃO é erro fatal.** A biblioteca
 * não declara `ext-openssl` no `require` do composer — quebraria a
 * instalação de quem nunca assina — só sugere. Em ambiente sem a
 * extensão, `inspect()` devolve `CertificateInspection::failure()`: o
 * mesmo modo degradado que qualquer outra causa de "não deu para ler o
 * certificado".
 */
final class CertificateInspector {

	public function inspect( CertificateMaterial $material ): CertificateInspection {
		if ( ! extension_loaded( 'openssl' ) || ! function_exists( 'openssl_pkcs12_read' ) ) {
			return CertificateInspection::failure( 'A extensão openssl do PHP não está disponível neste ambiente — não é possível ler o certificado.' );
		}

		if ( '' === $material->password() ) {
			return CertificateInspection::failure( 'Informe a senha do certificado.' );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lê arquivo local de certificado, não uma URL remota; a biblioteca roda também fora do WordPress; o `@` evita o warning de PHP para arquivo ilegível, já tratado pelo `is_string()` logo abaixo.
		$contents = @file_get_contents( $material->certificateFilePath() );

		if ( ! is_string( $contents ) || '' === $contents ) {
			return CertificateInspection::failure( 'Não foi possível ler o arquivo do certificado.' );
		}

		$certs = array();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- openssl_pkcs12_read() emite warning de PHP para senha errada/arquivo corrompido; o retorno booleano já basta para decidir, sem vazar o aviso técnico para quem preencheu.
		$opened = @openssl_pkcs12_read( $contents, $certs, $material->password() );

		if ( ! $opened || ! isset( $certs['cert'] ) || ! is_string( $certs['cert'] ) ) {
			return CertificateInspection::failure( 'Não foi possível abrir o certificado com a senha informada — confira o arquivo e a senha.' );
		}

		$parsed = openssl_x509_parse( $certs['cert'] );

		if ( ! is_array( $parsed ) ) {
			return CertificateInspection::success( null );
		}

		$subject = $this->readSubject( $parsed );

		if ( ! isset( $parsed['validTo_time_t'] ) || ! is_int( $parsed['validTo_time_t'] ) ) {
			// Certificado abre, mas sem validade reconhecida — conservador:
			// não é o mesmo que "certificado válido".
			return CertificateInspection::success( null, $subject );
		}

		return CertificateInspection::success( ( new DateTimeImmutable() )->setTimestamp( $parsed['validTo_time_t'] ), $subject );
	}

	/**
	 * O titular, do jeito mais robusto que o `openssl_x509_parse()` do PHP
	 * permite — e `null` sempre que o nome não sair daí (degradar com
	 * honestidade, nunca chutar).
	 *
	 * Nos certificados da ICP-Brasil o titular vem no nome comum no
	 * formato `NOME:DOCUMENTO` (`RIT:12345678000195`, `MARIA DA
	 * SILVA:52998224725`) — 14 dígitos é CNPJ, 11 é CPF. Quando o nome
	 * comum não traz o documento, `serialNumber` e `organizationIdentifier`
	 * do próprio titular são as duas outras posições em que ele costuma
	 * aparecer.
	 *
	 * ⚠️ **A extensão `subjectAltName` NÃO é usada de propósito.** É onde a
	 * ICP-Brasil guarda CPF/CNPJ de forma canônica, mas em `othername` com
	 * OID próprio, que o PHP não decodifica (aparece como
	 * `othername:<unsupported>`); e varrer aquele bloco atrás de uma
	 * sequência de 11 dígitos pegaria o NIS ou o RG do responsável em vez
	 * do CPF do titular — um documento errado impresso é pior que nenhum.
	 *
	 * @param array<string,mixed> $parsed Saída de openssl_x509_parse().
	 */
	private function readSubject( array $parsed ): ?CertificateSubject {
		$subject = is_array( $parsed['subject'] ?? null ) ? $parsed['subject'] : array();
		$issuer  = is_array( $parsed['issuer'] ?? null ) ? $parsed['issuer'] : array();

		$commonName = $this->field( $subject, 'CN' );
		$name       = '' !== $commonName ? $commonName : $this->field( $subject, 'O' );

		if ( '' === $name ) {
			return null;
		}

		$document = null;

		if ( 1 === preg_match( '/^(?P<name>.*?)\s*:\s*(?P<document>\d{11}|\d{14})$/', $name, $matches ) && '' !== trim( $matches['name'] ) ) {
			$name     = trim( $matches['name'] );
			$document = $matches['document'];
		}

		if ( null === $document ) {
			$document = $this->documentField( $subject );
		}

		return new CertificateSubject(
			$name,
			null !== $document ? ( 14 === strlen( $document ) ? CertificateSubject::DOCUMENT_CNPJ : CertificateSubject::DOCUMENT_CPF ) : null,
			$document,
			$this->issuerName( $issuer ),
			! $this->isSelfSigned( $subject, $issuer )
		);
	}

	/**
	 * CPF/CNPJ fora do nome comum: `serialNumber` (onde a ICP-Brasil
	 * repete o documento em vários perfis) e `organizationIdentifier`. Só
	 * aceita o campo INTEIRO com 11 ou 14 dígitos — nunca um trecho
	 * extraído de uma cadeia maior, que é como se troca um documento por
	 * outro sem ninguém notar.
	 *
	 * @param array<string,mixed> $subject
	 */
	private function documentField( array $subject ): ?string {
		foreach ( array( 'serialNumber', 'organizationIdentifier', 'OID.2.5.4.97' ) as $key ) {
			$value = (string) preg_replace( '/\D/', '', $this->field( $subject, $key ) );

			if ( 11 === strlen( $value ) || 14 === strlen( $value ) ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Autoassinado é titular IGUAL a emissor. É a única distinção que o
	 * certificado carrega entre "este nome foi conferido por alguém" e
	 * "este nome foi digitado por quem gerou o arquivo".
	 *
	 * @param array<string,mixed> $subject
	 * @param array<string,mixed> $issuer
	 */
	private function isSelfSigned( array $subject, array $issuer ): bool {
		if ( array() === $issuer ) {
			// Sem emissor legível não dá para afirmar que outra parte
			// atestou o titular — tratar como declarado é o lado
			// conservador.
			return true;
		}

		return $this->flatten( $subject ) === $this->flatten( $issuer );
	}

	/** @param array<string,mixed> $issuer */
	private function issuerName( array $issuer ): ?string {
		$name = $this->field( $issuer, 'CN' );
		$name = '' !== $name ? $name : $this->field( $issuer, 'O' );

		return '' !== $name ? $name : null;
	}

	/**
	 * O campo do DN como string. `openssl_x509_parse()` devolve um ARRAY
	 * quando o mesmo atributo aparece mais de uma vez (acontece com `OU`
	 * em certificado da ICP-Brasil) — nesse caso vale o primeiro valor.
	 *
	 * @param array<string,mixed> $dn
	 */
	private function field( array $dn, string $key ): string {
		$value = $dn[ $key ] ?? null;

		if ( is_array( $value ) ) {
			$value = $value[0] ?? null;
		}

		return is_string( $value ) ? trim( $value ) : '';
	}

	/** @param array<string,mixed> $dn */
	private function flatten( array $dn ): string {
		$parts = array();

		foreach ( $dn as $key => $value ) {
			$parts[] = $key . '=' . ( is_array( $value ) ? implode( '+', array_map( 'strval', $value ) ) : (string) $value );
		}

		sort( $parts );

		return implode( ',', $parts );
	}
}
