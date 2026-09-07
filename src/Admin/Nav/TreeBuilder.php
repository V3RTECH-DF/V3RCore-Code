<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * Constrói a árvore de navegação já filtrada para o usuário corrente
 * (docs/navegacao-do-painel.md §5), a partir do que o `Registry` acumulou.
 * Puro: não depende do WordPress, só do `ScreenAccess` que o plugin
 * forneceu — testável sem nada carregado.
 *
 * Três regras, e nenhuma é degenerada:
 *
 * 1. Grupo cujas telas foram todas filtradas não aparece.
 * 2. Grupo com uma tela só continua sendo grupo — nunca promovido a item
 *    solto, para a forma da navegação não mudar conforme a permissão de
 *    cada pessoa.
 * 3. Nenhuma tela declara grupo algum: a árvore é plana, sem nó de grupo
 *    nenhum — não é "grupo único implícito", é ausência total de
 *    agrupamento. É o caso comum (V3REvent, V3RLicense, V3RHelp).
 *
 * `order` ordena entre irmãos, em qualquer nível: tela solta e grupo usam
 * a mesma escala no primeiro nível (é o que permite uma tela solta cair
 * entre dois grupos), e as telas de um mesmo grupo se ordenam entre si do
 * mesmo jeito. Sem `order` declarada em lugar nenhum, o resultado é a
 * ordem de declaração — um grupo sem `Group` registrado (só citado via
 * `Screen::group()`) usa a posição de primeira aparição como ordem, para
 * não desaparecer nem pular para o fim da lista.
 *
 * ⚠️ Entre irmãos, declare `order` para todos ou para nenhum: misturar
 * quem declara com quem não compara duas escalas diferentes (valor
 * declarado contra posição de inserção) e o resultado surpreende.
 *
 * **Tela oculta (`Screen::hidden()`) nunca entra aqui** — nem como item
 * solto, nem dentro de grupo, nem para decidir se um grupo usa a forma
 * plana ou agrupada. Ela continua contando para a guarda de acesso direto
 * e para "enxerga ao menos uma tela" (`NavCapabilityGate`); só a árvore a
 * ignora. Um grupo cujas telas restantes são todas ocultas some pela
 * mesma regra 1 acima — telas ocultas são filtradas antes de qualquer
 * outra coisa, então "restarem zero telas visíveis" é o caso comum.
 *
 * @phpstan-type ScreenNode array{type: 'screen', slug: string, label: string}
 * @phpstan-type GroupNode array{type: 'group', key: string, label: string, screens: ScreenNode[]}
 */
final class TreeBuilder {

	/** @var Registry */
	private $registry;

	/** @var ScreenAccess */
	private $access;

	public function __construct( Registry $registry, ScreenAccess $access ) {
		$this->registry = $registry;
		$this->access   = $access;
	}

	/**
	 * @return array<int, array<string, mixed>> Lista de ScreenNode|GroupNode, já filtrada e ordenada.
	 */
	public function build(): array {
		$screens = array_values(
			array_filter(
				$this->registry->screens(),
				static function ( Screen $screen ): bool {
					return ! $screen->hidden();
				}
			)
		);

		$usesGroups = false;
		foreach ( $screens as $screen ) {
			if ( null !== $screen->group() ) {
				$usesGroups = true;
				break;
			}
		}

		if ( ! $usesGroups ) {
			return $this->buildFlat( $screens );
		}

		return $this->buildGrouped( $screens );
	}

	/**
	 * @param Screen[] $screens
	 * @return array<int, array<string, mixed>>
	 */
	private function buildFlat( array $screens ): array {
		$sortable = array();

		foreach ( $screens as $anchor => $screen ) {
			if ( ! $this->access->canView( $screen->permission() ) ) {
				continue;
			}

			$order = $screen->order() ?? $anchor;

			$sortable[] = array(
				'sort_key' => array( $order, $anchor ),
				'node'     => $this->screenNode( $screen ),
			);
		}

		usort(
			$sortable,
			static function ( array $left, array $right ): int {
				return $left['sort_key'] <=> $right['sort_key'];
			}
		);

		return array_column( $sortable, 'node' );
	}

