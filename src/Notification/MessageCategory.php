<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

/**
 * Distingue comunicação essencial (nunca pode ser suprimida pela
 * preferência do destinatário — ex.: aviso de credencial revogada) de
 * dispensável (o destinatário pode optar por não receber — ex.: cobrança de
 * pendência). Ver RecipientPreferenceInterface.
 *
 * PHP 7.4 não tem enum — constantes de classe, o padrão da casa para esse
 * caso (mesmo critério de V3R\Core\Licensing\LicenseStatus).
 */
final class MessageCategory {

	public const ESSENTIAL   = 'essential';
	public const DISPENSABLE = 'dispensable';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::ESSENTIAL, self::DISPENSABLE );
	}

	public static function isValid( string $category ): bool {
		return in_array( $category, self::all(), true );
	}

	private function __construct() {
		// Classe estática — não instanciável.
	}
}
