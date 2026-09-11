<?php
/**
 * Sonda do agrupamento do menu da família (V3RCore-Code#25/#40).
 *
 * Imprime a coluna do painel COMO O WORDPRESS A ENTREGA — depois dos filtros
 * `custom_menu_order`/`menu_order`, que é o único momento em que a
 * contiguidade se decide — e, ao lado, quem anunciou e se apareceu.
 *
 * USO — um processo só, somente leitura:
 *
 *   docker exec -e V3R_PROBE_LOGIN=<usuario> <ctr> php /caminho/sonda-menu-familia.php
 *
 * Fora de Docker é igual, sem o `docker exec`, ajustando WP_PATH. Sem
 * V3R_PROBE_LOGIN, usa o primeiro administrador. Prefira o usuário de teste
 * do ambiente, nunca a conta pessoal de alguém.
 *
 * ⚠️ NÃO EMITE CREDENCIAL NENHUMA. A sonda não gera cookie, não cria sessão,
 * não grava token e não escreve nada no banco. Ela diz ao WordPress, dentro
 * do próprio processo de medição, por qual usuário ler — o mesmo que o
 * `--user` do wp-cli faz, só que no momento certo (ver armadilha 2). Nada
 * sai do processo que permita a outro alguém agir como esse usuário.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * AS DUAS ARMADILHAS QUE ESTA SONDA EXISTE PARA NÃO CAIR
 *
 * 1. CONTEXTO DE PAINEL. Sem `WP_ADMIN` definido ANTES de carregar o
 *    WordPress, quem registra menu dentro de `if ( is_admin() )` nunca se
 *    registra — e some da leitura. O sintoma é idêntico a "não adotou".
 *
 * 2. IDENTIDADE RESOLVIDA CEDO. A navegação da casa captura o usuário
 *    corrente em `plugins_loaded`. O `--user` do wp-cli e o
 *    `wp_set_current_user()` depois do carregamento chegam TARDE: a entrada
 *    cai no balde de "sem permissão" e aparece como "anuncia e não aparece
 *    na coluna". Aqui o filtro `determine_current_user` é semeado ANTES de o
 *    WordPress carregar — é o mecanismo que o próprio WordPress documenta
 *    para pré-registrar filtros —, então a identidade já está resolvida
 *    quando os plugins sobem.
 *
 *    Se a identidade não resolver, a sonda ABORTA em vez de medir: medir sem
 *    identidade devolve número errado com cara de certo.
 *
 * ⚠️ A REGRA DE LEITURA, que é o que custou caro: sintoma reproduzido num
 *    sentido só é observação, não medição — não distingue defeito do produto
 *    de defeito do instrumento. E "ninguém adotou" é indistinguível de "a
 *    cópia instalada é velha demais para anunciar": confira a versão da
 *    biblioteca embutida em cada plugin antes de acusar qualquer produto.
 * ────────────────────────────────────────────────────────────────────────────
 */

$wp_path = getenv( 'WP_PATH' ) ?: '/var/www/html';
$login   = (string) getenv( 'V3R_PROBE_LOGIN' );

define( 'WP_USE_THEMES', false );
define( 'WP_ADMIN', true );                        // armadilha 1

$_SERVER['REQUEST_URI']    = '/wp-admin/index.php';
$_SERVER['SCRIPT_NAME']    = '/wp-admin/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['pagenow']        = 'index.php';

// Armadilha 2: filtro semeado antes de o WordPress carregar. O plugin.php
// converte este array em WP_Hook ao subir, e o filtro já vale em
// plugins_loaded. O callback só roda depois de o banco estar disponível.
$GLOBALS['wp_filter']['determine_current_user'][ PHP_INT_MAX ][] = array(
	'function'      => static function ( $user_id ) use ( $login ) {
		if ( '' !== $login ) {
			$user = get_user_by( 'login', $login );
			return $user ? (int) $user->ID : 0;
		}

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		return $admins ? (int) $admins[0] : 0;
	},
	'accepted_args' => 1,
);

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
	echo "(ninguém anunciou — ou nenhuma cópia instalada anuncia)\n";
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
