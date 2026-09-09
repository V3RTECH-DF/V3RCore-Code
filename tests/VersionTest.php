<?php
declare(strict_types=1);

namespace V3R\Core\Tests;

use PHPUnit\Framework\TestCase;
use V3R\Core\Version;

/**
 * `Version::CURRENT` (V3RCore-Code#44) — a versão embutida da biblioteca,
 * escrita por `bin/bump-version.sh`. Este teste não confere um valor
 * hardcoded (que descolaria da tag a cada bump): confere a FORMA — semver
 * de três números — que é a garantia que quem lê esta constante (o
 * hospedeiro, ou a receita de empacotamento) realmente precisa.
 */
final class VersionTest extends TestCase {

	public function test_current_e_um_semver_de_tres_numeros(): void {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', Version::CURRENT );
	}

	public function test_current_nunca_e_vazia(): void {
		$this->assertNotSame( '', Version::CURRENT );
	}
}
