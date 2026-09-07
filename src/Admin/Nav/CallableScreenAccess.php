<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * Adapta uma função `function( string $permission ): bool` para o contrato
 * `ScreenAccess` — é o que permite `Navigation` aceitar uma função no lugar
 * de exigir `implements ScreenAccess` (docs/navegacao-do-painel.md §3).
 *
 * **Por que a função é a forma recomendada, e a interface só alternativa:**
 * `implements` obriga o plugin a declarar a classe condicionalmente, porque
 * a biblioteca pode estar presente e ainda não prefixada (estado normal logo
 * após um clone, antes de alguém rodar a prefixação — `integracao-em-plugin.md`
 * §7). Escrever `implements` sobre uma interface ausente é fatal error na
 * ativação. `Bootstrap::withCapabilityDecider()` já resolve o mesmo problema
 * assim, para o mesmo motivo — este adaptador segue o padrão da casa.
 *
 * Não introduz consulta extra: repassa a chamada direto para a função, sem
 * cache próprio. Uma função lenta continua lenta nas duas formas — cachear
 * por requisição é responsabilidade de quem fornece o respondente (§3),
 * função ou objeto.
 */
final class CallableScreenAccess implements ScreenAccess {

	/** @var callable */
	private $decider;

	/**
	 * @param callable $decider `function( string $permission ): bool`.
	 */
	public function __construct( callable $decider ) {
		$this->decider = $decider;
	}

	public function canView( string $permission ): bool {
		return (bool) call_user_func( $this->decider, $permission );
	}
}
