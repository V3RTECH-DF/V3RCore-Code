<?php
/**
 * Sonda de acesso por pessoa — mede, para UM usuário, o que ele alcança num
 * plugin da família. Serve para validar migração para a camada de navegação
 * (antes × depois) sem depender de login no navegador.
 *
 * USO — um processo por pessoa, somente leitura:
 *
 *   docker cp bin/sonda-acesso-por-pessoa.php dev-wp:/tmp/
 *   docker exec \
 *     -e V3R_PROBE_LOGIN=<login|__anon__> \
 *     -e V3R_PROBE_PLUGIN=<pasta do plugin>      (ex.: v3revent)
 *     -e V3R_PROBE_SLUG=<slug do menu>           (ex.: v3revent)
 *     -e V3R_PROBE_NS=<prefixos REST>            (ex.: v3revent/v1,v3r-core/v1/v3revent)
 *     [-e V3R_PROBE_SCREENS=<telas>]             (slugs para v3r_nav_<tela>)
 *     [-e V3R_PROBE_CAPS=<capacidades extras>]
 *     [-e V3R_PROBE_PAGE=<page da requisição>]   (padrão: o slug do menu)
 *     [-e V3R_PROBE_NAV_CLASS=<FQCN do NavigationBootstrap>]
 *     [-e V3R_PROBE_SURFACES=<superfícies>]      (vazio = mapa sem superfície)
 *     [-e V3R_PROBE_ADAPTER=<arquivo PHP>]       (seções próprias do produto)
 *     [-e V3R_PROBE_SKIP=<regex de rota>]        (padrão: #/public/|/external/#)
 *     dev-wp php /tmp/sonda-acesso-por-pessoa.php
 *
 * O adaptador é um arquivo que devolve `array( 'Título' => callable )`, e cada
 * callable devolve `array( rótulo => bool|string )`. Um adaptador pode trazer a
 * seção 'camada' para substituir a leitura genérica do NavigationBootstrap
 * (quando `instance()` exige argumentos).
 *
 * ⚠️ NÃO EMITE CREDENCIAL. Identidade semeada em `determine_current_user` antes
 * do wp-load (mesmo mecanismo de bin/sonda-menu-familia.php). O nonce REST é
 * gerado dentro do processo e nunca impresso. `permission_callback` é chamado
 * sem executar o handler; rotas públicas e de chave externa são puladas por
 * padrão, porque podem carregar limitador de tentativas.
 *
 * ⚠️ ACESSO DIRETO REPRODUZ O wp-admin/admin.php, NÃO UM ATALHO. Sem
 * `$plugin_page` definido, `user_can_access_admin_page()` responde SIM para
 * qualquer um — a primeira versão desta sonda (V3RHelp-Code#88) caiu nisso e
 * gerou uma correção desnecessária. Aqui: `$plugin_page` antes do menu, recusa
 * do `wp_die` capturada, `admin_page_access_denied` real, e só os callbacks de
 * `admin_init` que REDIRECIONAM (regex V3R_PROBE_ADMIN_INIT) — o resto do
 * `admin_init` tem efeito colateral e fica de fora. Redirecionamento seguido de
 * `exit` é relatado no desligamento do processo.
 *
 * Saída determinística, para `diff` antes × depois.
 */

$v3rp_wp_path  = getenv( 'WP_PATH' ) ?: '/var/www/html';
$v3rp_login    = (string) getenv( 'V3R_PROBE_LOGIN' );
$v3rp_slug     = (string) getenv( 'V3R_PROBE_SLUG' );
$v3rp_plugin   = (string) getenv( 'V3R_PROBE_PLUGIN' );
$v3rp_anon     = '__anon__' === $v3rp_login;
$v3rp_csv      = static function ( $name ) {
	$v = (string) getenv( $name );
	return '' === $v ? array() : array_map( 'trim', explode( ',', $v ) );
};

if ( '' === $v3rp_login || '' === $v3rp_slug ) {
	fwrite( STDERR, "Defina V3R_PROBE_LOGIN e V3R_PROBE_SLUG.\n" );
	exit( 1 );
}

$v3rp_page = (string) ( getenv( 'V3R_PROBE_PAGE' ) ?: $v3rp_slug );

define( 'WP_USE_THEMES', false );
define( 'WP_ADMIN', true );

$_SERVER['REQUEST_URI']     = '/wp-admin/admin.php?page=' . rawurlencode( $v3rp_page );
$_SERVER['SCRIPT_NAME']     = '/wp-admin/admin.php';
// O wp-includes/vars.php recalcula $pagenow a partir do PHP_SELF; em CLI ele é
// o caminho da sonda, e aí get_admin_page_parent() erra a página-mãe e recusa
// até administrador em submenu legítimo (medido no V3RProp).
$_SERVER['PHP_SELF']        = '/wp-admin/admin.php';
$_SERVER['SCRIPT_FILENAME'] = $v3rp_wp_path . '/wp-admin/admin.php';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['HTTP_HOST']       = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_GET['page']               = $v3rp_page;
$_REQUEST['page']           = $v3rp_page;
$GLOBALS['pagenow']         = 'admin.php';

