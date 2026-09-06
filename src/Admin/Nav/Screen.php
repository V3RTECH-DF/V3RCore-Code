<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * Uma tela declarada por um plugin — a unidade que alimenta, ao mesmo
 * tempo, a navegação (o que aparece na árvore, ver TreeBuilder) e o gate
 * de acesso direto (o que a URL permite, ver NavCapabilityGate). É essa
 * dupla origem que torna impossível declarar uma coisa e esquecer a outra
 * (docs/navegacao-do-painel.md §1).
 *
 * Valor imutável: uma vez construído, nada muda. `group` é opcional —
 * ausente, a tela entra na árvore como item de primeiro nível (§5).
 */
final class Screen {

	/** @var string */
	private $slug;

	/** @var string */
	private $label;

	/** @var string|null */
	private $group;

	/** @var string */
	private $permission;

	/**
	 * @throws \InvalidArgumentException `slug`, `label` ou `permission` vazios.
	 */
	public function __construct( string $slug, string $label, ?string $group, string $permission ) {
		if ( '' === trim( $slug ) ) {
			throw new \InvalidArgumentException( 'Screen::slug não pode ser vazio.' );
		}

		if ( '' === trim( $label ) ) {
			throw new \InvalidArgumentException( 'Screen::label não pode ser vazio.' );
		}

		if ( '' === trim( $permission ) ) {
			throw new \InvalidArgumentException( 'Screen::permission não pode ser vazio.' );
		}

		$this->slug       = $slug;
		$this->label      = $label;
		$this->group      = ( null !== $group && '' !== trim( $group ) ) ? $group : null;
		$this->permission = $permission;
	}

	public function slug(): string {
		return $this->slug;
	}

	public function label(): string {
		return $this->label;
	}

	/** Chave do grupo ao qual a tela pertence, ou `null` — navegação plana. */
	public function group(): ?string {
		return $this->group;
	}

	/** A chave que o `ScreenAccess` do plugin entende (docs/navegacao-do-painel.md §3). */
	public function permission(): string {
		return $this->permission;
	}
}
