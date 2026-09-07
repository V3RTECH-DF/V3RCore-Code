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

	/** Declaração existente, sem o sinalizador, continua funcionando sem alteração — hidden cai para false. */
	public function test_hidden_e_falso_por_padrao(): void {
		$screen = new Screen( 'dashboard', 'Painel', null, 'perm' );

		self::assertFalse( $screen->hidden() );
	}

	public function test_hidden_declarada_e_exposta(): void {
		$screen = new Screen( 'certificado', 'Certificado', null, 'perm', null, true );

		self::assertTrue( $screen->hidden() );
	}

	/** Declaração existente, sem o argumento novo, continua funcionando sem alteração — surfaces cai para vazio. */
	public function test_surfaces_e_vazio_por_padrao(): void {
		$screen = new Screen( 'dashboard', 'Painel', null, 'perm' );

		self::assertSame( array(), $screen->surfaces() );
	}

	public function test_surfaces_declaradas_sao_expostas(): void {
		$screen = new Screen( 'config-geral', 'Configurações', null, 'perm', null, false, array( 'painel' ) );

		self::assertSame( array( 'painel' ), $screen->surfaces() );
	}

	/** Sem surfaces declaradas, a tela pertence a qualquer superfície pedida — inclusive a nenhuma. */
	public function test_sem_surfaces_pertence_a_qualquer_superficie(): void {
		$screen = new Screen( 'dashboard', 'Painel', null, 'perm' );

		self::assertTrue( $screen->belongsToSurface( 'painel' ) );
		self::assertTrue( $screen->belongsToSurface( 'publico' ) );
		self::assertTrue( $screen->belongsToSurface( null ) );
	}

	/** Controle negativo: COM surfaces declaradas, só a superfície listada (e nenhuma pedida) pertence. */
	public function test_com_surfaces_declaradas_so_a_listada_pertence(): void {
		$screen = new Screen( 'config-geral', 'Configurações', null, 'perm', null, false, array( 'painel' ) );

		self::assertTrue( $screen->belongsToSurface( 'painel' ) );
		self::assertFalse( $screen->belongsToSurface( 'publico' ) );
		self::assertTrue( $screen->belongsToSurface( null ), 'Sem superfície pedida, nenhum filtro se aplica.' );
	}
}
