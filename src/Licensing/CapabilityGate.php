<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

/**
 * Concede as capabilities sintéticas de licença (leitura/gestão) via
 * `user_has_cap`, com a guarda de saída antecipada embutida e
 * inescapável — é o que fecha V3RCore-Code#12 (a lib exigia as
 * capabilities e deixava cada plugin se defender sozinho contra a
 * recursão `user_has_cap → user_can → user_has_cap`).
 *
 * **Contrato com o plugin hospedeiro:** a função de decisão passada ao
 * construtor SÓ é chamada quando `$caps` (as capabilities pedidas na
 * chamada corrente a `current_user_can()`/`user_can()`) contém a
 * capability de leitura ou a de gestão desta licença. Para qualquer
 * outra capability — incluindo `manage_options`, que o RBAC do plugin
 * hospedeiro tipicamente consulta por dentro da própria função de
 * decisão — `grant()` devolve `$allcaps` sem tocar na função de decisão.
 * É essa saída antecipada, e não a função de decisão, que quebra o
 * ciclo: quando `user_can($uid, 'manage_options')` reentra em
 * `user_has_cap`, `$caps` pedido é `['manage_options']`, a guarda não
 * encontra nenhuma capability de licença ali e sai na hora — sem chamar
 * a função de decisão de novo. O plugin pode chamar
 * `user_can()`/`current_user_can()` à vontade dentro da função de
 * decisão sem criar recursão.
 */
final class CapabilityGate {

	/** @var string */
	private $readCapability;

	/** @var string */
	private $manageCapability;

	/** @var callable */
	private $decider;

	/** @var bool */
	private $registered;

	/**
	 * @param string   $readCapability   Capability de leitura (docs/api-contract.md §8.2).
	 * @param string   $manageCapability Capability de gestão (docs/api-contract.md §8.2).
	 * @param callable $decider          `function( int $userId, string $capability ): bool`.
	 *                                   Responde se $userId pode $capability (a de
	 *                                   leitura ou a de gestão — nunca chamada para
	 *                                   outra). Aqui, e só aqui, o plugin hospedeiro
	 *                                   consulta o próprio RBAC.
	 */
	public function __construct( string $readCapability, string $manageCapability, callable $decider ) {
		$this->readCapability   = $readCapability;
		$this->manageCapability = $manageCapability;
		$this->decider          = $decider;
		$this->registered       = false;
	}

	/**
	 * Registra o filtro `user_has_cap`. Idempotente e seguro fora do
	 * WordPress (mesmo padrão de Updater\UpdateChecker::register()) — em
	 * teste de unidade sem `add_filter()` disponível, é um no-op.
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'user_has_cap', array( $this, 'grant' ), 10, 4 );

		$this->registered = true;
	}

	/**
	 * Callback de `user_has_cap`. A guarda das linhas abaixo — devolver
	 * $allcaps sem chamar $this->decider — roda ANTES de qualquer
	 * consulta ao plugin hospedeiro; é ela, não a função de decisão, que
	 * garante que a reentrância nunca chega ao RBAC do hospedeiro.
	 *
	 * @param array<string, bool> $allcaps
	 * @param array<int, string>  $caps
	 * @param array<int, mixed>   $args
	 * @param \WP_User|null       $user
	 * @return array<string, bool>
	 */
	public function grant( array $allcaps, array $caps, array $args, $user = null ): array {
		$relevant = array_intersect( $caps, array( $this->readCapability, $this->manageCapability ) );

		if ( empty( $relevant ) ) {
			return $allcaps;
		}

		$userId = isset( $args[1] ) ? (int) $args[1] : ( isset( $user->ID ) ? (int) $user->ID : 0 );

		foreach ( $relevant as $capability ) {
			$allcaps[ $capability ] = (bool) call_user_func( $this->decider, $userId, $capability );
		}

		return $allcaps;
	}
}
