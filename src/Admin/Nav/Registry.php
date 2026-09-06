<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * Acumula telas e grupos declarados por qualquer parte do plugin, em
 * qualquer ordem — sem lista central (docs/navegacao-do-painel.md §2).
 * `add()`/`addGroup()` são chamados quantas vezes for preciso, de módulos
 * diferentes, e a ordem de chamada não importa para o resultado final: a
 * árvore (TreeBuilder) ordena por `Group::order()` e pela ordem de
 * inserção dentro de cada grupo.
 *
 * Duas partes do plugin declarando telas com a mesma chave de grupo
 * produzem um grupo só, porque o agrupamento nasce da chave (string) que
 * cada `Screen::group()` carrega — não existe uma segunda fonte de
 * verdade para "quais telas pertencem ao grupo X". `addGroup()` é só o
 * metadado (rótulo, ordem); chamá-lo mais de uma vez para a mesma chave é
 * tolerado — a última chamada é a que vale, mesmo critério usado pelo
 * resto da biblioteca para configuração acumulativa sem lista central.
 */
final class Registry {

	/** @var Screen[] */
	private $screens = array();

	/** @var array<string, Group> */
	private $groups = array();

	public function add( Screen $screen ): void {
		$this->screens[] = $screen;
	}

	public function addGroup( Group $group ): void {
		$this->groups[ $group->key() ] = $group;
	}

	/**
	 * Todas as telas, na ordem em que foram declaradas.
	 *
	 * @return Screen[]
	 */
	public function screens(): array {
		return $this->screens;
	}

	public function findScreen( string $slug ): ?Screen {
		foreach ( $this->screens as $screen ) {
			if ( $screen->slug() === $slug ) {
				return $screen;
			}
		}

		return null;
	}

	public function findGroup( string $key ): ?Group {
		return $this->groups[ $key ] ?? null;
	}
}
