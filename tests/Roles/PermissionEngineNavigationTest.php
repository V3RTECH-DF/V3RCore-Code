<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Roles;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\Navigation;
use V3R\Core\Admin\Nav\Registry;
use V3R\Core\Admin\Nav\Screen;
use V3R\Core\Roles\PermissionEngine;
use V3R\Core\Roles\RoleMatrix;
use V3R\Core\Tests\Licensing\Storage\InMemoryKeyValueStore;
use V3R\Core\Tests\Roles\Support\InMemoryRoleAssignmentStore;

/**
 * Prova o critério de aceite da promoção (V3RCore-Code#39): ligar o motor à
 * navegação é UMA linha —
 * `new Navigation( $registry, $engine->asScreenAccess( $userId ) )` — porque
 * `PermissionEngine::userCan()` já tem a forma
 * `function( string $permission ): bool` que `Navigation` aceita desde a
 * 0.18.0 (docs/navegacao-do-painel.md §3).
 */
final class PermissionEngineNavigationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['v3r_core_test_puc_filters']              = array();
		$GLOBALS['v3r_core_test_registered_menu_pages']    = array();
		$GLOBALS['v3r_core_test_registered_submenu_pages'] = array();
	}

	public function testNavegacaoLigadaAoMotorFiltraAArvoreCertaParaCadaPessoa(): void {
		$defaults    = array(
			'auditor'  => array(
				'label'       => 'Auditor',
				'description' => '',
				'permissions' => array( 'ropa.view' ),
			),
			'operador' => array(
				'label'       => 'Operador',
				'description' => '',
				'permissions' => array( 'orders.manage' ),
			),
		);
		$matrix      = new RoleMatrix( new InMemoryKeyValueStore(), 'v3r_test_roles', $defaults );
		$assignments = new InMemoryRoleAssignmentStore();
		$assignments->setRoles( 1, array( 'auditor' ) );
		$assignments->setRoles( 2, array( 'operador' ) );
		// 3 = administrador, sem papel nenhum atribuído.

		$engine = new PermissionEngine(
			$matrix,
			$assignments,
			static function ( int $userId ): bool {
				return 3 === $userId;
			}
		);

		$registry = new Registry();
		$registry->add( new Screen( 'ropa', 'ROPA', null, 'ropa.view' ) );
		$registry->add( new Screen( 'orders', 'Pedidos', null, 'orders.manage' ) );

		$treeAuditor  = ( new Navigation( $registry, $engine->asScreenAccess( 1 ) ) )->tree();
		$treeOperador = ( new Navigation( $registry, $engine->asScreenAccess( 2 ) ) )->tree();
		$treeAdmin    = ( new Navigation( $registry, $engine->asScreenAccess( 3 ) ) )->tree();

		$slugs = static function ( array $tree ): array {
			return array_column( $tree, 'slug' );
		};

		$this->assertSame( array( 'ropa' ), $slugs( $treeAuditor ) );
		$this->assertSame( array( 'orders' ), $slugs( $treeOperador ) );
		// Administrador (anti-tranca): vê as duas, mesmo sem papel atribuído.
		$this->assertSame( array( 'ropa', 'orders' ), $slugs( $treeAdmin ) );
	}
}
