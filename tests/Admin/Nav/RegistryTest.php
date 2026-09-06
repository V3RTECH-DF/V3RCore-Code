<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\Group;
use V3R\Core\Admin\Nav\Registry;
use V3R\Core\Admin\Nav\Screen;

final class RegistryTest extends TestCase {

	public function test_add_acumula_em_qualquer_ordem_sem_lista_central(): void {
		$registry = new Registry();

		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$registry->add( new Screen( 'b', 'B', null, 'perm_b' ) );

		self::assertCount( 2, $registry->screens() );
		self::assertSame( 'a', $registry->screens()[0]->slug() );
		self::assertSame( 'b', $registry->screens()[1]->slug() );
	}

	public function test_findScreen_localiza_por_slug(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'pessoas-cadastro', 'Cadastro', null, 'perm' ) );

		$screen = $registry->findScreen( 'pessoas-cadastro' );
		self::assertNotNull( $screen );
		self::assertSame( 'Cadastro', $screen->label() );
	}

	public function test_findScreen_devolve_null_para_slug_desconhecido(): void {
		$registry = new Registry();

		self::assertNull( $registry->findScreen( 'nao-existe' ) );
	}

	public function test_findGroup_localiza_por_key(): void {
		$registry = new Registry();
		$registry->addGroup( new Group( 'pessoas', 'Pessoas', 20 ) );

		$group = $registry->findGroup( 'pessoas' );
		self::assertNotNull( $group );
		self::assertSame( 'Pessoas', $group->label() );
	}

	public function test_findGroup_devolve_null_quando_nunca_declarado(): void {
		$registry = new Registry();

		self::assertNull( $registry->findGroup( 'pessoas' ) );
	}

	/** Duas partes do plugin declarando o metadado do mesmo grupo não duplicam a entrada. */
	public function test_addGroup_chamado_duas_vezes_para_a_mesma_chave_nao_duplica(): void {
		$registry = new Registry();
		$registry->addGroup( new Group( 'pessoas', 'Pessoas', 20 ) );
		$registry->addGroup( new Group( 'pessoas', 'Pessoas (rótulo revisado)', 20 ) );

		$group = $registry->findGroup( 'pessoas' );
		self::assertNotNull( $group );
		self::assertSame( 'Pessoas (rótulo revisado)', $group->label() );
	}
}
