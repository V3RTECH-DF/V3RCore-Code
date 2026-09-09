<?php
/**
 * Sonda do agrupamento do menu da família (V3RCore-Code#25/#40).
 *
 * Imprime a coluna do painel COMO O WORDPRESS A ENTREGA — depois dos filtros
 * `custom_menu_order`/`menu_order`, que é o único momento em que a
 * contiguidade se decide — e, ao lado, quem anunciou e se apareceu.
 *
 * USO — dois comandos, porque o cookie precisa existir ANTES de o WordPress
 * carregar na passada que mede (é justamente o ponto da armadilha 2):
 *
 *   COOKIE=$(docker exec <ctr> php /tmp/sonda-cookie.php)
 *   docker exec -e V3R_PROBE_COOKIE="$COOKIE" <ctr> php /tmp/sonda-menu-familia.php
 *
 * Fora de Docker é igual, sem o `docker exec`, ajustando WP_PATH.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * AS DUAS ARMADILHAS QUE ESTA SONDA EXISTE PARA NÃO CAIR
 *
 * 1. CONTEXTO DE PAINEL. Sem `define( 'WP_ADMIN', true )` ANTES do wp-load,
 *    quem registra menu dentro de `if ( is_admin() )` nunca se registra — e
 *    some da leitura. O sintoma é idêntico a "aquele produto não adotou".
 *
 * 2. IDENTIDADE RESOLVIDA CEDO. Esta sonda NÃO chama `wp_set_current_user()`
 *    depois do wp-load, e NÃO usa o `--user` do wp-cli: os dois chegam tarde
 *    demais. Quem captura o usuário corrente em `plugins_loaded` encontra
 *    ninguém, e a entrada do produto cai no balde de "sem permissão",
 *    aparecendo como "anuncia e não aparece na coluna".
 *
 *    Em vez disso, ela recebe um COOKIE DE SESSÃO REAL e deixa o WordPress
 *    resolver a identidade no mesmo instante em que resolveria numa
 *    requisição de verdade. É o que dispensa o medidor de lembrar da
 *    armadilha — e a razão de a sonda ABORTAR se a identidade não resolver,
 *    em vez de medir e devolver número errado com cara de certo.
 *
 * ⚠️ A REGRA DE LEITURA, que é o que custou caro: sintoma reproduzido num
 *    sentido só é observação, não medição — não distingue defeito do produto
 *    de defeito do instrumento. Antes de acusar qualquer produto, desligue o
 *    anúncio dele e leia de novo. Se a coluna não mudar, o problema é aqui.
 * ────────────────────────────────────────────────────────────────────────────
 */

$wp_path = getenv( 'WP_PATH' ) ?: '/var/www/html';
$cookie  = (string) getenv( 'V3R_PROBE_COOKIE' );

if ( '' === $cookie || false === strpos( $cookie, '=' ) ) {
	fwrite( STDERR, "Falta o cookie. Gere com sonda-cookie.php e passe em V3R_PROBE_COOKIE.\n" );
	exit( 1 );
}

define( 'WP_USE_THEMES', false );
define( 'WP_ADMIN', true );                        // armadilha 1

$_SERVER['REQUEST_URI']    = '/wp-admin/index.php';
$_SERVER['SCRIPT_NAME']    = '/wp-admin/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['pagenow']        = 'index.php';

list( $name, $value ) = explode( '=', $cookie, 2 );
$_COOKIE[ $name ]     = $value;                    // armadilha 2

require $wp_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$current = wp_get_current_user();

if ( ! $current->ID ) {
	fwrite( STDERR, "Identidade não resolveu — a medição seria inválida. Abortando.\n" );
	exit( 1 );
}

printf( "usuário: %s (id %d)\n\n", $current->user_login, $current->ID );

require ABSPATH . 'wp-admin/menu.php';             // aplica custom_menu_order/menu_order

global $menu;

echo "── coluna do painel, já reordenada ──\n";

$i = 0;

foreach ( $menu as $item ) {
	if ( empty( $item[0] ) ) {
		continue;
	}

	$label = trim( wp_strip_all_tags( $item[0] ) );

	printf( "%3d  %-30s %s\n", ++$i, '' === $label ? '(separador)' : $label, $item[2] );
}

$announced = $GLOBALS['v3r_nav_family_menu_entries'] ?? array();

printf( "\n── anunciados (%d) ──\n", is_array( $announced ) ? count( $announced ) : 0 );

if ( ! $announced ) {
	echo "(ninguém anunciou — ou nenhuma cópia instalada tem MenuOrder)\n";
	exit( 0 );
}

$slugs_na_coluna = array();

foreach ( $menu as $item ) {
	if ( isset( $item[2] ) ) {
		$slugs_na_coluna[] = $item[2];
	}
}

foreach ( (array) $announced as $slug => $data ) {
	printf(
		"%-22s %-9s %-24s %s\n",
		$slug,
		$data['family'] ?? '?',
		$data['title'] ?? '?',
		in_array( $slug, $slugs_na_coluna, true ) ? 'na coluna' : '⚠ ANUNCIOU E NÃO ESTÁ NA COLUNA'
	);
}
