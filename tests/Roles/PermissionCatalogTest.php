<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Roles;

use PHPUnit\Framework\TestCase;
use V3R\Core\Roles\PermissionCatalog;

final class PermissionCatalogTest extends TestCase {

	public function testCadaModuloGeraViewEManage(): void {
		$catalog = PermissionCatalog::build( array( 'ropa', 'dsar' ) );

		$this->assertSame(
			array( 'ropa.view', 'ropa.manage', 'dsar.view', 'dsar.manage' ),
			$catalog
		);
	}

	public function testExtrasEntramSemSeguirAGramaticaDeModulo(): void {
		$catalog = PermissionCatalog::build( array( 'ropa' ), array( 'dashboard.view', 'maintenance.manage' ) );

		$this->assertSame(
			array( 'dashboard.view', 'maintenance.manage', 'ropa.view', 'ropa.manage' ),
			$catalog
		);
	}

	public function testSemDuplicatas(): void {
		$catalog = PermissionCatalog::build( array( 'ropa' ), array( 'ropa.view' ) );

		$this->assertSame( 1, count( array_keys( $catalog, 'ropa.view', true ) ) );
	}

	public function testSemModulosNemExtrasDevolveVazio(): void {
		$this->assertSame( array(), PermissionCatalog::build( array() ) );
	}
}
