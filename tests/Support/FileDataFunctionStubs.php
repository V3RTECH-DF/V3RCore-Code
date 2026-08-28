<?php
/**
 * Stub de `get_file_data()`, fiel o bastante ao WordPress real para testar
 * `Support\PluginVersion::resolve()` sem WordPress carregado
 * (v3rtech-scripts#32) — extrai cabeçalhos de comentário (`Version:` etc.)
 * dos primeiros bytes do arquivo, como a implementação real faz.
 *
 * Disjuntor controlável por global: `$GLOBALS['v3r_core_test_get_file_data_throws']`,
 * quando `true`, faz a chamada lançar `RuntimeException` — necessário para o
 * teste que prova que uma falha na leitura do cabeçalho não derruba
 * `PluginVersion::resolve()` (ele precisa continuar no fallback mesmo se a
 * função do WordPress se comportar mal).
 */

declare(strict_types=1);

if ( ! function_exists( 'get_file_data' ) ) {

	/**
	 * @param string               $file
	 * @param array<string,string> $defaultHeaders
	 * @param string               $context
	 * @return array<string,string>
	 * @throws RuntimeException Quando o disjuntor de teste está armado.
	 */
	function get_file_data( $file, $defaultHeaders, $context = '' ) {
		if ( ! empty( $GLOBALS['v3r_core_test_get_file_data_throws'] ) ) {
			throw new RuntimeException( 'get_file_data() forçado a falhar (disjuntor de teste)' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- stub de teste sem WordPress carregado; lê arquivo local, não URL remota.
		$contents = (string) file_get_contents( $file, false, null, 0, 8192 );

		$result = array();
		foreach ( $defaultHeaders as $field => $label ) {
			$result[ $field ] = '';
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $contents, $m ) ) {
				$result[ $field ] = trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
			}
		}

		return $result;
	}
}
