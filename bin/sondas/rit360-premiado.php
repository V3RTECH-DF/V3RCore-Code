<?php
/**
 * Adaptador do RIT360 Premiado para bin/sonda-acesso-por-pessoa.php
 * (RIT360-Premiado-Code#212).
 */

use RIT\Rit360Premiado\Admin\AdminSPA;
use RIT\Rit360Premiado\Core\Permissions;

return array(
	'RBAC do Premiado'           => static function () {
		$o = array(
			'has_any_role' => Permissions::has_any_role(),
			'roles_of'     => implode( ',', Permissions::roles_of() ),
		);
		foreach ( Permissions::catalog() as $p ) {
			$o[ "user_can {$p}" ] = Permissions::user_can( $p );
		}
		$o['user_can sonda.inexistente (controle)'] = Permissions::user_can( 'sonda.inexistente' );
		return $o;
	},
	'seções (can_view_section)'  => static function () {
		$o = array();
		foreach ( array_keys( AdminSPA::SECTIONS ) as $s ) {
			$o[ $s ] = AdminSPA::can_view_section( $s );
		}
		return $o;
	},
);
