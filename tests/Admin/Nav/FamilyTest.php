<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\Family;

final class FamilyTest extends TestCase {

	public function test_rit_e_v3rtech_sao_validas(): void {
		self::assertTrue( Family::isValid( Family::RIT ) );
		self::assertTrue( Family::isValid( Family::V3RTECH ) );
	}

	public function test_familia_desconhecida_e_invalida(): void {
		self::assertFalse( Family::isValid( 'acme' ) );
	}

	public function test_icon_file_name(): void {
		self::assertSame( 'familia-rit.svg', Family::iconFileName( Family::RIT ) );
		self::assertSame( 'familia-v3rtech.svg', Family::iconFileName( Family::V3RTECH ) );
	}
}
