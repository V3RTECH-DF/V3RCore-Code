<?php
/**
 * Adaptador do V3RLGPD para bin/sonda-acesso-por-pessoa.php — só leitura.
 *
 * Mostra o papel do plugin (meta do usuário) e as permissões efetivas do motor
 * de papéis, que é o que a SPA e o REST consultam.
 */

return array(
	'RBAC do V3RLGPD' => static function () {
		if ( ! class_exists( '\V3RTECH\V3RLGPD\Core\Permissions' ) ) {
			return array( '(Permissions ausente)' => false );
		}
		$uid   = (int) get_current_user_id();
		$perms = \V3RTECH\V3RLGPD\Core\Permissions::user_permissions( $uid );
		sort( $perms );
		return array(
			'papel do plugin (meta v3rlgpd_role)' => (string) get_user_meta( $uid, \V3RTECH\V3RLGPD\Core\Permissions::USER_ROLE_META, true ),
			'has_any_role'                        => \V3RTECH\V3RLGPD\Core\Permissions::has_any_role( $uid ),
			'permissoes efetivas'                 => implode( ' ', $perms ),
		);
	},
);
