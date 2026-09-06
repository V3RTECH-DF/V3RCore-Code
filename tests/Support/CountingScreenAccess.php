<?php
/**
 * `ScreenAccess` de teste: concede exatamente as permissões listadas em
 * `$granted` e conta quantas vezes cada permissão distinta foi consultada
 * — usado para provar (V3RCore-Code#35) que a árvore e o gate de acesso
 * direto derivam da MESMA declaração, e que uma implementação de
 * `ScreenAccess` pode não usar `current_user_can()` nenhuma vez.
 */

declare(strict_types=1);

namespace V3R\Core\Tests\Support;

use V3R\Core\Admin\Nav\ScreenAccess;

final class CountingScreenAccess implements ScreenAccess {

	/** @var string[] */
	private $granted;

	/** @var array<string, int> */
	private $calls = array();

	/**
	 * @param string[] $granted Permissões que esta instância concede.
	 */
	public function __construct( array $granted ) {
		$this->granted = $granted;
	}

	public function canView( string $permission ): bool {
		$this->calls[ $permission ] = ( $this->calls[ $permission ] ?? 0 ) + 1;

		return in_array( $permission, $this->granted, true );
	}

	public function callsFor( string $permission ): int {
		return $this->calls[ $permission ] ?? 0;
	}
}
