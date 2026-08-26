<?php
declare(strict_types=1);

namespace V3R\Core\Support;

/**
 * Decide se e como uma mensagem de diagnóstico é registrada, sem nunca
 * expor dado sensível (chave de licença, token) no destino do log.
 *
 * Log é opcional: por padrão, silencioso. Um plugin consumidor liga o log
 * passando um callable de destino (ex.: error_log, um logger próprio).
 */
class Logger {

	/**
	 * @var callable|null
	 */
	private $sink;

	/**
	 * @param callable|null $sink Recebe (string $level, string $message, array $context).
	 *                            Nulo desativa o log (comportamento padrão).
	 */
	public function __construct( ?callable $sink = null ) {
		$this->sink = $sink;
	}

	/**
	 * @param string               $message
	 * @param array<string, mixed> $context
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * @param string               $message
	 * @param array<string, mixed> $context
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * @param string               $message
	 * @param array<string, mixed> $context
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * @param string               $level
	 * @param string               $message
	 * @param array<string, mixed> $context
	 */
	private function log( string $level, string $message, array $context ): void {
		if ( null === $this->sink ) {
			return;
		}

		( $this->sink )( $level, $message, $this->sanitizeContext( $context ) );
	}

	/**
	 * Mascara qualquer campo de contexto cujo nome sugira dado sensível,
	 * para que nenhuma chave de licença ou token chegue ao destino do log
	 * em texto pleno, mesmo por engano de quem chama.
	 *
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	private function sanitizeContext( array $context ): array {
		$sensitiveKeys = array( 'license_key', 'key', 'token', 'secret', 'password' );

		foreach ( $context as $field => $value ) {
			if ( is_string( $field ) && in_array( strtolower( $field ), $sensitiveKeys, true ) && is_string( $value ) ) {
				$context[ $field ] = LicenseKeyMasker::mask( $value );
			}
		}

		return $context;
	}
}
