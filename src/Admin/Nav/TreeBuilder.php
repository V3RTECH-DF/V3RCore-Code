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
 * Fora dessas três, a ordenação de telas sem grupo misturadas com telas
 * agrupadas não é parte do contrato — aqui elas entram pela ordem de
 * declaração, e um grupo sem `Group` registrado (só citado via
 * `Screen::group()`) usa essa mesma posição como ordem, para não
 * desaparecer nem pular para o fim da lista.
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
		$screens = $this->registry->screens();

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
		$nodes = array();

		foreach ( $screens as $screen ) {
			if ( $this->access->canView( $screen->permission() ) ) {
				$nodes[] = $this->screenNode( $screen );
			}
		}

		return $nodes;
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

			$groupScreens[ $group ][] = $screen;
		}

		$sortable = array();

		foreach ( $topLevel as $entry ) {
			if ( 'screen' === $entry['type'] ) {
				$screen = $entry['screen'];

				if ( null === $screen || ! $this->access->canView( $screen->permission() ) ) {
					continue;
				}

				$sortable[] = array(
					'sort_key' => array( $entry['anchor'], $entry['anchor'] ),
					'node'     => $this->screenNode( $screen ),
				);
				continue;
			}

			$key = $entry['key'];

			if ( null === $key ) {
				continue;
			}

			$visible = array();

			foreach ( $groupScreens[ $key ] as $groupedScreen ) {
				if ( $this->access->canView( $groupedScreen->permission() ) ) {
					$visible[] = $this->screenNode( $groupedScreen );
				}
			}

			// Regra 1: grupo vazio some.
			if ( array() === $visible ) {
				continue;
			}

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
