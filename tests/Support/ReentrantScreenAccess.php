<?php
/**
 * `ScreenAccess` de teste que provoca a reentrância real do WordPress: ao
 * ser consultado sobre a permissão-gatilho, pergunta de novo pela
 * capability de raiz ANTES de responder — exatamente como
 * `current_user_can()` faria, disparando o filtro `user_has_cap` inteiro
 * de novo (com todos os plugins do site dentro dele) no meio da varredura
 * de `NavCapabilityGate::hasAnyVisibleScreen()`.
 *
 * Usado para provar o defeito de 07/09/2026: a versão antiga publicava
 * `false` no início do cálculo, e essa consulta reentrante recebia o
 * `false` provisório como se fosse a resposta final.
 */

declare(strict_types=1);

namespace V3R\Core\Tests\Support;

use V3R\Core\Admin\Nav\ScreenAccess;

final class ReentrantScreenAccess implements ScreenAccess {

	/** @var string[] Permissões concedidas por esta instância. */
	private $granted;

	/** @var string|null Permissão cuja consulta dispara a reentrância. */
	private $trigger;

	/**
	 * @var string A capability de raiz (já derivada, ver
	 * NavCapabilityGate::rootCapabilityFor()) usada na consulta reentrante
	 * — o teste que constrói esta classe decide de qual menu.
	 */
	private $rootCapability;

	/** @var array<string, int> Contagem de chamadas a canView(), por permissão. */
	private $calls = array();

	/**
	 * @var array<int, bool> Se a chave ROOT_CAPABILITY estava presente em
	 * $allcaps na volta de cada consulta reentrante, na ordem em que
	 * ocorreram — presença é o que distingue "respondeu algo" de
	 * "silêncio" (ver NavCapabilityGate::grant()).
	 */
	private $reentrantKeyWasPresent = array();

	/**
	 * @var array<int, bool|null> O valor de ROOT_CAPABILITY em cada
	 * consulta reentrante quando a chave estava presente — `null` quando
	 * a chave estava ausente (silêncio), na mesma ordem do array acima.
	 */
	private $reentrantValues = array();

	/**
	 * @param string[]    $granted
	 * @param string|null $trigger        Permissão cuja consulta dispara a reentrância.
	 * @param string      $rootCapability A capability de raiz já derivada
	 *                                    (`NavCapabilityGate::rootCapabilityFor( $menuSlug )`)
	 *                                    a ser perguntada na reentrância.
	 */
	public function __construct( array $granted, ?string $trigger = null, string $rootCapability = 'v3r_nav_root_plugin-a' ) {
		$this->granted        = $granted;
		$this->trigger        = $trigger;
		$this->rootCapability = $rootCapability;
	}

	public function canView( string $permission ): bool {
		$this->calls[ $permission ] = ( $this->calls[ $permission ] ?? 0 ) + 1;

		if ( null !== $this->trigger && $permission === $this->trigger ) {
			$allcaps = apply_filters(
				'user_has_cap',
				array(),
				array( $this->rootCapability ),
				array( $this->rootCapability, get_current_user_id() ),
				null
			);

			$present                        = array_key_exists( $this->rootCapability, $allcaps );
			$this->reentrantKeyWasPresent[] = $present;
			$this->reentrantValues[]        = $present ? $allcaps[ $this->rootCapability ] : null;
		}

		return in_array( $permission, $this->granted, true );
	}

	public function callsFor( string $permission ): int {
		return $this->calls[ $permission ] ?? 0;
	}

	public function triggerReentranceCount(): int {
		return $this->calls[ $this->trigger ] ?? 0;
	}

	/** @return array<int, bool> */
	public function reentrantKeyWasPresent(): array {
		return $this->reentrantKeyWasPresent;
	}

	/** @return array<int, bool|null> */
	public function reentrantValues(): array {
		return $this->reentrantValues;
	}
}