	/**
	 * @param Screen[] $screens
	 * @return array<int, array<string, mixed>>
	 */
	private function buildGrouped( array $screens ): array {
		$topLevel     = array();
		$groupScreens = array();
		$anchor       = 0;

		foreach ( $screens as $screen ) {
			$group = $screen->group();

			if ( null === $group ) {
				$topLevel[] = array(
					'type'   => 'screen',
					'screen' => $screen,
					'key'    => null,
					'anchor' => $anchor,
				);
				++$anchor;
				continue;
			}

			if ( ! isset( $groupScreens[ $group ] ) ) {
				$groupScreens[ $group ] = array();
				$topLevel[]             = array(
					'type'   => 'group',
					'screen' => null,
					'key'    => $group,
					'anchor' => $anchor,
				);
				++$anchor;
			}

			// Chave: posição de inserção dentro do próprio grupo — a mesma
			// escala usada como fallback para ordenar as telas do grupo entre si.
			$groupScreens[ $group ][] = array(
				'screen' => $screen,
				'anchor' => count( $groupScreens[ $group ] ),
			);
		}

		$sortable = array();

		foreach ( $topLevel as $entry ) {
			if ( 'screen' === $entry['type'] ) {
				$screen = $entry['screen'];

				if ( null === $screen || ! $this->access->canView( $screen->permission() ) ) {
					continue;
				}

				// Mesma escala do grupo abaixo: é isto que permite uma tela
				// solta com `order` declarada cair entre dois grupos.
				$order = $screen->order() ?? $entry['anchor'];

				$sortable[] = array(
					'sort_key' => array( $order, $entry['anchor'] ),
					'node'     => $this->screenNode( $screen ),
				);
				continue;
			}

			$key = $entry['key'];

			if ( null === $key ) {
				continue;
			}

			$visibleSortable = array();

			foreach ( $groupScreens[ $key ] as $grouped ) {
				$groupedScreen = $grouped['screen'];

				if ( ! $this->access->canView( $groupedScreen->permission() ) ) {
					continue;
				}

				$groupedOrder = $groupedScreen->order() ?? $grouped['anchor'];

				$visibleSortable[] = array(
					'sort_key' => array( $groupedOrder, $grouped['anchor'] ),
					'node'     => $this->screenNode( $groupedScreen ),
				);
			}

			// Regra 1: grupo vazio some.
			if ( array() === $visibleSortable ) {
				continue;
			}

			usort(
				$visibleSortable,
				static function ( array $left, array $right ): int {
					return $left['sort_key'] <=> $right['sort_key'];
				}
			);

			$visible = array_column( $visibleSortable, 'node' );

			$declared = $this->registry->findGroup( $key );
			$label    = null !== $declared ? $declared->label() : $key;
			$order    = null !== $declared ? $declared->order() : $entry['anchor'];

			$sortable[] = array(
				'sort_key' => array( $order, $entry['anchor'] ),
				// Regra 2: mesmo com uma tela só em $visible, o nó continua sendo grupo.
				'node'     => array(
					'type'    => 'group',
					'key'     => $key,
					'label'   => $label,
					'screens' => $visible,
				),
			);
		}

		usort(
			$sortable,
			static function ( array $left, array $right ): int {
				return $left['sort_key'] <=> $right['sort_key'];
			}
		);

		return array_column( $sortable, 'node' );
	}

	/**
	 * @return array{type: 'screen', slug: string, label: string}
	 */
	private function screenNode( Screen $screen ): array {
		return array(
			'type'  => 'screen',
			'slug'  => $screen->slug(),
			'label' => $screen->label(),
		);
	}
}
