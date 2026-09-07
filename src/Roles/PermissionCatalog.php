<?php
declare(strict_types=1);

namespace V3R\Core\Roles;

/**
 * Constrói o catálogo plano de permissões a partir do que o produto declara
 * — nunca o contrário. O vocabulário (quais módulos existem, o que cada um
 * significa) é do produto (docs/papeis-orientados-a-dados.md §2); esta
 * classe só aplica a gramática comum: cada módulo declarado gera as duas
 * permissões `<módulo>.view` e `<módulo>.manage`, e `$extraPermissions`
 * carrega o que não segue essa forma (ex.: `dashboard.view`, que os dois
 * produtos de origem tratam como implícita de "entrar", não como módulo).
 *
 * Função pura, sem estado: um produto que já monta o catálogo à mão (as
 * duas implementações de origem faziam isso, cada uma com o próprio loop)
 * pode trocar por uma chamada a `build()` sem mudar o resultado.
 */
final class PermissionCatalog {

	/**
	 * @param string[] $modules           Slugs de módulo declarados pelo produto.
	 * @param string[] $extraPermissions  Permissões avulsas, fora da gramática `.view`/`.manage`.
	 *
	 * @return string[] Catálogo plano, sem duplicatas, na ordem: extras primeiro, depois os módulos.
	 */
	public static function build( array $modules, array $extraPermissions = array() ): array {
		$permissions = $extraPermissions;

		foreach ( $modules as $module ) {
			$permissions[] = "{$module}.view";
			$permissions[] = "{$module}.manage";
		}

		return array_values( array_unique( $permissions ) );
	}
}
