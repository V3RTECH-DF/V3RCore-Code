<?php
/**
 * Stubs mínimos das funções do WordPress que o Plugin Update Checker
 * (yahnis-elsts/plugin-update-checker, dependência real via Composer, NÃO
 * a cópia prefixada) chama sozinho ao construir um checker de verdade e ao
 * simular o ciclo completo de leitura do transiente `update_plugins`.
 *
 * Só existem porque os testes de PucBridge (V3RCore-Code#8 e #10)
 * precisam instanciar o PUC de verdade — diferente do resto da suíte
 * (ver UpdateCheckerTest), que evita isso mantendo register() um no-op
 * sem WordPress. Aqui o objetivo é o oposto: provar, com as classes reais
 * do PUC, que um campo sobrevive (ou não) à conversão PluginInfo -> Update
 * -> toWpFormat().
 *
 * Cada stub é o mínimo que faz o PUC não quebrar — não simula
 * comportamento real do WordPress além do que essas chamadas específicas
 * precisam.
 */

declare(strict_types=1);

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

if ( ! isset( $GLOBALS['wp_version'] ) ) {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- stub de teste: o PUC lê `global $wp_version` para filtrar traduções aplicáveis, e não há WordPress real aqui para defini-la.
	$GLOBALS['wp_version'] = '6.7';
}

if ( ! function_exists( 'add_filter' ) ) {
	$GLOBALS['v3r_core_test_puc_filters'] = array();

	function add_filter( string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
		$GLOBALS['v3r_core_test_puc_filters'][ $tag ][] = $callback;

		return true;
	}

	/**
	 * @param string $tag
	 * @param mixed  $value
	 * @param mixed  ...$extraArgs
	 * @return mixed
	 */
	function apply_filters( string $tag, $value, ...$extraArgs ) {
		foreach ( $GLOBALS['v3r_core_test_puc_filters'][ $tag ] ?? array() as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), $extraArgs ) );
		}

		return $value;
	}

	function remove_filter( string $tag, callable $callback, int $priority = 10 ): bool {
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
		return true;
	}

	function remove_action( string $tag, callable $callback, int $priority = 10 ): bool {
		return true;
	}

	/**
	 * @param string $tag
	 * @param mixed  ...$args
	 */
	function do_action( string $tag, ...$args ): void {
	}

	function did_action( string $tag ): int {
		return 0;
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( 'get_plugin_data' ) ) {
	/**
	 * @return array<string, string>
	 */
	function get_plugin_data( string $pluginFile, bool $markup = true, bool $translate = true ): array {
		return array(
			'Name'    => $GLOBALS['v3r_core_test_puc_plugin_name'] ?? 'Plugin de Teste',
			'Version' => $GLOBALS['v3r_core_test_puc_installed_version'] ?? '1.0.0',
		);
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * @param string       $hook
	 * @param array<mixed> $args
	 */
	function wp_next_scheduled( string $hook, array $args = array() ): bool {
		return false;
	}

	/**
	 * @param int          $timestamp
	 * @param string       $recurrence
	 * @param string       $hook
	 * @param array<mixed> $args
	 */
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		return true;
	}

	/**
	 * @param string       $hook
	 * @param array<mixed> $args
	 */
	function wp_clear_scheduled_hook( string $hook, array $args = array() ): bool {
		return true;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return true;
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string {
		return 'pt_BR';
	}

	function get_user_locale(): string {
		return 'pt_BR';
	}

	/**
	 * @return array<int, string>
	 */
	function get_available_languages( string $dir = '' ): array {
		return array();
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}

	function esc_html( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( string $file, callable $callback ): void {
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache(): bool {
		return false;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	$GLOBALS['v3r_core_test_puc_site_options'] = array();

	/**
	 * @param string $name
	 * @param mixed  $defaultValue
	 * @return mixed
	 */
	function get_site_option( string $name, $defaultValue = false ) {
		return $GLOBALS['v3r_core_test_puc_site_options'][ $name ] ?? $defaultValue;
	}

	/**
	 * @param string $name
	 * @param mixed  $value
	 */
	function update_site_option( string $name, $value ): bool {
		$GLOBALS['v3r_core_test_puc_site_options'][ $name ] = $value;

		return true;
	}

	function delete_site_option( string $name ): bool {
		unset( $GLOBALS['v3r_core_test_puc_site_options'][ $name ] );

		return true;
	}
}
