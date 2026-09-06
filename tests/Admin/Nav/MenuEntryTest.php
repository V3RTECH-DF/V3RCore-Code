<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\Family;
use V3R\Core\Admin\Nav\MenuEntry;

final class MenuEntryTest extends TestCase {

	public function test_expoe_o_que_foi_declarado(): void {
		$entry = new MenuEntry( 'RIT360 Flow', 'v3rflow', Family::RIT );

		self::assertSame( 'RIT360 Flow', $entry->title() );
		self::assertSame( 'v3rflow', $entry->slug() );
		self::assertSame( Family::RIT, $entry->family() );
	}

	public function test_title_vazio_e_rejeitado(): void {
		$this->expectException( \InvalidArgumentException::class );

		new MenuEntry( '', 'v3rflow', Family::RIT );
	}

	public function test_slug_vazio_e_rejeitado(): void {
		$this->expectException( \InvalidArgumentException::class );

		new MenuEntry( 'RIT360 Flow', '', Family::RIT );
	}

	public function test_familia_desconhecida_e_rejeitada(): void {
		$this->expectException( \InvalidArgumentException::class );

		new MenuEntry( 'RIT360 Flow', 'v3rflow', 'acme' );
	}
}
