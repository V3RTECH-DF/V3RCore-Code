<?php
declare(strict_types=1);

namespace V3R\Core\Roles\Storage;

/**
 * Implementação de produção do `RoleAssignmentStoreInterface` sobre
 * `get_user_meta()`/`update_user_meta()`/`delete_user_meta()`. `$metaKey` é
 * o nome do meta — configuração do produto (docs/papeis-orientados-a-dados.md
 * §4), nunca fixo aqui, para dois consumidores no mesmo WordPress não
 * dividirem o mesmo vínculo.
 */
final class WordPressUserMetaStore implements RoleAssignmentStoreInterface {

	/** @var string */
	private $metaKey;

	public function __construct( string $metaKey ) {
		if ( '' === $metaKey ) {
			throw new \InvalidArgumentException( 'metaKey não pode ser vazio — sem ele dois consumidores no mesmo site dividiriam o mesmo vínculo.' );
		}

		$this->metaKey = $metaKey;
	}

	/**
	 * @return mixed
	 */
	public function getRoles( int $userId ) {
		return get_user_meta( $userId, $this->metaKey, true );
	}

	public function setRoles( int $userId, array $slugs ): void {
		update_user_meta( $userId, $this->metaKey, $slugs );
	}

	public function deleteRoles( int $userId ): void {
		delete_user_meta( $userId, $this->metaKey );
	}
}
