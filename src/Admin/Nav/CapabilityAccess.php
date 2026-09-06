<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * Implementação padrão de `ScreenAccess`, sobre `current_user_can()` —
 * para quem usa capability nativa do WordPress (docs/navegacao-do-painel.md
 * §3: GE Associados, V3REvent, V3RLicense, V3RHelp, V3RProp).
 *
 * Cache por requisição: cada `$permission` distinta é consultada no
 * máximo uma vez por instância (que vive pela duração da requisição — a
 * mesma instância é compartilhada entre `TreeBuilder` e
 * `NavCapabilityGate` via `Navigation`). Nada aqui persiste entre
 * requisições; não há transient nem option envolvidos.
 */
final class CapabilityAccess implements ScreenAccess {

	/** @var array<string, bool> */
	private $cache = array();

	public function canView( string $permission ): bool {
		if ( array_key_exists( $permission, $this->cache ) ) {
			return $this->cache[ $permission ];
		}

		$allowed = function_exists( 'current_user_can' ) ? (bool) current_user_can( $permission ) : false;

		$this->cache[ $permission ] = $allowed;

		return $allowed;
	}
}
