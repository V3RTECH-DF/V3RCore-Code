<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Fixtures;

/**
 * Par de chaves ed25519 GERADO SÓ PARA TESTE deste repositório — não é a
 * chave de nenhum ambiente real, e não deve ser usado fora dos testes.
 * Gerado uma única vez com `sodium_crypto_sign_keypair()`.
 */
final class Ed25519TestKeys {

	public const PUBLIC_KEY_BASE64 = '9yKxCLWVIeYybmG5AbpmIzOwxlLAAegDWl0FKZAZrgk=';

	public const SECRET_KEY_BASE64 = 'jFQul7zcmtWd/dEQ3KqSzQ6dqxr23hxxze1KpAVRYIT3IrEItZUh5jJuYbkBumYjM7DGUsAB6ANaXQUpkBmuCQ==';

	private function __construct() {
		// Classe estática — não instanciável.
	}
}
