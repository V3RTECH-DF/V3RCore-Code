<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Roles;

use PHPUnit\Framework\TestCase;
use V3R\Core\Roles\PermissionEngine;
use V3R\Core\Roles\RoleMatrix;
use V3R\Core\Tests\Licensing\Storage\InMemoryKeyValueStore;
use V3R\Core\Tests\Roles\Support\InMemoryRoleAssignmentStore;

final class PermissionEngineTest extends TestCase {

	/**
	 * Papéis-modelo com o mesmo eixo de divergência que originou a
	 * promoção: `dpo`/`auditor` de conjunto de um elemento (V3RLGPD) e
	 * `operador` pensado para conviver com outro papel na união (Premiado).
	 *
	 * @return array<string, array{label: string, description: string, permissions: string[]}>
	 */
	private function defaults(): array {
		return array(
			'dpo'      => array(
				'label'       => 'Encarregado',
				'description' => 'Acesso completo.',
				'permissions' => array( 'dashboard.view', 'ropa.view', 'ropa.manage', 'dsar.erase' ),
			),
			'auditor'  => array(
				'label'       => 'Auditor',
				'description' => 'Só leitura.',
				'permissions' => array( 'dashboard.view', 'ropa.view' ),
			),
			'operador' => array(
				'label'       => 'Operador',
				'description' => 'Orders e accountability.',
				'permissions' => array( 'dashboard.view', 'orders.view', 'orders.manage' ),
			),
		);
	}

	/**
	 * @param InMemoryRoleAssignmentStore $assignments
	 * @param array<int, bool>            $admins      userId => é administrador?.
	 * @param string[]                    $sensitive
	 * @param RoleMatrix|null             $matrix
	 */
	private function engine( InMemoryRoleAssignmentStore $assignments, array $admins = array(), array $sensitive = array(), ?RoleMatrix $matrix = null ): PermissionEngine {
		$matrix = $matrix ?? new RoleMatrix( new InMemoryKeyValueStore(), 'v3r_test_roles', $this->defaults() );

		return new PermissionEngine(
			$matrix,
			$assignments,
			static function ( int $userId ) use ( $admins ): bool {
				return $admins[ $userId ] ?? false;
			},
			$sensitive
		);
	}

	public function testPapelUnicoFormatoAntigoFuncionaSemConversao(): void {
		$assignments = new InMemoryRoleAssignmentStore();
		$assignments->seedLegacy( 10, 'auditor' );
		$engine = $this->engine( $assignments );

		$this->assertSame( array( 'auditor' ), $engine->rolesOf( 10 ) );
		$this->assertTrue( $engine->userCan( 10, 'ropa.view' ) );
		$this->assertFalse( $engine->userCan( 10, 'ropa.manage' ) );
		$this->assertTrue( $engine->hasAnyRole( 10 ) );
	}

	public function testConjuntoDeVariosPapeisUneAsPermissoes(): void {
		$assignments = new InMemoryRoleAssignmentStore();
		$assignments->setRoles( 20, array( 'operador', 'auditor' ) );
		$engine = $this->engine( $assignments );

		$this->assertSame( array( 'operador', 'auditor' ), $engine->rolesOf( 20 ) );
		$this->assertTrue( $engine->userCan( 20, 'orders.manage' ) );
		$this->assertTrue( $engine->userCan( 20, 'ropa.view' ) );
		$this->assertFalse( $engine->userCan( 20, 'ropa.manage' ) );

		$perms = $engine->permissionsOf( 20 );
		$this->assertContains( 'orders.manage', $perms );
		$this->assertContains( 'ropa.view', $perms );
		$this->assertSame( array_values( array_unique( $perms ) ), $perms );
	}

	public function testAdministradorPodeTudoMesmoSemPapel(): void {
		$assignments = new InMemoryRoleAssignmentStore();
		$engine      = $this->engine( $assignments, array( 1 => true ) );

		$this->assertTrue( $engine->userCan( 1, 'ropa.manage' ) );
		$this->assertTrue( $engine->userCan( 1, 'qualquer.coisa.inventada' ) );
		$this->assertSame( array( '*' ), $engine->permissionsOf( 1 ) );
		$this->assertTrue( $engine->hasAnyRole( 1 ) );
	}

	/**
	 * ⚠️ O bypass é resolvido UMA vez por pessoa, mesmo com muitas
	 * perguntas de permissão distintas na mesma requisição — não uma vez
	 * por pergunta. Conta CHAMADAS reais ao verificador injetado.
	 */
	public function testBypassDeAdministradorEResolvidoUmaVezPorPessoa(): void {
		$assignments = new InMemoryRoleAssignmentStore();
		$chamadas    = array();
		$matrix      = new RoleMatrix( new InMemoryKeyValueStore(), 'v3r_test_roles', $this->defaults() );
		$engine      = new PermissionEngine(
			$matrix,
			$assignments,
			static function ( int $userId ) use ( &$chamadas ): bool {
				$chamadas[] = $userId;
				return true;
			}
		);

		$engine->userCan( 1, 'ropa.view' );
		$engine->userCan( 1, 'ropa.manage' );
		$engine->userCan( 1, 'dsar.erase' );
		$engine->hasAnyRole( 1 );
		$engine->permissionsOf( 1 );

		$this->assertSame( array( 1 ), $chamadas );
	}

