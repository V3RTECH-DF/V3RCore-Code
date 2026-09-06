<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\Group;

final class GroupTest extends TestCase {

	public function test_expoe_o_que_foi_declarado(): void {
		$group = new Group( 'pessoas', 'Pessoas', 20 );

		self::assertSame( 'pessoas', $group->key() );
		self::assertSame( 'Pessoas', $group->label() );
		self::assertSame( 20, $group->order() );
	}

	public function test_key_vazia_e_rejeitada(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Group( '', 'Pessoas', 20 );
	}

	public function test_label_vazio_e_rejeitado(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Group( 'pessoas', '', 20 );
	}
}
