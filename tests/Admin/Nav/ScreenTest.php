<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\Screen;

final class ScreenTest extends TestCase {

	public function test_expoe_o_que_foi_declarado(): void {
		$screen = new Screen( 'pessoas-cadastro', 'Cadastro', 'pessoas', 'gea_manage_people' );

		self::assertSame( 'pessoas-cadastro', $screen->slug() );
		self::assertSame( 'Cadastro', $screen->label() );
		self::assertSame( 'pessoas', $screen->group() );
		self::assertSame( 'gea_manage_people', $screen->permission() );
	}

	public function test_grupo_ausente_e_null_nao_string_vazia(): void {
		$screen = new Screen( 'dashboard', 'Painel', null, 'v3revent_view' );

		self::assertNull( $screen->group() );
	}

	public function test_grupo_com_apenas_espacos_vira_null(): void {
		$screen = new Screen( 'dashboard', 'Painel', '   ', 'v3revent_view' );

		self::assertNull( $screen->group() );
	}

	public function test_slug_vazio_e_rejeitado(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Screen( '', 'Cadastro', null, 'perm' );
	}

	public function test_label_vazio_e_rejeitado(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Screen( 'slug', '', null, 'perm' );
	}

	public function test_permission_vazia_e_rejeitada(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Screen( 'slug', 'Label', null, '' );
	}

	public function test_order_e_null_quando_nao_declarada(): void {
		$screen = new Screen( 'dashboard', 'Painel', null, 'perm' );

		self::assertNull( $screen->order() );
	}

	public function test_order_declarada_e_exposta(): void {
		$screen = new Screen( 'dashboard', 'Painel', null, 'perm', 40 );

		self::assertSame( 40, $screen->order() );
	}
}