$GLOBALS['wp_filter']['determine_current_user'][ PHP_INT_MAX ][] = array(
	'function'      => static function ( $user_id ) use ( $v3rp_login, $v3rp_anon ) {
		if ( $v3rp_anon ) {
			return 0;
		}
		$user = get_user_by( 'login', $v3rp_login );
		return $user ? (int) $user->ID : 0;
	},
	'accepted_args' => 1,
);

require $v3rp_wp_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$GLOBALS['pagenow'] = 'admin.php';
$v3rp_current = wp_get_current_user();

if ( ! $v3rp_anon && ! $v3rp_current->ID ) {
	fwrite( STDERR, "Identidade não resolveu — medição inválida. Abortando.\n" );
	exit( 1 );
}

$v3rp_fmt = static function ( $v ) {
	if ( is_bool( $v ) ) {
		return $v ? 'SIM' : 'nao';
	}
	return is_scalar( $v ) || null === $v ? var_export( $v, true ) : wp_json_encode( $v );
};
$v3rp_row = static function ( $label, $v ) use ( $v3rp_fmt ) {
	printf( "  %-44s %s\n", $label, $v3rp_fmt( $v ) );
};

// ── identidade e versões ──
$v3rp_lib = '?';
$v3rp_ver = '?';
if ( '' !== $v3rp_plugin ) {
	foreach ( glob( WP_PLUGIN_DIR . '/' . $v3rp_plugin . '/{,src/,plugin/}vendor-prefixed/v3rtech/v3r-core/src/Version.php', GLOB_BRACE ) ?: array() as $vf ) {
		if ( preg_match( "/CURRENT\s*=\s*'([^']+)'/", (string) file_get_contents( $vf ), $m ) ) {
			$v3rp_lib = $m[1];
		}
	}
	foreach ( get_plugins( '/' . $v3rp_plugin ) as $data ) {
		$v3rp_ver = $data['Version'];
	}
}
printf( "identidade: %s (id %d) papeis=[%s]\n", $v3rp_anon ? '(anonimo)' : $v3rp_current->user_login, $v3rp_current->ID, implode( ',', (array) $v3rp_current->roles ) );
printf( "plugin %s %s | v3r-core embutida %s\n", $v3rp_plugin, $v3rp_ver, $v3rp_lib );

// ── capacidades do WordPress e da camada ──
echo "\n── capacidades do WordPress / camada ──\n";
$v3rp_caps = array_merge(
	array( 'read', 'edit_posts', 'manage_options', 'manage_woocommerce', 'view_admin_dashboard', 'v3r_nav_root_' . $v3rp_slug, 'v3r_nav_sonda_inexistente' ),
	array_map(
		static function ( $s ) {
			return 'v3r_nav_' . $s;
		},
		$v3rp_csv( 'V3R_PROBE_SCREENS' )
	),
	$v3rp_csv( 'V3R_PROBE_CAPS' )
);
foreach ( $v3rp_caps as $c ) {
	$v3rp_row( $c, current_user_can( $c ) );
}
$v3rp_has_wc_cap = current_user_can( 'edit_posts' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'view_admin_dashboard' );
$v3rp_row( 'WooCommerce EXPULSA do painel', class_exists( 'WooCommerce' ) && ! $v3rp_anon && (bool) apply_filters( 'woocommerce_prevent_admin_access', apply_filters( 'woocommerce_disable_admin_bar', true ) && ! $v3rp_has_wc_cap ) );

// ── seções do adaptador ──
$v3rp_adapter = array();
$v3rp_adapter_file = (string) getenv( 'V3R_PROBE_ADAPTER' );
if ( '' !== $v3rp_adapter_file ) {
	$v3rp_adapter = (array) require $v3rp_adapter_file;
}
foreach ( $v3rp_adapter as $title => $fn ) {
	if ( 'camada' === $title ) {
		continue;
	}
	echo "\n── {$title} ──\n";
	foreach ( (array) call_user_func( $fn ) as $label => $v ) {
		$v3rp_row( $label, $v );
	}
}

