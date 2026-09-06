<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * As duas famílias de produto que definem o ícone da entrada única no
 * menu do painel (docs/navegacao-do-painel.md §6) — nunca o ícone do
 * produto individual.
 *
 * Constantes de classe, e não enum: o padrão que a biblioteca inteira usa
 * (ver Licensing\LicenseStatus, Signing\SigningMode) — migrar todas de
 * uma vez é assunto próprio, não decisão a tomar de passagem numa peça
 * nova.
 */
final class Family {

	public const RIT = 'rit';

	public const V3RTECH = 'v3rtech';

	/** @return string[] */
	public static function all(): array {
		return array( self::RIT, self::V3RTECH );
	}

	public static function isValid( string $family ): bool {
		return in_array( $family, self::all(), true );
	}

	/** Nome do arquivo SVG em `src/Assets/brand/`, sem o caminho. */
	public static function iconFileName( string $family ): string {
		return 'familia-' . $family . '.svg';
	}

	private function __construct() {
		// Classe estática — não instanciável.
	}
}
