<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Admin\Nav;

use PHPUnit\Framework\TestCase;
use V3R\Core\Admin\Nav\Group;
use V3R\Core\Admin\Nav\Registry;
use V3R\Core\Admin\Nav\Screen;
use V3R\Core\Admin\Nav\TreeBuilder;
use V3R\Core\Tests\Support\CountingScreenAccess;

/**
 * Prende as três regras do §5 de docs/navegacao-do-painel.md, cada uma
 * com o controle negativo que prova que a regra discrimina (não passaria
 * com um código que sempre esconde, ou que sempre promove, ou que sempre
 * agrupa).
 */
final class TreeBuilderTest extends TestCase {

	/** Sem nenhum screen declarando grupo, a árvore é uma lista plana — não um grupo único implícito. */
	public function test_sem_grupos_declarados_a_arvore_e_plana(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$registry->add( new Screen( 'b', 'B', null, 'perm_b' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm_a', 'perm_b' ) ) ) )->build();

		self::assertSame(
			array(
				array(
					'type'  => 'screen',
					'slug'  => 'a',
					'label' => 'A',
				),
				array(
					'type'  => 'screen',
					'slug'  => 'b',
					'label' => 'B',
				),
			),
			$tree
		);
	}

	/** Controle negativo da regra acima: um plugin QUE declara grupo não pode sair plano. */
	public function test_plugin_que_declara_grupo_nao_recebe_arvore_plana(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'a', 'A', 'grupo', 'perm_a' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm_a' ) ) ) )->build();

		self::assertSame( 'group', $tree[0]['type'] );
	}

	/** Grupo cujas telas foram todas filtradas não aparece — nem vazio. */
	public function test_grupo_totalmente_filtrado_nao_aparece(): void {
		$registry = new Registry();
		$registry->addGroup( new Group( 'pessoas', 'Pessoas', 20 ) );
		$registry->add( new Screen( 'pessoas-cadastro', 'Cadastro', 'pessoas', 'perm_negada' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array() ) ) )->build();

		self::assertSame( array(), $tree );
	}

	/** Controle negativo: se ALGUMA tela do grupo passa, o grupo aparece (a regra não esconde tudo). */
	public function test_grupo_com_ao_menos_uma_tela_visivel_aparece(): void {
		$registry = new Registry();
		$registry->addGroup( new Group( 'pessoas', 'Pessoas', 20 ) );
		$registry->add( new Screen( 'pessoas-cadastro', 'Cadastro', 'pessoas', 'perm_negada' ) );
		$registry->add( new Screen( 'pessoas-relatorio', 'Relatório', 'pessoas', 'perm_concedida' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm_concedida' ) ) ) )->build();

		self::assertCount( 1, $tree );
		self::assertSame( 'group', $tree[0]['type'] );
		self::assertCount( 1, $tree[0]['screens'] );
		self::assertSame( 'pessoas-relatorio', $tree[0]['screens'][0]['slug'] );
	}

	/** Grupo com uma tela só continua sendo grupo — não é promovido a item solto. */
	public function test_grupo_com_uma_tela_so_continua_grupo(): void {
		$registry = new Registry();
		$registry->addGroup( new Group( 'pessoas', 'Pessoas', 20 ) );
		$registry->add( new Screen( 'pessoas-cadastro', 'Cadastro', 'pessoas', 'perm' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm' ) ) ) )->build();

		self::assertCount( 1, $tree );
		self::assertSame( 'group', $tree[0]['type'], 'Uma tela só no grupo não deveria virar item de primeiro nível.' );
		self::assertSame( 'pessoas', $tree[0]['key'] );
		self::assertCount( 1, $tree[0]['screens'] );
	}

	/** Controle negativo direto da regra acima: sem grupo declarado, a MESMA tela sozinha É item de primeiro nível. */
	public function test_tela_unica_sem_grupo_e_item_de_primeiro_nivel(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'pessoas-cadastro', 'Cadastro', null, 'perm' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm' ) ) ) )->build();

		self::assertSame( 'screen', $tree[0]['type'] );
	}

	/** Duas partes do plugin declarando telas do mesmo grupo produzem um grupo só. */
	public function test_duas_declaracoes_do_mesmo_grupo_produzem_um_grupo_so(): void {
		$registry = new Registry();
		// Simula dois módulos diferentes chamando add() em momentos distintos,
		// ambos referenciando a chave 'pessoas' sem coordenação entre si.
		$registry->add( new Screen( 'pessoas-cadastro', 'Cadastro', 'pessoas', 'perm_a' ) );
		$registry->add( new Screen( 'financeiro-lancamentos', 'Lançamentos', 'financeiro', 'perm_b' ) );
		$registry->add( new Screen( 'pessoas-relatorio', 'Relatório', 'pessoas', 'perm_c' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm_a', 'perm_b', 'perm_c' ) ) ) )->build();

		$grupos = array_values(
			array_filter(
				$tree,
				static function ( array $node ): bool {
					return 'group' === $node['type'] && 'pessoas' === $node['key'];
				}
			)
		);

		self::assertCount( 1, $grupos, 'As duas declarações de telas em "pessoas" deveriam produzir um único nó de grupo.' );
		self::assertCount( 2, $grupos[0]['screens'] );
	}

	/** Grupos são ordenados por Group::order(); grupo não declarado cai para a posição de primeira aparição. */
	public function test_grupos_sao_ordenados_por_order(): void {
		$registry = new Registry();
		$registry->addGroup( new Group( 'financeiro', 'Financeiro', 10 ) );
		$registry->addGroup( new Group( 'pessoas', 'Pessoas', 20 ) );
		$registry->add( new Screen( 'pessoas-x', 'X', 'pessoas', 'perm_a' ) );
		$registry->add( new Screen( 'financeiro-y', 'Y', 'financeiro', 'perm_b' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm_a', 'perm_b' ) ) ) )->build();

		self::assertSame( 'financeiro', $tree[0]['key'] );
		self::assertSame( 'pessoas', $tree[1]['key'] );
	}

	/** Rótulo cai para a própria chave quando ninguém chamou addGroup() para ela. */
	public function test_grupo_sem_metadado_usa_a_propria_chave_como_rotulo(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'x', 'X', 'sem-metadado', 'perm' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm' ) ) ) )->build();

		self::assertSame( 'sem-metadado', $tree[0]['label'] );
	}

	/**
	 * O caso do RIT360 Flow (06/09/2026): uma tela solta com `order` declarada
	 * precisa cair ENTRE dois grupos, não sempre antes de todos eles.
	 */
	public function test_tela_solta_com_order_cai_entre_dois_grupos(): void {
		$registry = new Registry();
		$registry->addGroup( new Group( 'pessoas', 'Pessoas', 20 ) );
		$registry->addGroup( new Group( 'fluxos', 'Fluxos', 50 ) );
		$registry->add( new Screen( 'pessoas-x', 'Pessoas X', 'pessoas', 'perm_a' ) );
		$registry->add( new Screen( 'configuracoes', 'Configurações', null, 'perm_b', 40 ) );
		$registry->add( new Screen( 'fluxos-x', 'Fluxos X', 'fluxos', 'perm_c' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm_a', 'perm_b', 'perm_c' ) ) ) )->build();

		self::assertSame( 'pessoas', $tree[0]['key'] );
		self::assertSame( 'screen', $tree[1]['type'] );
		self::assertSame( 'configuracoes', $tree[1]['slug'] );
		self::assertSame( 'fluxos', $tree[2]['key'] );
	}

	/** Controle negativo: sem `order` na tela solta, ela cai na posição de declaração — antes dos dois grupos. */
	public function test_tela_solta_sem_order_nao_pula_para_o_meio(): void {
		$registry = new Registry();
		$registry->addGroup( new Group( 'pessoas', 'Pessoas', 20 ) );
		$registry->addGroup( new Group( 'fluxos', 'Fluxos', 50 ) );
		$registry->add( new Screen( 'configuracoes', 'Configurações', null, 'perm_b' ) );
		$registry->add( new Screen( 'pessoas-x', 'Pessoas X', 'pessoas', 'perm_a' ) );
		$registry->add( new Screen( 'fluxos-x', 'Fluxos X', 'fluxos', 'perm_c' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm_a', 'perm_b', 'perm_c' ) ) ) )->build();

		self::assertSame( 'configuracoes', $tree[0]['slug'] );
		self::assertSame( 'pessoas', $tree[1]['key'] );
		self::assertSame( 'fluxos', $tree[2]['key'] );
	}

	/** Telas dentro de um grupo respeitam `order` entre si. */
	public function test_telas_do_mesmo_grupo_respeitam_order_entre_si(): void {
		$registry = new Registry();
		$registry->addGroup( new Group( 'pessoas', 'Pessoas', 20 ) );
		$registry->add( new Screen( 'pessoas-relatorio', 'Relatório', 'pessoas', 'perm_a', 20 ) );
		$registry->add( new Screen( 'pessoas-cadastro', 'Cadastro', 'pessoas', 'perm_b', 10 ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm_a', 'perm_b' ) ) ) )->build();

		self::assertSame( 'pessoas-cadastro', $tree[0]['screens'][0]['slug'] );
		self::assertSame( 'pessoas-relatorio', $tree[0]['screens'][1]['slug'] );
	}

	/** Controle negativo: sem `order` declarada nas telas do grupo, prevalece a ordem de inserção. */
	public function test_telas_do_mesmo_grupo_sem_order_mantem_ordem_de_insercao(): void {
		$registry = new Registry();
		$registry->addGroup( new Group( 'pessoas', 'Pessoas', 20 ) );
		$registry->add( new Screen( 'pessoas-relatorio', 'Relatório', 'pessoas', 'perm_a' ) );
		$registry->add( new Screen( 'pessoas-cadastro', 'Cadastro', 'pessoas', 'perm_b' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm_a', 'perm_b' ) ) ) )->build();

		self::assertSame( 'pessoas-relatorio', $tree[0]['screens'][0]['slug'] );
		self::assertSame( 'pessoas-cadastro', $tree[0]['screens'][1]['slug'] );
	}

	/**
	 * Sem nenhuma `order` declarada em lugar nenhum, a árvore PLANA sai
	 * idêntica à ordem de declaração — comparando a árvore inteira, não só
	 * um nó, para proteger contra regressão silenciosa na ordenação nova.
	 */
	public function test_sem_order_a_arvore_plana_e_identica_a_ordem_de_declaracao(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'c', 'C', null, 'perm_c' ) );
		$registry->add( new Screen( 'a', 'A', null, 'perm_a' ) );
		$registry->add( new Screen( 'b', 'B', null, 'perm_b' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm_a', 'perm_b', 'perm_c' ) ) ) )->build();

		self::assertSame(
			array(
				array(
					'type'  => 'screen',
					'slug'  => 'c',
					'label' => 'C',
				),
				array(
					'type'  => 'screen',
					'slug'  => 'a',
					'label' => 'A',
				),
				array(
					'type'  => 'screen',
					'slug'  => 'b',
					'label' => 'B',
				),
			),
			$tree
		);
	}

	/**
	 * Mesmo controle, com grupos: sem nenhuma `order` declarada — nem em
	 * Group, nem em Screen — a árvore agrupada sai idêntica à ordem de
	 * declaração, comparando a árvore inteira.
	 */
	public function test_sem_order_a_arvore_agrupada_e_identica_a_ordem_de_declaracao(): void {
		$registry = new Registry();
		$registry->add( new Screen( 'financeiro-y', 'Y', 'financeiro', 'perm_b' ) );
		$registry->add( new Screen( 'solta', 'Solta', null, 'perm_s' ) );
		$registry->add( new Screen( 'pessoas-x', 'X', 'pessoas', 'perm_a' ) );
		$registry->add( new Screen( 'pessoas-z', 'Z', 'pessoas', 'perm_z' ) );

		$tree = ( new TreeBuilder( $registry, new CountingScreenAccess( array( 'perm_a', 'perm_b', 'perm_s', 'perm_z' ) ) ) )->build();

		self::assertSame(
			array(
				array(
					'type'    => 'group',
					'key'     => 'financeiro',
					'label'   => 'financeiro',
					'screens' => array(
						array(
							'type'  => 'screen',
							'slug'  => 'financeiro-y',
							'label' => 'Y',
						),
					),
				),
				array(
					'type'  => 'screen',
					'slug'  => 'solta',
					'label' => 'Solta',
				),
				array(
					'type'    => 'group',
					'key'     => 'pessoas',
					'label'   => 'pessoas',
					'screens' => array(
						array(
							'type'  => 'screen',
							'slug'  => 'pessoas-x',
							'label' => 'X',
						),
						array(
							'type'  => 'screen',
							'slug'  => 'pessoas-z',
							'label' => 'Z',
						),
					),
				),
			),
			$tree
		);
	}
}