// ── camada de navegação: mapa de acesso e árvore ──
echo "\n── camada: telas visíveis (accessMap) ──\n";
$v3rp_navigation = null;
if ( isset( $v3rp_adapter['camada'] ) ) {
	$v3rp_navigation = call_user_func( $v3rp_adapter['camada'] );
} else {
	$v3rp_nav_class = (string) getenv( 'V3R_PROBE_NAV_CLASS' );
	if ( '' !== $v3rp_nav_class && class_exists( $v3rp_nav_class ) && method_exists( $v3rp_nav_class, 'instance' ) ) {
		$rm = new ReflectionMethod( $v3rp_nav_class, 'instance' );
		if ( 0 === $rm->getNumberOfRequiredParameters() ) {
			$v3rp_navigation = $v3rp_nav_class::instance();
		} else {
			echo "  (instance() exige argumentos — use a seção 'camada' do adaptador)\n";
		}
	}
}
if ( $v3rp_navigation ) {
	$v3rp_surfaces = $v3rp_csv( 'V3R_PROBE_SURFACES' ) ?: array( '' );
	foreach ( $v3rp_surfaces as $surface ) {
		$map = '' === $surface ? $v3rp_navigation->accessMap() : $v3rp_navigation->accessMap( $surface );
		ksort( $map );
		foreach ( $map as $s => $v ) {
			$v3rp_row( ( '' === $surface ? '' : $surface . ': ' ) . 'tela ' . $s, $v );
		}
		$tree = '' === $surface ? $v3rp_navigation->tree() : $v3rp_navigation->tree( $surface );
		printf( "  %sarvore %s\n", '' === $surface ? '' : $surface . ': ', wp_json_encode( $tree ) );
	}
} else {
	echo "  (camada ausente nesta versão)\n";
}

// ── REST: permission_callback por rota (handler NÃO executado) ──
echo "\n── REST (permission_callback) ──\n";
$v3rp_prefixes = $v3rp_csv( 'V3R_PROBE_NS' );
$v3rp_skip     = (string) ( getenv( 'V3R_PROBE_SKIP' ) ?: '#/public/|/external/#' );
$v3rp_nonce    = wp_create_nonce( 'wp_rest' );
// Pular por callback (V3R_PROBE_SKIP_CB, regex sobre "Classe::metodo" ou, para
// closure, a classe onde ela foi criada): o mesmo caminho pode existir numa
// família de rotas sem efeito e noutra com limitador.
$v3rp_skip_cb  = (string) getenv( 'V3R_PROBE_SKIP_CB' );
$v3rp_cb_name  = static function ( $f ) {
	if ( is_string( $f ) ) {
		return $f;
	}
	if ( is_array( $f ) ) {
		return ( is_object( $f[0] ) ? get_class( $f[0] ) : (string) $f[0] ) . '::' . $f[1];
	}
	if ( $f instanceof Closure ) {
		$scope = ( new ReflectionFunction( $f ) )->getClosureScopeClass();
		return 'closure@' . ( $scope ? $scope->getName() : '' );
	}
	return '';
};
$v3rp_lines    = array();
foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
	$hit = false;
	foreach ( $v3rp_prefixes as $p ) {
		if ( 0 === strpos( $route, '/' . ltrim( $p, '/' ) ) ) {
			$hit = true;
		}
	}
	if ( ! $hit ) {
		continue;
	}
	foreach ( $handlers as $h ) {
		$methods = implode( ',', array_keys( array_filter( (array) $h['methods'] ) ) );
		if ( preg_match( $v3rp_skip, $route ) || ( '' !== $v3rp_skip_cb && preg_match( $v3rp_skip_cb, $v3rp_cb_name( $h['permission_callback'] ?? null ) ) ) ) {
			$v3rp_lines[] = sprintf( '  %-8s %-66s PULADA', $methods, $route );
			continue;
		}
		$req = new WP_REST_Request( explode( ',', $methods )[0], $route );
		$req->set_header( 'X-WP-Nonce', $v3rp_nonce );
		$res = isset( $h['permission_callback'] ) && is_callable( $h['permission_callback'] ) ? call_user_func( $h['permission_callback'], $req ) : true;
		$out = true === $res ? 'OK' : ( is_wp_error( $res ) ? 'NEGA ' . $res->get_error_code() : ( $res ? 'OK' : 'NEGA false' ) );
		$v3rp_lines[] = sprintf( '  %-8s %-66s %s', $methods, $route, $out );
	}
}
sort( $v3rp_lines );
echo $v3rp_lines ? implode( "\n", $v3rp_lines ) . "\n" : "  (nenhuma rota nos prefixos)\n";

// ── acesso direto ao endereço, como o wp-admin/admin.php faz ──
echo "\n── acesso direto ?page={$v3rp_page} ──\n";
if ( $v3rp_anon ) {
	echo "  (anonimo: o wp-admin manda para o login antes de qualquer checagem)\n";
	exit( 0 );
}

