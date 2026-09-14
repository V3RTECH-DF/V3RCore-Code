<?php
/**
 * Adaptador do V3RHelp para bin/sonda-acesso-por-pessoa.php (V3RHelp-Code#88).
 *
 * V3R_PROBE_CONTROLE=read reintroduz, só em memória, o submenu de conteúdo com
 * capacidade 'read' da primeira versão da fatia 2 — controle negativo da
 * guarda de acesso direto.
 */

if ( 'read' === getenv( 'V3R_PROBE_CONTROLE' ) ) {
	add_action(
		'admin_menu',
		static function () {
			add_submenu_page( 'v3rhelp', 'controle', 'controle', 'read', 'v3rhelp', '__return_null' );
		},
		100
	);
}

$v3rp_help_caps = static function () {
	return \V3RHelp\Plugin::instance()->container()->get( \V3RHelp\Auth\Capabilities::class );
};

return array(
	'RBAC do V3RHelp' => static function () use ( $v3rp_help_caps ) {
		$caps = $v3rp_help_caps();
		$all  = array_keys( $caps->all() );
		sort( $all );
		$o = array( 'controle submenu read em memória' => 'read' === getenv( 'V3R_PROBE_CONTROLE' ) );
		foreach ( $all as $c ) {
			$o[ $c ] = $caps->user_can( $c );
		}
		return $o;
	},
	'camada'          => static function () use ( $v3rp_help_caps ) {
		if ( ! class_exists( '\V3RHelp\Admin\Nav\NavigationBootstrap' ) ) {
			return null;
		}
		$c = \V3RHelp\Plugin::instance()->container();
		return \V3RHelp\Admin\Nav\NavigationBootstrap::instance( $c->get( \V3RHelp\Modules\ModuleRegistry::class ), $v3rp_help_caps() );
	},
);
