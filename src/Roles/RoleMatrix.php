<?php
declare(strict_types=1);

namespace V3R\Core\Roles;

use V3R\Core\Licensing\Storage\KeyValueStoreInterface;

/**
 * A matriz de papéis guardada — `slug => [label, description, permissions[]]`
 * — sobre qualquer `KeyValueStoreInterface`, com os três cuidados que as duas
 * implementações de origem (V3RLGPD, RIT360 Premiado) já tinham e que
 * precisam sobreviver à promoção (docs/papeis-orientados-a-dados.md):
 *
 * 1. **Rótulo e descrição vêm SEMPRE do código** — `$defaultRoles`, que o
 *    produto fornece — mesmo quando a matriz já está guardada. O guardado
 *    manda nas permissões, nunca nos textos; é o que faz renomear um
 *    papel-modelo ter efeito em instalação já semeada.
 * 2. **Semeadura idempotente** — `seedIfMissing()` só grava quando a chave
 *    ainda não existe ou está vazia; nunca sobrescreve um valor customizado.
 * 3. **Módulo novo aparece nos papéis já semeados** — `reconcileModule()`
 *    generaliza `ensure_module_seeded()`/`backfill_*_permissions()`: para
 *    cada papel já guardado, acrescenta as permissões daquele módulo que
 *    `$defaultRoles` prevê para ele. Papel cujos padrões não incluem o
 *    módulo não ganha nada — sem lista de papéis elegíveis à parte, o
 *    próprio `$defaultRoles` já é a lista.
 *
 * **A matriz é lida do armazenamento no máximo uma vez por instância** — a
 * primeira chamada a qualquer método público dispara `$store->get()`; as
 * seguintes reusam o valor em memória. Instância nova (nova requisição) lê
 * de novo; instância reaproveitada dentro da mesma requisição não.
 */
final class RoleMatrix {

	/** @var KeyValueStoreInterface */
	private $store;

	/** @var string */
	private $key;

	/** @var array<string, array{label: string, description: string, permissions: string[]}> */
	private $defaultRoles;

	/** @var bool */
	private $loaded = false;

	/**
	 * O que veio do armazenamento, tal como veio — sem garantia de forma.
	 * `$defaultRoles` (tipado acima) é a única fonte de verdade sobre o
	 * formato esperado; o guardado pode ser dado antigo, incompleto ou (em
	 * teoria) corrompido, e o resto da classe confere antes de usar.
	 *
	 * @var array<string, mixed>
	 */
	private $stored = array();

	/**
	 * @param KeyValueStoreInterface                                                          $store
	 * @param string                                                                          $key
	 * @param array<string, array{label: string, description: string, permissions: string[]}> $defaultRoles
	 *        Os papéis-modelo — decisão de produto, nunca desta biblioteca.
	 *
	 * @throws \InvalidArgumentException `$key` vazia.
	 */
	public function __construct( KeyValueStoreInterface $store, string $key, array $defaultRoles ) {
		if ( '' === $key ) {
			throw new \InvalidArgumentException( 'key não pode ser vazia — sem ela dois consumidores no mesmo site dividiriam a mesma matriz.' );
		}

		$this->store        = $store;
		$this->key          = $key;
		$this->defaultRoles = $defaultRoles;
	}

	/**
	 * A matriz vigente: o guardado, com rótulo e descrição sempre
	 * reconciliados a partir de `$defaultRoles`. Sem nada guardado, os
	 * próprios padrões.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$this->ensureLoaded();

		if ( empty( $this->stored ) ) {
			return $this->defaultRoles;
		}

		$merged = $this->stored;

		foreach ( $this->defaultRoles as $slug => $default ) {
			if ( isset( $merged[ $slug ] ) && is_array( $merged[ $slug ] ) ) {
				$merged[ $slug ]['label']       = $default['label'];
				$merged[ $slug ]['description'] = $default['description'];
			}
		}

		return $merged;
	}

	public function exists( string $slug ): bool {
		return array_key_exists( $slug, $this->all() );
	}

	/**
	 * @return string[]
	 */
	public function permissionsOf( string $slug ): array {
		$roles = $this->all();

		return isset( $roles[ $slug ]['permissions'] ) && is_array( $roles[ $slug ]['permissions'] )
			? $roles[ $slug ]['permissions']
			: array();
	}

	/** Grava `$defaultRoles` se a chave ainda não existir ou estiver vazia. Idempotente. */
	public function seedIfMissing(): void {
		$this->ensureLoaded();

		if ( ! empty( $this->stored ) ) {
			return;
		}

		$this->store->set( $this->key, $this->defaultRoles );
		$this->stored = $this->defaultRoles;
	}

	/**
	 * Para cada papel JÁ GUARDADO, acrescenta as permissões de `$module`
	 * (prefixo `"$module."`) que `$defaultRoles` prevê para aquele mesmo
	 * papel e que ainda não estão presentes. Sem efeito em instalação nova
	 * (nada guardado ainda — `seedIfMissing()`/a semeadura inicial já
	 * incluem o módulo, porque partem de `$defaultRoles`). Idempotente:
	 * rodar de novo não duplica.
	 */
	public function reconcileModule( string $module ): void {
		$this->ensureLoaded();

		if ( empty( $this->stored ) ) {
			return;
		}

		$prefix  = "{$module}.";
		$changed = false;

		foreach ( $this->stored as $slug => $definition ) {
			if ( ! isset( $this->defaultRoles[ $slug ] ) ) {
				continue;
			}

			if ( ! isset( $definition['permissions'] ) || ! is_array( $definition['permissions'] ) ) {
				continue;
			}

			$granted = $definition['permissions'];

			foreach ( $this->defaultRoles[ $slug ]['permissions'] as $permission ) {
				if ( 0 === strpos( $permission, $prefix ) && ! in_array( $permission, $granted, true ) ) {
					$granted[] = $permission;
					$changed   = true;
				}
			}

			$this->stored[ $slug ]['permissions'] = $granted;
		}

		if ( $changed ) {
			$this->store->set( $this->key, $this->stored );
		}
	}

	private function ensureLoaded(): void {
		if ( $this->loaded ) {
			return;
		}

		$raw = $this->store->get( $this->key );

		$this->stored = is_array( $raw ) ? $raw : array();
		$this->loaded = true;
	}
}
