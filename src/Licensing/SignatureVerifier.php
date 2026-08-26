<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

/**
 * Verifica a assinatura ed25519 de uma resposta do servidor de licenças.
 *
 * Decide se o payload recebido foi realmente assinado pela chave privada do
 * servidor — nunca decide se a licença é válida (isso é do LicenseManager).
 * Uma assinatura inválida NUNCA deve ser tratada como "licença válida": o
 * chamador deve tratar o retorno falso como falha de comunicação, entrando
 * no mesmo caminho de erro de um timeout (ver docs/api-contract.md).
 *
 * Serialização canônica: ver canonicalize() — precisa bater byte a byte com
 * o que o servidor assina, documentado em docs/api-contract.md.
 */
class SignatureVerifier {

	/** @var string */
	private $publicKey;

	/**
	 * @param string $publicKeyBase64 Chave pública ed25519 embutida no plugin (não é segredo).
	 */
	public function __construct( string $publicKeyBase64 ) {
		$this->publicKey = $publicKeyBase64;
	}

	/**
	 * O WordPress inclui sodium_compat desde a versão 5.2, então
	 * sodium_crypto_sign_verify_detached() está sempre disponível em
	 * contexto de plugin, mesmo sem a extensão nativa libsodium — mas fora
	 * do WordPress (ex.: neste pacote isolado, em teste) pode não estar.
	 * Verificação defensiva: nunca assume, sempre confere.
	 */
	public function isAvailable(): bool {
		return function_exists( 'sodium_crypto_sign_verify_detached' );
	}

	/**
	 * @param array<string, mixed> $payload         Payload recebido, tal como o servidor o serializou.
	 * @param string               $signatureBase64 Assinatura em base64 (variante original, com padding).
	 *
	 * @throws \RuntimeException Quando nenhuma implementação de sodium está disponível —
	 *                            este é um impedimento de ambiente, não deve ser confundido
	 *                            com "assinatura inválida".
	 */
	public function verify( array $payload, string $signatureBase64 ): bool {
		if ( ! $this->isAvailable() ) {
			throw new \RuntimeException(
				'sodium_crypto_sign_verify_detached indisponível — verifique sodium_compat/libsodium'
			);
		}

		$signature = base64_decode( $signatureBase64, true );
		$publicKey = base64_decode( $this->publicKey, true );

		if ( false === $signature || false === $publicKey || '' === $signature || '' === $publicKey ) {
			return false;
		}

		$canonical = $this->canonicalize( $payload );

		return sodium_crypto_sign_verify_detached( $signature, $canonical, $publicKey );
	}

	/**
	 * Serialização canônica do payload, para bater byte a byte com o que o
	 * servidor assinou: chaves ordenadas alfabeticamente em qualquer nível
	 * (recursivo, só para arrays associativos — listas mantêm a ordem
	 * original), JSON compacto (sem espaço), barras e unicode não escapados.
	 *
	 * Esta é a peça onde implementações divergentes do cliente e do servidor
	 * fazem a verificação falhar em produção — qualquer mudança aqui precisa
	 * ser espelhada em docs/api-contract.md e no servidor.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function canonicalize( array $payload ): string {
		$ordered = $this->sortRecursively( $payload );

		$json = json_encode( $ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false === $json ? '' : $json;
	}

	/**
	 * @param array<int|string, mixed> $data
	 * @return array<int|string, mixed>
	 */
	private function sortRecursively( array $data ): array {
		$isList = array_keys( $data ) === range( 0, count( $data ) - 1 );

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->sortRecursively( $value );
			}
		}

		if ( ! $isList ) {
			ksort( $data, SORT_STRING );
		}

		return $data;
	}
}
