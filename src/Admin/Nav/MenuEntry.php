<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * A entrada única que o plugin declara no menu do WordPress
 * (docs/navegacao-do-painel.md §6). A família define o ícone; posição na
 * coluna e reordenação entre blocos são da V3RCore-Code#25 — fora do
 * escopo desta camada.
 */
final class MenuEntry {

	/** @var string */
	private $title;

	/** @var string */
	private $slug;

	/** @var string */
	private $family;

	/**
	 * @param string $title
	 * @param string $slug
	 * @param string $family Um dos valores de `Family` (`Family::RIT`, `Family::V3RTECH`).
	 *
	 * @throws \InvalidArgumentException `title`/`slug` vazios, ou `family` desconhecida.
	 */
	public function __construct( string $title, string $slug, string $family ) {
		if ( '' === trim( $title ) ) {
			throw new \InvalidArgumentException( 'MenuEntry::title não pode ser vazio.' );
		}

		if ( '' === trim( $slug ) ) {
			throw new \InvalidArgumentException( 'MenuEntry::slug não pode ser vazio.' );
		}

		if ( ! Family::isValid( $family ) ) {
			throw new \InvalidArgumentException( "MenuEntry::family desconhecida: '{$family}'. Use uma constante de Family." );
		}

		$this->title  = $title;
		$this->slug   = $slug;
		$this->family = $family;
	}

	public function title(): string {
		return $this->title;
	}

	public function slug(): string {
		return $this->slug;
	}

	public function family(): string {
		return $this->family;
	}
}
