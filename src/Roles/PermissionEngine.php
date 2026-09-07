<?php
declare(strict_types=1);

namespace V3R\Core\Roles;

use V3R\Core\Roles\Storage\RoleAssignmentStoreInterface;

/**
 * Fachada de resolução do motor de papéis orientados a dados
 * (docs/papeis-orientados-a-dados.md): "a pessoa tem um CONJUNTO de
 * papéis" — generalização do RIT360 Premiado (vários papéis, união de
 * permissões) que também cobre o V3RLGPD (conjunto de um elemento), sem o
 * produto normalizar nada.
 *
 * ⚠️ **Bypass de administrador resolvido uma vez por pessoa, não por
 * permissão.** Medido na adoção do V3RLGPD: `user_can()` chamado de dentro
 * do filtro `user_has_cap` REENTRA no filtro — com uma chamada ao
 * `$isAdmin` por permissão distinta consultada, isso vira N reentradas por
 * requisição, e já causou incidente em produção (esgotamento de memória,
 * toda requisição autenticada). `$isAdmin` aqui é chamado no máximo uma vez
 * por `$userId`, guardado em memória para o resto da vida da instância.
 *
 * ⚠️ **Nenhum estado estático.** Instância, injetável, testável sem
 * WordPress carregado — como o resto da biblioteca. `$isAdmin` é a ponte
 * (mesmo padrão de `Licensing\CapabilityGate`/`Admin\Nav\CallableScreenAccess`):
 * em produção, tipicamente `fn( int $userId ): bool => user_can( $userId,
 * 'manage_options' )`.
 */
final class PermissionEngine {

	/** @var RoleMatrix */
	private $matrix;

	/** @var RoleAssignmentStoreInterface */
	private $assignments;

	/** @var callable */
	private $isAdmin;

	/** @var string[] */
	private $sensitivePermissions;

	/** @var array<int, bool> */
	private $adminCache = array();

	/**
	 * @param RoleMatrix                   $matrix
	 * @param RoleAssignmentStoreInterface $assignments
	 * @param callable                     $isAdmin              `function( int $userId ): bool`.
	 * @param string[]                     $sensitivePermissions Permissões que o produto declara
	 *                                                            sensíveis (docs/papeis-orientados-a-dados.md
	 *                                                            §5) — vivem no papel como qualquer
	 *                                                            outra, mas a tela de edição não deve
	 *                                                            oferecê-las ao cliente.
	 */
	public function __construct(
		RoleMatrix $matrix,
		RoleAssignmentStoreInterface $assignments,
		callable $isAdmin,
		array $sensitivePermissions = array()
	) {
		$this->matrix               = $matrix;
		$this->assignments          = $assignments;
		$this->isAdmin              = $isAdmin;
		$this->sensitivePermissions = $sensitivePermissions;
	}

	/** O usuário tem a permissão? Administrador sempre sim (anti-tranca). */
	public function userCan( int $userId, string $permission ): bool {
		if ( $this->isAdministrator( $userId ) ) {
			return true;
		}

		$roles = $this->matrix->all();

		foreach ( $this->rolesOf( $userId ) as $slug ) {
			if ( in_array( $permission, $roles[ $slug ]['permissions'] ?? array(), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Papéis válidos do usuário, como lista de slugs — normaliza o formato
	 * legado (string única) e o atual (lista); descarta slugs desconhecidos
	 * (papel apagado ou nunca existiu não concede nada).
	 *
	 * @return string[]
	 */
	public function rolesOf( int $userId ): array {
		$raw = $this->assignments->getRoles( $userId );

		if ( is_array( $raw ) ) {
			$slugs = $raw;
		} else {
			$asString = null === $raw ? '' : (string) $raw;
			$slugs    = '' === $asString ? array() : array( $asString );
		}

		$out = array();

		foreach ( $slugs as $slug ) {
			$slug = (string) $slug;

			if ( '' !== $slug && $this->matrix->exists( $slug ) && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}

		return $out;
	}

	/** Administrador OU ao menos um papel válido (entra no produto). */
	public function hasAnyRole( int $userId ): bool {
		if ( $this->isAdministrator( $userId ) ) {
			return true;
		}

		return array() !== $this->rolesOf( $userId );
	}

	/**
	 * Lista plana das permissões efetivas — a união dos papéis. Administrador
	 * recebe o sentinela `'*'` (tudo), nunca a lista expandida.
	 *
	 * @return string[]
	 */
	public function permissionsOf( int $userId ): array {
		if ( $this->isAdministrator( $userId ) ) {
			return array( '*' );
		}

		$roles = $this->matrix->all();
		$perms = array();

		foreach ( $this->rolesOf( $userId ) as $slug ) {
			foreach ( $roles[ $slug ]['permissions'] ?? array() as $permission ) {
				if ( ! in_array( $permission, $perms, true ) ) {
					$perms[] = $permission;
				}
			}
		}

		return $perms;
	}

	/**
	 * Atribui um CONJUNTO de papéis à pessoa — aceita um ou vários. Valida
	 * cada slug contra a matriz (rejeita tudo e não grava nada se algum for
	 * desconhecido), deduplica e grava como lista. Lista vazia remove o
	 * vínculo.
	 *
	 * @param int      $userId
	 * @param string[] $slugs
	 */
	public function assignRoles( int $userId, array $slugs ): bool {
		$clean = array();

		foreach ( $slugs as $slug ) {
			$slug = (string) $slug;

			if ( '' === $slug ) {
				continue;
			}

			if ( ! $this->matrix->exists( $slug ) ) {
				return false;
			}

			if ( ! in_array( $slug, $clean, true ) ) {
				$clean[] = $slug;
			}
		}

		if ( empty( $clean ) ) {
			$this->assignments->deleteRoles( $userId );
		} else {
			$this->assignments->setRoles( $userId, $clean );
		}

		return true;
	}

	/**
	 * Sentinela conveniente para atribuir um papel só (compat com quem ainda
	 * pensa em "o papel da pessoa", no singular). `''` remove o vínculo.
	 */
	public function assignRole( int $userId, string $slug ): bool {
		return $this->assignRoles( $userId, '' === $slug ? array() : array( $slug ) );
	}

	/**
	 * As permissões sensíveis que o produto declarou (construtor) — para a
	 * tela de edição de papel excluir do que oferece ao cliente. Não
	 * consulta papel nenhum: é o catálogo bruto que o produto forneceu.
	 *
	 * @return string[]
	 */
	public function sensitivePermissions(): array {
		return $this->sensitivePermissions;
	}

	/**
	 * Liga este motor à camada de navegação (`Admin\Nav\Navigation`) em uma
	 * linha: `new Navigation( $registry, $engine->asScreenAccess( $userId ) )`
	 * — a forma que `Navigation`/`Bootstrap::withCapabilityDecider()` já
	 * aceitam desde a 0.18.0 (`function( string $permission ): bool`).
	 */
	public function asScreenAccess( int $userId ): callable {
		return function ( string $permission ) use ( $userId ): bool {
			return $this->userCan( $userId, $permission );
		};
	}

	private function isAdministrator( int $userId ): bool {
		if ( ! array_key_exists( $userId, $this->adminCache ) ) {
			$this->adminCache[ $userId ] = (bool) call_user_func( $this->isAdmin, $userId );
		}

		return $this->adminCache[ $userId ];
	}
}
