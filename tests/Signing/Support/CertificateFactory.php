<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Signing\Support;

/**
 * Gera certificados PKCS#12 de teste com as funções de openssl do próprio
 * PHP — sem depender do binário `openssl` da linha de comando e sem
 * commitar binário de fixture (V3RCore-Code#29). Cada certificado é
 * descartável, gerado no momento do teste.
 *
 * As checagens de `false` logo após cada chamada de openssl_*() não são
 * só defensivas: os stubs do PHPStan para esta versão do PHP tipam essas
 * funções como `resource|string|false`/`OpenSSLAsymmetricKey|false`, e é
 * o `throw` que estreita o tipo para o que as chamadas seguintes esperam.
 */
final class CertificateFactory {

	private const KEY_OPTS = array(
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
	);

	/**
	 * PKCS#12 autoassinado (titular === emissor) com o `dn` informado.
	 *
	 * @param array<string,string> $dn
	 */
	public static function selfSigned( array $dn, string $password, int $days = 365 ): string {
		$key  = self::newKey( self::KEY_OPTS );
		$csr  = self::newCsr( $dn, $key );
		$cert = self::signCsr( $csr, null, $key, $days );

		return self::export( $cert, $key, $password );
	}

	/**
	 * PKCS#12 autoassinado cuja validade nasce vencida: `$days = 0` faz o
	 * `validTo` coincidir com o instante de geração, que já fica no
	 * passado (ou, no limite, igual) no instante em que o teste compara.
	 *
	 * @param array<string,string> $dn
	 */
	public static function selfSignedWithZeroDaysValidity( array $dn, string $password ): string {
		return self::selfSigned( $dn, $password, 0 );
	}

	/**
	 * PKCS#12 cujo titular foi assinado por uma CA com DN diferente do
	 * titular — "atestado", não autoassinado.
	 *
	 * @param array<string,string> $subjectDn
	 * @param array<string,string> $issuerDn
	 */
	public static function issuedByCa( array $subjectDn, array $issuerDn, string $password ): string {
		$caKey  = self::newKey( self::KEY_OPTS );
		$caCsr  = self::newCsr( $issuerDn, $caKey );
		$caCert = self::signCsr( $caCsr, null, $caKey, 3650 );

		$leafKey  = self::newKey( self::KEY_OPTS );
		$leafCsr  = self::newCsr( $subjectDn, $leafKey );
		$leafCert = self::signCsr( $leafCsr, $caCert, $caKey, 365 );

		return self::export( $leafCert, $leafKey, $password );
	}

	/**
	 * PKCS#12 autoassinado com uma extensão `subjectAltName` contendo o
	 * valor informado — para provar que o inspetor NÃO lê essa extensão em
	 * busca de documento (V3RCore-Code#29, decisão 2).
	 *
	 * @param array<string,string> $dn
	 */
	public static function selfSignedWithSubjectAltName( array $dn, string $subjectAltNameValue, string $password ): string {
		$cnfPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'v3r-core-san-' . bin2hex( random_bytes( 8 ) ) . '.cnf';

		$cnf = "[req]\ndistinguished_name = req_distinguished_name\nx509_extensions = v3_req\nprompt = no\n\n"
			. "[req_distinguished_name]\n\n"
			. "[v3_req]\n"
			. 'subjectAltName = otherName:1.3.6.1.4.1.311.20.2.3;UTF8:' . $subjectAltNameValue . "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- classe de teste, sem WordPress carregado.
		file_put_contents( $cnfPath, $cnf );

		try {
			$configArgs = array(
				'config'          => $cnfPath,
				'x509_extensions' => 'v3_req',
				'digest_alg'      => 'sha256',
			);

			$key  = self::newKey( self::KEY_OPTS + $configArgs );
			$csr  = self::newCsr( $dn, $key, $configArgs );
			$cert = self::signCsr( $csr, null, $key, 365, $configArgs );

			return self::export( $cert, $key, $password );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- classe de teste, sem WordPress carregado.
			unlink( $cnfPath );
		}
	}

	/** Bytes que não são um PKCS#12 válido — para o caminho "conteúdo ilegível". */
	public static function garbage(): string {
		return "isto nao e um certificado\x00\x01\x02";
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @throws \RuntimeException Falha ao gerar a chave — sinal de ambiente sem suporte,
	 *                            não caso a testar.
	 */
	private static function newKey( array $options ): mixed {
		$key = openssl_pkey_new( $options );

		if ( false === $key ) {
			throw new \RuntimeException( 'CertificateFactory: falha ao gerar chave privada de teste — ' . (string) openssl_error_string() );
		}

		return $key;
	}

	/**
	 * @param array<string,string> $dn
	 * @param mixed                $key
	 * @param array<string,mixed>  $configArgs
	 *
	 * @throws \RuntimeException Falha ao gerar o CSR — sinal de ambiente sem suporte,
	 *                            não caso a testar.
	 */
	private static function newCsr( array $dn, $key, array $configArgs = array() ): mixed {
		$csr = array() === $configArgs ? openssl_csr_new( $dn, $key ) : openssl_csr_new( $dn, $key, $configArgs );

		if ( false === $csr ) {
			throw new \RuntimeException( 'CertificateFactory: falha ao gerar CSR de teste — ' . (string) openssl_error_string() );
		}

		return $csr;
	}

	/**
	 * @param mixed               $csr
	 * @param mixed               $caCert
	 * @param mixed               $caKey
	 * @param int                 $days
	 * @param array<string,mixed> $configArgs
	 *
	 * @throws \RuntimeException Falha ao assinar — sinal de ambiente sem suporte,
	 *                            não caso a testar.
	 */
	private static function signCsr( $csr, $caCert, $caKey, int $days, array $configArgs = array() ): mixed {
		$cert = array() === $configArgs
			? openssl_csr_sign( $csr, $caCert, $caKey, $days )
			: openssl_csr_sign( $csr, $caCert, $caKey, $days, $configArgs );

		if ( false === $cert ) {
			throw new \RuntimeException( 'CertificateFactory: falha ao assinar certificado de teste — ' . (string) openssl_error_string() );
		}

		return $cert;
	}

	/**
	 * @param mixed $cert
	 * @param mixed $key
	 *
	 * @throws \RuntimeException Falha ao exportar o PKCS#12 — sinal de ambiente sem
	 *                            suporte, não caso a testar.
	 */
	private static function export( $cert, $key, string $password ): string {
		$ok = openssl_pkcs12_export( $cert, $out, $key, $password );

		if ( ! $ok || ! is_string( $out ) ) {
			throw new \RuntimeException( 'CertificateFactory: falha ao exportar PKCS#12 de teste — ' . (string) openssl_error_string() );
		}

		return $out;
	}
}
