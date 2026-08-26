<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Fixtures;

use V3R\Core\Licensing\SignatureVerifier;

/**
 * Assina um payload exatamente como o servidor faria (mesma serialização
 * canônica que V3R\Core\Licensing\SignatureVerifier::canonicalize() usa
 * para verificar), usando o par de chaves de teste. Só para fixture.
 */
final class TestSigner {

	/**
	 * @param array<string, mixed> $payload
	 * @return array{payload: array<string, mixed>, signature: string}
	 *
	 * @throws \RuntimeException Se a fixture de chave estiver corrompida (nunca deveria acontecer).
	 */
	public static function sign( array $payload ): array {
		$verifier  = new SignatureVerifier( Ed25519TestKeys::PUBLIC_KEY_BASE64 );
		$canonical = $verifier->canonicalize( $payload );

		$secretKey = base64_decode( Ed25519TestKeys::SECRET_KEY_BASE64, true );

		if ( false === $secretKey || '' === $secretKey ) {
			throw new \RuntimeException( 'Fixture de teste com chave secreta ed25519 inválida.' );
		}

		$signature = base64_encode( sodium_crypto_sign_detached( $canonical, $secretKey ) );

		return array(
			'payload'   => $payload,
			'signature' => $signature,
		);
	}

	/**
	 * Mesmo envelope, mas com a assinatura corrompida — para testar o
	 * caminho de "assinatura inválida nunca é licença válida".
	 *
	 * @param array<string, mixed> $payload
	 * @return array{payload: array<string, mixed>, signature: string}
	 */
	public static function signWithTamperedSignature( array $payload ): array {
		$signed = self::sign( $payload );

		$signed['signature'] = base64_encode( random_bytes( 64 ) );

		return $signed;
	}
}
