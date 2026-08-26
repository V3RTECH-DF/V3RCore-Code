<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

use V3R\Core\Licensing\Transport\HttpTransportInterface;
use V3R\Core\Licensing\Transport\HttpTransportResult;
use V3R\Core\Licensing\Transport\WordPressHttpTransport;

/**
 * Implementação do ApiClientInterface via um HttpTransportInterface
 * (produção: WordPressHttpTransport, sobre wp_remote_post()/wp_remote_get();
 * teste: um transporte falso injetado).
 *
 * Responsabilidades: montar a URL/corpo de cada endpoint conforme
 * docs/api-contract.md, verificar a assinatura de toda resposta que a traz,
 * e traduzir falha de rede/HTTP em ApiException. NUNCA decide política de
 * cache, grace period ou o que fazer com o resultado — isso é do
 * LicenseManager.
 */
class HttpApiClient implements ApiClientInterface {

	/**
	 * Timeout curto de propósito: o servidor de licenças pode estar fora do
	 * ar, e nada nesta biblioteca pode travar o carregamento do admin do
	 * site cliente esperando resposta.
	 */
	public const DEFAULT_TIMEOUT_SECONDS = 8;

	/** @var string */
	private $baseUrl;

	/** @var HttpTransportInterface */
	private $transport;

	/** @var SignatureVerifier */
	private $verifier;

	/** @var int */
	private $timeoutSeconds;

	public function __construct(
		string $baseUrl,
		?HttpTransportInterface $transport = null,
		?SignatureVerifier $verifier = null,
		int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS
	) {
		$this->baseUrl        = rtrim( $baseUrl, '/' );
		$this->transport      = $transport ?? new WordPressHttpTransport();
		$this->verifier       = $verifier ?? new SignatureVerifier( '' );
		$this->timeoutSeconds = $timeoutSeconds;
	}

