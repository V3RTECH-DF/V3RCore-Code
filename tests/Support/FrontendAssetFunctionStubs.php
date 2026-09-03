<?php
/**
 * Stubs mínimos de `plugins_url()` e `wp_enqueue_script()`, para testar
 * `Frontend\AssetLocator` sem WordPress carregado (V3RCore-Code#23).
 *
 * `plugins_url( $path, $file )` de verdade devolve a URL do diretório que
 * contém `$file`, com `$path` acrescentado. Aqui a raiz de plugins é uma
 * base fixa, e o caminho do arquivo é recortado a partir do marcador
 * `/plugins/` — o suficiente para provar que a URL acompanha o caminho real
 * do arquivo, que é a decisão que a classe materializa.
 */

declare(strict_types=1);

if ( ! function_exists( 'plugins_url' ) ) {
	define( 'V3R_CORE_TEST_PLUGINS_URL', 'https://exemplo.test/wp-content/plugins' );

	function plugins_url( string $path = '', string $pluginFile = '' ): string {
		$directory = '' === $pluginFile ? '' : dirname( $pluginFile );
		$marker    = strpos( $directory, '/plugins/' );
		$relative  = false === $marker ? ltrim( $directory, '/' ) : substr( $directory, $marker + 9 );

		return rtrim( V3R_CORE_TEST_PLUGINS_URL . '/' . $relative, '/' ) . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	$GLOBALS['v3r_core_test_enqueued_scripts'] = array();

	/**
	 * @param string      $handle
	 * @param string      $src
	 * @param string[]    $dependencies
	 * @param string|null $version
	 * @param bool        $inFooter
	 */
	function wp_enqueue_script( string $handle, string $src = '', array $dependencies = array(), $version = null, bool $inFooter = false ): void {
		$GLOBALS['v3r_core_test_enqueued_scripts'][] = array(
			'handle'       => $handle,
			'src'          => $src,
			'dependencies' => $dependencies,
			'version'      => $version,
			'inFooter'     => $inFooter,
		);
	}
}
