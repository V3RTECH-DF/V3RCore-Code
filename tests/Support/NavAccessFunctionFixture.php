<?php
/**
 * Função de nome fixo, no namespace de teste de `Admin\Nav`, só para
 * `NavigationTest` provar que `Navigation::__construct()` aceita `$access`
 * como uma STRING que nomeia uma função (a forma que `is_callable()`
 * reconhece sem ser um `Closure`) — não só `Closure`/callable array. Arquivo
 * próprio porque a convenção da casa não mistura função com classe no mesmo
 * arquivo (ver tests/bootstrap.php).
 */

declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

if ( ! function_exists( __NAMESPACE__ . '\\v3r_core_test_sempre_permite' ) ) {
	function v3r_core_test_sempre_permite( string $permission ): bool {
		return true;
	}
}
