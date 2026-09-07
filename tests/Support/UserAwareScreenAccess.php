<?php
/**
 * `ScreenAccess` de teste que responde de acordo com o usuário corrente
 * (`$GLOBALS['v3r_core_test_current_user_id']`, o mesmo global que
 * `get_current_user_id()` lê em `PucFunctionStubs.php`) — usado para provar
 * que `NavCapabilityGate` nunca serve a resposta calculada para uma pessoa
 * a outra (V3RCore-Code, defeito medido no RIT360 Flow, 07/09/2026).
 *
 * Diferente de `CountingScreenAccess` (uma permissão fixa por instância,
 * representando uma pessoa só), esta classe simula várias pessoas
 * respondendo dentro da MESMA requisição, exatamente como o
 * `ScreenAccess` real (`current_user_can()`) faria ao mudar quem está
 * logado.
 */

declare(strict_types=1);

namespace V3R\Core\Tests\Support;

use V3R\Core\Admin\Nav\ScreenAccess;

final class UserAwareScreenAccess implements ScreenAccess {

	/** @var array<int, string[]> Permissões concedidas, por ID de usuário. */
	private $grantedByUser;

	/** @var array<int, array<string, int>> Contagem de chamadas, por ID de usuário e permissão. */
	private $calls = array();

	/**
	 * @param array<int, string[]> $grantedByUser
	 */
	public function __construct( array $grantedByUser ) {
		$this->grantedByUser = $grantedByUser;
	}

	public function canView( string $permission ): bool {
		$userId = $GLOBALS['v3r_core_test_current_user_id'] ?? 0;

		$this->calls[ $userId ][ $permission ] = ( $this->calls[ $userId ][ $permission ] ?? 0 ) + 1;

		$granted = $this->grantedByUser[ $userId ] ?? array();

		return in_array( $permission, $granted, true );
	}

	public function callsFor( int $userId, string $permission ): int {
		return $this->calls[ $userId ][ $permission ] ?? 0;
	}
}
