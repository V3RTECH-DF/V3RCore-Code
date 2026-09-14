<?php
/**
 * Adaptador do V3REvent para bin/sonda-acesso-por-pessoa.php (V3REvent-Code#180).
 * Evento 236 é o evento de teste do dev-wp com equipe cadastrada.
 */

use V3RTECH\V3REvent\Core\Auth\Auth;
use V3RTECH\V3REvent\Frontend\Gestao;

return array(
	'RBAC do V3REvent'                  => static function () {
		$o = array(
			'has_plugin_access' => Auth::has_plugin_access(),
			'is_org_admin'      => Auth::is_org_admin(),
		);
		foreach ( array( 'dashboard.view', 'settings.manage', 'org.admins', 'privacy.manage' ) as $p ) {
			$o[ "can {$p}" ] = Auth::can( $p );
		}
		foreach ( array( 'event.view', 'event.edit', 'event.team', 'event.attendees', 'event.checkin', 'event.export', 'event.registrations' ) as $p ) {
			$o[ "can {$p} @236" ] = Auth::can( $p, 236 );
		}
		$o['can sonda.inexistente @236 (controle)'] = Auth::can( 'sonda.inexistente', 236 );
		return $o;
	},
	'superfície site (v3revent_gestao)' => static function () {
		return array( 'access_state' => Gestao::access_state( is_user_logged_in(), Auth::has_plugin_access() ) );
	},
);
