<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Signing\Support;

use V3R\Core\Signing\CertificateMaterial;
use V3R\Core\Signing\SignerInterface;
use V3R\Core\Signing\SigningException;

/**
 * Implementação mínima de SignerInterface, só para provar em teste que o
 * contrato é usável por quem implementa — a biblioteca não traz nenhuma
 * implementação real (issue #27: quem assina de verdade é o plugin).
 */
final class FakeSigner implements SignerInterface {

	/** @var string|null Senha que faz sign() falhar com SENHA_INVALIDA, se houver. */
	private $senhaQueFalha;

	public function __construct( ?string $senhaQueFalha = null ) {
		$this->senhaQueFalha = $senhaQueFalha;
	}

	public function sign( string $unsignedFilePath, CertificateMaterial $material ): string {
		if ( null !== $this->senhaQueFalha && $this->senhaQueFalha === $material->password() ) {
			throw new SigningException( SigningException::SENHA_INVALIDA, 'FakeSigner: senha incorreta.' );
		}

		$assinado = $unsignedFilePath . '.signed';
		copy( $unsignedFilePath, $assinado );

		return $assinado;
	}
}
