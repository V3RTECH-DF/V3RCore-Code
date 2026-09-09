<?php
/**
 * Companheiro da `sonda-menu-familia.php`: emite um cookie de sessão real
 * (`nome=valor`) para um administrador, válido por uma hora.
 *
 * Precisa ser um processo SEPARADO da medição — o cookie tem de existir antes
 * de o WordPress carregar na passada que mede, e é isso que faz a identidade
 * ser resolvida no mesmo instante em que seria numa requisição de verdade.
 *
 *   COOKIE=$(php sonda-cookie.php)          # WP_LOGIN=<usuário> para escolher
 *
 * Não escreve nada: só lê o usuário e assina o cookie. O valor é uma
 * credencial de sessão — trate como segredo e não deixe em log.
 */

$wp_path = getenv( 'WP_PATH' ) ?: '/var/www/html';
$login   = (string) getenv( 'WP_LOGIN' );

define( 'WP_USE_THEMES', false );
require $wp_path . '/wp-load.php';

$user = '' !== $login ? get_user_by( 'login', $login ) : null;

if ( ! $user ) {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	$user   = $admins ? $admins[0] : null;
}

if ( ! $user ) {
	fwrite( STDERR, "Nenhum administrador encontrado neste site.\n" );
	exit( 1 );
}

echo LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie( $user->ID, time() + 3600, 'logged_in' );
