<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Roles\Support;

use V3R\Core\Roles\Storage\RoleAssignmentStoreInterface;

/**
 * `RoleAssignmentStoreInterface` em memória, para teste. `seedLegacy()`
 * simula uma instalação anterior à E13 do RIT360 Premiado, onde o meta
 * guardava uma string única em vez de uma lista.
 */
final class InMemoryRoleAssignmentStore implements RoleAssignmentStoreInterface {

	/** @var array<int, mixed> */
	private $values = array();

	/**
	 * @return mixed
	 */
	public function getRoles( int $userId ) {
		return $this->values[ $userId ] ?? null;
	}

	public function setRoles( int $userId, array $slugs ): void {
		$this->values[ $userId ] = $slugs;
	}

	public function deleteRoles( int $userId ): void {
		unset( $this->values[ $userId ] );
	}

	/** Grava diretamente o formato legado (string única), sem passar por `setRoles()`. */
	public function seedLegacy( int $userId, string $slug ): void {
		$this->values[ $userId ] = $slug;
	}
}