	public function testUsuarioSemPapelNaoFazNada(): void {
		$assignments = new InMemoryRoleAssignmentStore();
		$engine      = $this->engine( $assignments );

		$this->assertFalse( $engine->userCan( 99, 'dashboard.view' ) );
		$this->assertFalse( $engine->hasAnyRole( 99 ) );
		$this->assertSame( array(), $engine->permissionsOf( 99 ) );
	}

	public function testPapelDesconhecidoGuardadoNaPessoaEIgnorado(): void {
		$assignments = new InMemoryRoleAssignmentStore();
		$assignments->setRoles( 30, array( 'auditor', 'papel-que-nao-existe-mais' ) );
		$engine = $this->engine( $assignments );

		$this->assertSame( array( 'auditor' ), $engine->rolesOf( 30 ) );
		$this->assertFalse( $engine->userCan( 30, 'papel-que-nao-existe-mais.manage' ) );
	}

	public function testAssignRolesValidaDedupicaEGravaComoLista(): void {
		$assignments = new InMemoryRoleAssignmentStore();
		$engine      = $this->engine( $assignments );

		$ok = $engine->assignRoles( 40, array( 'auditor', 'operador', 'auditor' ) );

		$this->assertTrue( $ok );
		$this->assertSame( array( 'auditor', 'operador' ), $assignments->getRoles( 40 ) );
	}

	public function testAssignRolesRejeitaSlugDesconhecidoSemGravarNada(): void {
		$assignments = new InMemoryRoleAssignmentStore();
		$assignments->setRoles( 41, array( 'auditor' ) );
		$engine = $this->engine( $assignments );

		$ok = $engine->assignRoles( 41, array( 'auditor', 'inexistente' ) );

		$this->assertFalse( $ok );
		// Nada mudou.
		$this->assertSame( array( 'auditor' ), $assignments->getRoles( 41 ) );
	}

	public function testAssignRolesVazioRemoveTodos(): void {
		$assignments = new InMemoryRoleAssignmentStore();
		$assignments->setRoles( 42, array( 'auditor', 'operador' ) );
		$engine = $this->engine( $assignments );

		$ok = $engine->assignRoles( 42, array() );

		$this->assertTrue( $ok );
		$this->assertSame( array(), $engine->rolesOf( 42 ) );
		$this->assertFalse( $engine->hasAnyRole( 42 ) );
	}

	public function testAssignRoleUnicoECompatComAssignRoles(): void {
		$assignments = new InMemoryRoleAssignmentStore();
		$engine      = $this->engine( $assignments );

		$engine->assignRole( 43, 'auditor' );
		$this->assertSame( array( 'auditor' ), $assignments->getRoles( 43 ) );

		$engine->assignRole( 43, '' );
		// Removeu o vínculo — nada gravado, não uma lista vazia.
		$this->assertNull( $assignments->getRoles( 43 ) );
		$this->assertSame( array(), $engine->rolesOf( 43 ) );
	}

	public function testPermissoesSensiveisSaoExpostasSeparadamente(): void {
		$assignments = new InMemoryRoleAssignmentStore();
		$engine      = $this->engine( $assignments, array(), array( 'dsar.erase', 'incidents.notify' ) );

		$this->assertSame( array( 'dsar.erase', 'incidents.notify' ), $engine->sensitivePermissions() );
	}

	public function testDoisProdutosComOptionKeysDiferentesNaoInterferem(): void {
		$storeA = new InMemoryKeyValueStore();
		$storeB = new InMemoryKeyValueStore();

		$matrixA = new RoleMatrix(
			$storeA,
			'produto_a_roles',
			array(
				'a1' => array(
					'label' => 'A1',
					'description' => '',
					'permissions' => array( 'x.view' ),
				),
			)
		);
		$matrixB = new RoleMatrix(
			$storeB,
			'produto_b_roles',
			array(
				'b1' => array(
					'label' => 'B1',
					'description' => '',
					'permissions' => array( 'y.view' ),
				),
			)
		);

		$assignmentsA = new InMemoryRoleAssignmentStore();
		$assignmentsB = new InMemoryRoleAssignmentStore();
		$assignmentsA->setRoles( 1, array( 'a1' ) );
		$assignmentsB->setRoles( 1, array( 'b1' ) );

		$engineA = $this->engine( $assignmentsA, array(), array(), $matrixA );
		$engineB = $this->engine( $assignmentsB, array(), array(), $matrixB );

		$this->assertTrue( $engineA->userCan( 1, 'x.view' ) );
		$this->assertFalse( $engineA->userCan( 1, 'y.view' ) );
		$this->assertTrue( $engineB->userCan( 1, 'y.view' ) );
		$this->assertFalse( $engineB->userCan( 1, 'x.view' ) );
	}
}
