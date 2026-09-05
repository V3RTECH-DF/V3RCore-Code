<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

/**
 * O material de certificado entregue a um SignerInterface no momento de
 * assinar: caminho do arquivo do certificado (já decifrado e entregue em
 * local seguro — ver EphemeralSecretFile) e a senha em texto pleno (já
 * decifrada por CertificateSecretVault). Nunca persistido; existe só pela
 * duração da chamada a sign().
 */
final class CertificateMaterial {

	/** @var string */
	private $certificateFilePath;

	/** @var string */
	private $password;

	public function __construct( string $certificateFilePath, string $password ) {
		$this->certificateFilePath = $certificateFilePath;
		$this->password            = $password;
	}

	public function certificateFilePath(): string {
		return $this->certificateFilePath;
	}

	public function password(): string {
		return $this->password;
	}
}
