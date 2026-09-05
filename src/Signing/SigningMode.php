<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

/**
 * Os dois modos possíveis em que um documento pode ter sido assinado.
 *
 * Este valor é guardado junto do documento e exibido nele (issue #27) — não
 * é detalhe interno de implementação. É o que permite ao documento nunca
 * precisar ser adivinhado: quem abre o arquivo, imprime, ou recebe um scan
 * sabe, sem perguntar a ninguém, se aquilo foi assinado com certificado
 * digital da organização ou apenas registrado eletronicamente.
 *
 * Constantes de classe, e não enum: é o padrão que a biblioteca inteira
 * usa (ver Licensing\LicenseStatus), e um dialeto novo aqui obrigaria
 * quem consome a lidar com duas formas de dizer a mesma coisa. O piso da
 * biblioteca é PHP 8.2 e comportaria enum — migrar TODAS de uma vez é
 * assunto próprio, não decisão a tomar de passagem numa peça nova.
 */
final class SigningMode {

	/**
	 * Assinado com o certificado digital da organização — o modo pleno.
	 */
	public const CERTIFICADO_DIGITAL = 'certificado_digital';

	/**
	 * Sem certificado utilizável no momento da emissão: o documento foi
	 * apenas registrado eletronicamente. Modo degradado, mas nunca
	 * silencioso — SigningModeResolver sempre devolve também o motivo
	 * (SigningModeReason) de ter caído aqui.
	 */
	public const REGISTRO_ELETRONICO = 'registro_eletronico';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::CERTIFICADO_DIGITAL, self::REGISTRO_ELETRONICO );
	}

	public static function isValid( string $mode ): bool {
		return in_array( $mode, self::all(), true );
	}

	private function __construct() {
		// Classe estática — não instanciável.
	}
}
