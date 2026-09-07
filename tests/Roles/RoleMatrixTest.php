<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Roles;

use PHPUnit\Framework\TestCase;
use V3R\Core\Roles\RoleMatrix;
use V3R\Core\Tests\Access\Support\CountingKeyValueStore;
use V3R\Core\Tests\Licensing\Storage\InMemoryKeyValueStore;
use V3R\Core\Tests\Roles\Support\DefaultRolesFixtures;

final class RoleMatrixTest extends TestCase {

	/** @return array<string, array{label: string, description: string, permissions: string[]}> */
	private function defaults(): array {
		return array(
			'dpo'     => array(
				'label'       => 'Encarregado',
				'description' => 'Acesso completo.',
				'permissions' => array( 'dashboard.view', 'ropa.view', 'ropa.manage' ),
			),
			'auditor' => array(
				'label'       => 'Auditor',
				'description' => 'Só leitura.',
				'permissions' => array( 'dashboard.view', 'ropa.view' ),
			),
		);
	}

	public function testChaveVaziaERecusada(): void {
		$this->expectException( \InvalidArgumentException::class );
		new RoleMatrix( new InMemoryKeyValueStore(), '', $this->defaults() );
	}

	public function testSemNadaGuardadoDevolveOsPadroes(): void {
		$matrix = new RoleMatrix( new InMemoryKeyValueStore(), 'v3r_test_roles', $this->defaults() );

		$this->assertSame( $this->defaults(), $matrix->all() );
	}

	public function testSeedIfMissingGravaOsPadroes(): void {
		$store  = new InMemoryKeyValueStore();
		$matrix = new RoleMatrix( $store, 'v3r_test_roles', $this->defaults() );

		$matrix->seedIfMissing();

		$this->assertSame( $this->defaults(), $store->get( 'v3r_test_roles' ) );
	}

	public function testSeedIfMissingNaoSobrescreveValorCustomizado(): void {
		$store = new InMemoryKeyValueStore();
		$store->set(
			'v3r_test_roles',
			array(
				'custom' => array(
					'label' => 'X',
					'description' => '',
					'permissions' => array( 'x' ),
				),
			)
		);
		$matrix = new RoleMatrix( $store, 'v3r_test_roles', $this->defaults() );

		$matrix->seedIfMissing();

		$this->assertSame( array( 'custom' ), array_keys( $store->get( 'v3r_test_roles' ) ) );
	}

	/**
	 * ⚠️ Rótulo e descrição vêm SEMPRE do código, mesmo com a matriz já
	 * guardada — o guardado manda só nas permissões. É o que faz renomear
	 * um papel-modelo ter efeito em instalação já semeada.
	 */
	public function testRotuloEDescricaoVemDoCodigoMesmoComMatrizGuardada(): void {
		$store = new InMemoryKeyValueStore();
		$store->set(
			'v3r_test_roles',
			array(
				'dpo' => array(
					'label' => 'Nome antigo gravado no banco',
					'description' => 'Descrição antiga',
					'permissions' => array( 'dashboard.view' ),
				),
			)
		);
		$matrix = new RoleMatrix( $store, 'v3r_test_roles', $this->defaults() );

		$roles = $matrix->all();

		$this->assertSame( 'Encarregado', $roles['dpo']['label'] );
		$this->assertSame( 'Acesso completo.', $roles['dpo']['description'] );
		// As permissões, essas sim, vêm do guardado (só dashboard.view, não o padrão inteiro).
		$this->assertSame( array( 'dashboard.view' ), $roles['dpo']['permissions'] );
	}

	public function testPapelDesconhecidoNaoExiste(): void {
		$matrix = new RoleMatrix( new InMemoryKeyValueStore(), 'v3r_test_roles', $this->defaults() );

		$this->assertFalse( $matrix->exists( 'inexistente' ) );
		$this->assertTrue( $matrix->exists( 'dpo' ) );
	}

	/** A matriz é lida do armazenamento no máximo UMA vez por instância. */
	public function testMatrizELidaUmaSoVez(): void {
		$store  = new CountingKeyValueStore( new InMemoryKeyValueStore() );
		$matrix = new RoleMatrix( $store, 'v3r_test_roles', $this->defaults() );

		$matrix->all();
		$matrix->exists( 'dpo' );
		$matrix->permissionsOf( 'auditor' );
		$matrix->all();

		$this->assertSame( 1, $store->reads );
	}

	public function testReconcileModuleNaoTemEfeitoEmInstalacaoNova(): void {
		$store  = new InMemoryKeyValueStore();
		$matrix = new RoleMatrix( $store, 'v3r_test_roles', $this->defaults() );

		$matrix->reconcileModule( 'ropa' );

		$this->assertNull( $store->get( 'v3r_test_roles' ) );
	}

