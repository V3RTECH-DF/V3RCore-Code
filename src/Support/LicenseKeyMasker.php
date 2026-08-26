<?php
declare(strict_types=1);

namespace V3R\Core\Support;

/**
 * Decide como uma chave de licença aparece fora do sistema (log, exceção,
 * serialização para debug) — nunca em texto pleno.
 */
class LicenseKeyMasker {

	/**
	 * Mascara a chave mantendo só os últimos 4 caracteres, no formato
	 * "V3R-XXXX-...-4F2A" citado no contrato: prefixo até o primeiro hífen
	 * preservado (se houver), miolo substituído por "XXXX-...", últimos
	 * 4 caracteres preservados.
	 */
	public static function mask( string $key ): string {
		$key = trim( $key );

		if ( '' === $key ) {
			return '';
		}

		$length = strlen( $key );

		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		$tail = substr( $key, -4 );

		$firstSegment = strtok( $key, '-' );

		if ( false !== $firstSegment && strlen( $firstSegment ) < $length ) {
			return $firstSegment . '-XXXX-...-' . $tail;
		}

		return 'XXXX-...-' . $tail;
	}
}
