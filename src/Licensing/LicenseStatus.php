<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

/**
 * Valores possíveis de status de uma licença.
 *
 * PHP 7.4 não tem enum — usamos constantes de classe, o padrão da casa
 * para esse caso.
 */
final class LicenseStatus {

	public const ACTIVE  = 'active';
	public const EXPIRED = 'expired';
	public const REVOKED = 'revoked';
	public const INVALID = 'invalid';

	/**
	 * Nenhuma ativação ainda foi feita neste site.
	 */
	public const INACTIVE = 'inactive';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::ACTIVE, self::EXPIRED, self::REVOKED, self::INVALID, self::INACTIVE );
	}

	public static function isValid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	private function __construct() {
		// Classe estática — não instanciável.
	}
}