	/**
	 * Exposto para debug/teste; a fatia 2 monta as URLs de cada endpoint
	 * a partir dela (docs/api-contract.md).
	 */
	public function getBaseUrl(): string {
		return $this->baseUrl;
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @throws ApiException Em falha de comunicação ou erro de negócio do servidor.
	 */
	public function activate( array $payload ): array {
		$result = $this->transport->post( $this->baseUrl . '/activate', $payload, $this->timeoutSeconds );

		return $this->handleSignedResponse( $result );
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @throws ApiException Em falha de comunicação ou erro de negócio do servidor.
	 */
	public function deactivate( array $payload ): array {
		$result = $this->transport->post( $this->baseUrl . '/deactivate', $payload, $this->timeoutSeconds );

		// /deactivate não traz payload assinado (ver docs/api-contract.md §1):
		// não há o que verificar além do envelope de erro genérico.
		return $this->handleUnsignedResponse( $result );
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @throws ApiException Em falha de comunicação ou erro de negócio do servidor.
	 */
	public function validate( array $payload ): array {
		$result = $this->transport->post( $this->baseUrl . '/validate', $payload, $this->timeoutSeconds );

		return $this->handleSignedResponse( $result );
	}

	/**
	 * @param array<string, mixed> $query
	 *
	 * @throws ApiException Em falha de comunicação ou erro de negócio do servidor.
	 */
	public function checkUpdate( array $query ): array {
		$url    = $this->baseUrl . '/update-check?' . http_build_query( $query );
		$result = $this->transport->get( $url, $this->timeoutSeconds );

		return $this->handleSignedResponse( $result );
	}

	/**
	 * Trata uma resposta que, quando bem-sucedida, traz `{ payload, signature }`
	 * — a assinatura é verificada antes de o resultado ser devolvido ao
	 * chamador. Assinatura ausente, malformada ou inválida NUNCA vira
	 * resultado de sucesso: vira ApiException::COMMUNICATION_FAILURE, o
	 * mesmo caminho de um timeout (docs/api-contract.md §4.3/§7).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException Em falha de comunicação ou erro de negócio do servidor.
	 */
	private function handleSignedResponse( HttpTransportResult $result ): array {
		if ( $result->isFailure() ) {
			throw $this->communicationFailure( 'Falha de rede: ' . $result->getFailureMessage() );
		}

		$statusCode = $result->getStatusCode();

		// 5xx é sempre tratado como falha de comunicação, mesmo que o corpo
		// pareça JSON válido — o servidor não está confiável neste estado.
		if ( $statusCode >= 500 ) {
			throw $this->communicationFailure( 'Servidor de licenças respondeu ' . $statusCode );
		}

		$decoded = $this->decodeJsonObject( $result->getBody() );

		if ( null === $decoded ) {
			throw $this->communicationFailure( 'Resposta com JSON malformado' );
		}

		if ( $statusCode >= 200 && $statusCode < 300 ) {
			return $this->verifySignedPayload( $decoded );
		}

		throw $this->businessErrorFromBody( $decoded, $statusCode );
	}

	/**
	 * Trata uma resposta sem payload assinado (só `/deactivate`).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException Em falha de comunicação ou erro de negócio do servidor.
	 */
	private function handleUnsignedResponse( HttpTransportResult $result ): array {
		if ( $result->isFailure() ) {
			throw $this->communicationFailure( 'Falha de rede: ' . $result->getFailureMessage() );
		}

		$statusCode = $result->getStatusCode();

		if ( $statusCode >= 500 ) {
			throw $this->communicationFailure( 'Servidor de licenças respondeu ' . $statusCode );
		}

		$decoded = $this->decodeJsonObject( $result->getBody() );

		if ( null === $decoded ) {
			throw $this->communicationFailure( 'Resposta com JSON malformado' );
		}

		if ( $statusCode >= 200 && $statusCode < 300 ) {
			return $decoded;
		}

		throw $this->businessErrorFromBody( $decoded, $statusCode );
	}

	/**
	 * @param array<string, mixed> $decoded Envelope { payload, signature } já decodificado.
	 * @return array<string, mixed>
	 *
	 * @throws ApiException Quando payload/signature estão ausentes ou a assinatura falha.
	 */
	private function verifySignedPayload( array $decoded ): array {
		if ( ! isset( $decoded['payload'] ) || ! is_array( $decoded['payload'] ) || ! isset( $decoded['signature'] ) || ! is_string( $decoded['signature'] ) ) {
			throw $this->communicationFailure( 'Resposta sem payload/signature no formato esperado' );
		}

		if ( ! $this->verifySignatureDefensively( $decoded['payload'], $decoded['signature'] ) ) {
			throw $this->communicationFailure( 'Assinatura da resposta inválida ou ausente' );
		}

		return $decoded;
	}

	/**
	 * Chama SignatureVerifier::verify() protegendo contra o caso em que a
	 * implementação de sodium (nativa ou sodium_compat) lança uma exceção
	 * em vez de devolver falso para bytes de assinatura semanticamente
	 * inválidos (ex.: assinatura corrompida/aleatória do mesmo tamanho de
	 * uma válida) — isso acontece de verdade com sodium_compat. Trata como
	 * assinatura inválida, nunca deixa a exceção escapar como erro fatal.
	 *
	 * \RuntimeException continua propagando: é o sinal deliberado de
	 * "sodium indisponível" (impedimento de ambiente), distinto de
	 * assinatura inválida (docs/api-contract.md §7, última linha).
	 *
	 * @param array<string, mixed> $payload
	 * @param string               $signature
	 *
	 * @throws \RuntimeException Repassada quando sodium está indisponível (impedimento de ambiente).
	 */
	private function verifySignatureDefensively( array $payload, string $signature ): bool {
		try {
			return $this->verifier->verify( $payload, $signature );
		} catch ( \RuntimeException $exception ) {
			throw $exception;
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	/**
	 * @param array<string, mixed> $decoded Corpo de erro { code, message, data: { status } }.
	 *
	 * @throws ApiException Sempre — quem chama decide o que fazer com o código.
	 */
	private function businessErrorFromBody( array $decoded, int $httpStatus ): ApiException {
		if ( ! isset( $decoded['code'] ) || ! is_string( $decoded['code'] ) ) {
			// Corpo de erro fora do formato do contrato: não sabemos o que
			// significa, então tratamos como falha de comunicação — nunca
			// inventamos um código de negócio a partir de um corpo estranho.
			return $this->communicationFailure( 'Corpo de erro fora do formato esperado (HTTP ' . $httpStatus . ')' );
		}

		$message = isset( $decoded['message'] ) && is_string( $decoded['message'] ) ? $decoded['message'] : $decoded['code'];

		return new ApiException( $decoded['code'], $message, $httpStatus );
	}

	private function communicationFailure( string $message ): ApiException {
		return new ApiException( ApiException::COMMUNICATION_FAILURE, $message, 0 );
	}

	/**
	 * Decodifica um corpo JSON esperado como objeto associativo. Devolve
	 * null para qualquer coisa que não seja um objeto JSON válido — corpo
	 * vazio, JSON quebrado, ou um JSON válido que não é objeto (ex.: `"ok"`,
	 * `[1,2,3]`) contam igualmente como malformado para este protocolo.
	 *
	 * @return array<string, mixed>|null
	 */
	private function decodeJsonObject( string $body ): ?array {
		if ( '' === trim( $body ) ) {
			return null;
		}

		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return null;
		}

		return $decoded;
	}
}