	/**
	 * Reproduz `ensure_module_seeded('detector')` do V3RLGPD: dpo e auditor
	 * ganham o módulo (porque os PADRÕES deles já preveem `detector.*`);
	 * atendente não ganha nada — sem lista de exceção, só porque os padrões
	 * dele nunca incluíram o módulo.
	 */
	public function testReconcileModuleReproduzExclusaoDoV3rlgpd(): void {
		$defaults = DefaultRolesFixtures::v3rlgpdComModuloDetector();
		$store    = new InMemoryKeyValueStore();
		// Instalação "antiga": guardada ANTES do módulo detector existir.
		$store->set(
			'v3r_test_roles',
			array(
				'dpo'       => array(
					'label' => 'x',
					'description' => 'x',
					'permissions' => array( 'dashboard.view', 'ropa.view', 'ropa.manage' ),
				),
				'auditor'   => array(
					'label' => 'x',
					'description' => 'x',
					'permissions' => array( 'dashboard.view', 'ropa.view' ),
				),
				'atendente' => array(
					'label' => 'x',
					'description' => 'x',
					'permissions' => array( 'dashboard.view', 'dsar.view', 'dsar.manage' ),
				),
			)
		);
		$matrix = new RoleMatrix( $store, 'v3r_test_roles', $defaults );

		$matrix->reconcileModule( 'detector' );

		$stored = $store->get( 'v3r_test_roles' );
		$this->assertContains( 'detector.view', $stored['dpo']['permissions'] );
		$this->assertContains( 'detector.manage', $stored['dpo']['permissions'] );
		$this->assertContains( 'detector.view', $stored['auditor']['permissions'] );
		$this->assertNotContains( 'detector.manage', $stored['auditor']['permissions'] );
		// A exclusão do atendente é CONSEQUÊNCIA dos padrões dele, não de uma lista.
		$this->assertNotContains( 'detector.view', $stored['atendente']['permissions'] );
		$this->assertNotContains( 'detector.manage', $stored['atendente']['permissions'] );
	}

	/**
	 * Reproduz `backfill_audit_permissions()` do RIT360 Premiado: operador
	 * não ganha nada porque seus padrões nunca incluíram `audit.*`.
	 */
	public function testReconcileModuleReproduzExclusaoDoPremiado(): void {
		$defaults = DefaultRolesFixtures::premiadoComModuloAudit();
		$store    = new InMemoryKeyValueStore();
		$store->set(
			'v3r_test_roles',
			array(
				'admin_campanha' => array(
					'label' => 'x',
					'description' => 'x',
					'permissions' => array( 'dashboard.view', 'campaigns.view', 'campaigns.manage' ),
				),
				'auditor'        => array(
					'label' => 'x',
					'description' => 'x',
					'permissions' => array( 'dashboard.view', 'campaigns.view' ),
				),
				'operador'       => array(
					'label' => 'x',
					'description' => 'x',
					'permissions' => array( 'dashboard.view', 'orders.view', 'orders.manage' ),
				),
			)
		);
		$matrix = new RoleMatrix( $store, 'v3r_test_roles', $defaults );

		$matrix->reconcileModule( 'audit' );

		$stored = $store->get( 'v3r_test_roles' );
		$this->assertContains( 'audit.view', $stored['admin_campanha']['permissions'] );
		$this->assertContains( 'audit.manage', $stored['admin_campanha']['permissions'] );
		$this->assertContains( 'audit.view', $stored['auditor']['permissions'] );
		$this->assertNotContains( 'audit.manage', $stored['auditor']['permissions'] );
		$this->assertNotContains( 'audit.view', $stored['operador']['permissions'] );
		$this->assertNotContains( 'audit.manage', $stored['operador']['permissions'] );
	}

	public function testReconcileModuleEIdempotente(): void {
		$defaults = DefaultRolesFixtures::premiadoComModuloAudit();
		$store    = new InMemoryKeyValueStore();
		$store->set(
			'v3r_test_roles',
			array(
				'admin_campanha' => array(
					'label' => 'x',
					'description' => 'x',
					'permissions' => array( 'dashboard.view' ),
				),
			)
		);
		$matrix = new RoleMatrix( $store, 'v3r_test_roles', $defaults );

		$matrix->reconcileModule( 'audit' );
		$matrix->reconcileModule( 'audit' );

		$stored = $store->get( 'v3r_test_roles' );
		$this->assertSame(
			count( array_unique( $stored['admin_campanha']['permissions'] ) ),
			count( $stored['admin_campanha']['permissions'] )
		);
	}

	/**
	 * `reconcileModule()` só concede o PREFIXO pedido — mesmo quando o papel
	 * também está faltando permissões de outro módulo nos padrões, essas
	 * outras não são tocadas. Sem esta prova, um `reconcileModule('audit')`
	 * que concedesse tudo que falta (não só `audit.*`) passaria despercebido
	 * pelos outros testes desta classe, porque nos fixtures usados ali o
	 * papel já tinha as permissões dos outros módulos.
	 */
	public function testReconcileModuleSoConcedeOPrefixoPedidoNuncaOutroModuloFaltante(): void {
		$defaults = array(
			'admin_campanha' => array(
				'label'       => 'x',
				'description' => 'x',
				'permissions' => array( 'dashboard.view', 'campaigns.view', 'campaigns.manage', 'audit.view', 'audit.manage' ),
			),
		);
		$store    = new InMemoryKeyValueStore();
		// Guardado sem NENHUM dos dois módulos — só dashboard.
		$store->set(
			'v3r_test_roles',
			array(
				'admin_campanha' => array(
					'label' => 'x',
					'description' => 'x',
					'permissions' => array( 'dashboard.view' ),
				),
			)
		);
		$matrix = new RoleMatrix( $store, 'v3r_test_roles', $defaults );

		$matrix->reconcileModule( 'audit' );

		$stored = $store->get( 'v3r_test_roles' );
		$this->assertContains( 'audit.view', $stored['admin_campanha']['permissions'] );
		$this->assertContains( 'audit.manage', $stored['admin_campanha']['permissions'] );
		// campaigns.* também falta nos padrões, mas reconcileModule('audit') não deve tocá-lo.
		$this->assertNotContains( 'campaigns.view', $stored['admin_campanha']['permissions'] );
		$this->assertNotContains( 'campaigns.manage', $stored['admin_campanha']['permissions'] );
	}
}
