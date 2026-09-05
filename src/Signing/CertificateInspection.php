<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

use DateTimeImmutable;

/**
 * Resultado de `CertificateInspector::inspect()` — objeto imutável
 * (V3RCore-Code#29).
 */
final class CertificateInspection {

	/** @var bool */
	private $ok;

	/** @var DateTimeImmutable|null */
	private $expiresAt;

	/** @var string|null */
	private $error;

	/** @var CertificateSubject|null */
	private $subject;

	private function __construct( bool $ok, ?DateTimeImmutable $expiresAt, ?string $error, ?CertificateSubject $subject ) {
		$this->ok        = $ok;
		$this->expiresAt = $expiresAt;
		$this->error     = $error;
		$this->subject   = $subject;
	}

	public static function success( ?DateTimeImmutable $expiresAt, ?CertificateSubject $subject = null ): self {
		return new self( true, $expiresAt, null, $subject );
	}

	public static function failure( string $error ): self {
		return new self( false, null, $error, null );
	}

	public function isOk(): bool {
		return $this->ok;
	}

	/**
	 * Null quando o certificado não tem validade reconhecida — conservador,
	 * nunca "certificado bom" por omissão. É esse `null` que faz
	 * `SigningModeResolver::decide()` cair em `SigningModeReason::SEM_VALIDADE_CONHECIDA`.
	 */
	public function expiresAt(): ?DateTimeImmutable {
		return $this->expiresAt;
	}

	/**
	 * O titular lido do certificado — null quando não foi possível
	 * determinar quem é. Nunca um nome presumido.
	 */
	public function subject(): ?CertificateSubject {
		return $this->subject;
	}

	public function error(): ?string {
		return $this->error;
	}
}