$GLOBALS['v3r_probe_redirect'] = null;
$GLOBALS['v3r_probe_done']     = false;
add_filter(
	'wp_redirect',
	static function ( $location ) {
		$GLOBALS['v3r_probe_redirect'] = $location;
		// Registrado; o cabeçalho não sai (a sonda já imprimiu, e em CLI ele não
		// leva a lugar nenhum). O `exit` de quem redireciona é relatado no
		// desligamento do processo.
		return false;
	},
	PHP_INT_MAX
);
register_shutdown_function(
	static function () {
		if ( ! $GLOBALS['v3r_probe_done'] && null !== $GLOBALS['v3r_probe_redirect'] ) {
			printf( "  resultado: REDIRECIONA para %s\n", preg_replace( '#^https?://[^/]+#', '', (string) $GLOBALS['v3r_probe_redirect'] ) );
		}
	}
);
add_filter(
	'wp_die_handler',
	static function () {
		return static function ( $message = '' ) {
			$text = is_wp_error( $message ) ? $message->get_error_message() : (string) $message;
			throw new RuntimeException( mb_substr( trim( wp_strip_all_tags( $text ) ), 0, 90 ) );
		};
	}
);

global $plugin_page;
$plugin_page = plugin_basename( $v3rp_page );

$v3rp_recusado = false;
try {
	require ABSPATH . 'wp-admin/menu.php';
} catch ( RuntimeException $e ) {
	$v3rp_recusado = $e->getMessage();
}

global $menu, $submenu;
$v3rp_in = false;
foreach ( (array) $menu as $item ) {
	if ( isset( $item[2] ) && $v3rp_slug === $item[2] ) {
		$v3rp_in = true;
	}
}
$v3rp_row( "entrada {$v3rp_slug} na coluna", $v3rp_in );
$v3rp_subs = array();
foreach ( (array) ( $submenu[ $v3rp_slug ] ?? array() ) as $s ) {
	$v3rp_subs[] = $s[2];
}
printf( "  %-44s %s\n", 'submenus visíveis', $v3rp_subs ? implode( ' ', $v3rp_subs ) : '(nenhum)' );

if ( $v3rp_recusado ) {
	$GLOBALS['v3r_probe_done'] = true;
	printf( "  resultado: RECUSADO pelo WordPress\n  mensagem: %s\n", $v3rp_recusado );
	// Diagnóstico da recusa: de onde o WordPress tirou a página-mãe e se o
	// gancho da página estava registrado.
	$v3rp_parent = get_admin_page_parent();
	$v3rp_hname  = get_plugin_page_hookname( $plugin_page, $v3rp_parent );
	printf(
		"  diagnostico: pagenow=" . $GLOBALS['pagenow'] . " mae=%s gancho=%s registrado=%s submenu_nopriv=%s menu_nopriv=%s\n",
		var_export( $v3rp_parent, true ),
		$v3rp_hname,
		isset( $GLOBALS['_registered_pages'][ $v3rp_hname ] ) ? 'SIM' : 'nao',
		wp_json_encode( array_keys( (array) ( $GLOBALS['_wp_submenu_nopriv'][ $v3rp_parent ] ?? array() ) ) ),
		isset( $GLOBALS['_wp_menu_nopriv'][ $plugin_page ] ) ? 'SIM' : 'nao'
	);
	exit( 0 );
}

// Só os callbacks de admin_init que redirecionam (LegacyRedirects, expulsão do
// WooCommerce, redirecionamento próprio do produto).
$v3rp_pattern = (string) ( getenv( 'V3R_PROBE_ADMIN_INIT' ) ?: '/redirect|prevent_admin_access/i' );
$v3rp_hook    = $GLOBALS['wp_filter']['admin_init'] ?? null;
if ( $v3rp_hook instanceof WP_Hook ) {
	$v3rp_callbacks = $v3rp_hook->callbacks;
	ksort( $v3rp_callbacks );
	foreach ( $v3rp_callbacks as $cbs ) {
		foreach ( $cbs as $cb ) {
			$f    = $cb['function'];
			$name = is_string( $f ) ? $f : ( is_array( $f ) ? ( is_object( $f[0] ) ? get_class( $f[0] ) : $f[0] ) . '::' . $f[1] : 'closure' );
			if ( preg_match( $v3rp_pattern, $name ) ) {
				try {
					call_user_func( $f, '' );
				} catch ( RuntimeException $e ) {
					$GLOBALS['v3r_probe_done'] = true;
					echo "  resultado: RECUSADO pelo WordPress (em {$name})\n";
					exit( 0 );
				}
			}
		}
	}
}

$GLOBALS['v3r_probe_done'] = true;
echo null !== $GLOBALS['v3r_probe_redirect']
	? sprintf( "  resultado: REDIRECIONA para %s\n", preg_replace( '#^https?://[^/]+#', '', (string) $GLOBALS['v3r_probe_redirect'] ) )
	: "  resultado: abre\n";
