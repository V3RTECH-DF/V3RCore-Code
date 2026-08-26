<?php
declare(strict_types=1);

namespace V3R\Core\Licensing\Transport;

/**
 * Implementação de produção do transporte HTTP, via wp_remote_post()/
 * wp_remote_get(). Única classe desta biblioteca que chama essas funções —
 * é o que permite testar o HttpApiClient inteiro sem WordPress carregado.
 */
final class WordPressHttpTransport implements HttpTransportInterface {

	/**
	 * @param string               $url
	 * @param array<string, mixed> $body
	 * @param int                  $timeout
	 */
	public function post( string $url, array $body, int $timeout ): HttpTransportResult {
		$encoded = wp_json_encode( $body );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => false === $encoded ? '' : $encoded,
			)
		);

		return $this->normalize( $response );
	}

	public function get( string $url, int $timeout ): HttpTransportResult {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $timeout,
			)
		);

		return $this->normalize( $response );
	}

	/**
	 * @param array<string, mixed>|\WP_Error $response
	 */
	private function normalize( $response ): HttpTransportResult {
		if ( is_wp_error( $response ) ) {
			return HttpTransportResult::failure( $response->get_error_message() );
		}

		$statusCode = (int) wp_remote_retrieve_response_code( $response );
		$body       = (string) wp_remote_retrieve_body( $response );

		return HttpTransportResult::success( $statusCode, $body );
	}
}
