<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\CapabilityAccess;

final class CapabilityAccessTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['v3r_core_test_current_user_can']                     = array( 'gea_manage_people' );
		$GLOBALS['v3r_core_test_current_user_can_calls_by_capability'] = array();
	}

	public function test_delega_a_current_user_can(): void {
		$access = new CapabilityAccess();

		self::assertTrue( $access->canView( 'gea_manage_people' ) );
		self::assertFalse( $access->canView( 'gea_manage_finance' ) );
	}

	/**
	 * O contrato de desempenho do §3: no máximo uma consulta por
	 * permissão distinta, por instância/requisição. Sem o cache, esta
	 * asserção falharia com `3`.
	 */
	public function test_permissao_repetida_consulta_current_user_can_uma_unica_vez(): void {
		$access = new CapabilityAccess();

		$access->canView( 'gea_manage_people' );
		$access->canView( 'gea_manage_people' );
		$access->canView( 'gea_manage_people' );

		self::assertSame( 1, $GLOBALS['v3r_core_test_current_user_can_calls_by_capability']['gea_manage_people'] );
	}

	/** Controle negativo: permissões DISTINTAS continuam sendo consultadas cada uma a sua vez — o cache não vira um "sim para tudo". */
	public function test_permissoes_distintas_sao_consultadas_cada_uma(): void {
		$access = new CapabilityAccess();

		$access->canView( 'gea_manage_people' );
		$access->canView( 'gea_manage_finance' );

		self::assertSame( 1, $GLOBALS['v3r_core_test_current_user_can_calls_by_capability']['gea_manage_people'] );
		self::assertSame( 1, $GLOBALS['v3r_core_test_current_user_can_calls_by_capability']['gea_manage_finance'] );
	}

	public function test_cache_nao_e_compartilhado_entre_instancias(): void {
		$first  = new CapabilityAccess();
		$second = new CapabilityAccess();

		self::assertTrue( $first->canView( 'gea_manage_people' ) );

		$GLOBALS['v3r_core_test_current_user_can'] = array();

		// A segunda instância nunca consultou antes: reflete o estado ATUAL,
		// não um cache herdado da primeira — prova que o cache é por instância.
		self::assertFalse( $second->canView( 'gea_manage_people' ) );
	}
}
