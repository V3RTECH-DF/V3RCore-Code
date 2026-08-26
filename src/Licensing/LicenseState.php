<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

use DateTimeImmutable;
use JsonSerializable;
use V3R\Core\Support\LicenseKeyMasker;

/**
 * Retrato imutável do estado de licenciamento de um site, num instante.
 *
 * Decide, a partir dos dados que carrega, se a licença está válida, se está
 * dentro do período de graça e há quantos dias vence — mas NÃO decide se o
 * update deve ser entregue agora (isso é do V3R\Core\Updater\UpdateGate,
 * que aplica a regra de negócio temporal completa).
 *
 * Objeto imutável: qualquer alteração produz uma nova instância (métodos
 * with*()); nenhum método muta o objeto corrente.
 */
final class LicenseState implements JsonSerializable {

	/** @var string */
	private $key;

	/** @var string */
	private $status;

	/** @var DateTimeImmutable|null */
	private $expiresAt;

	/** @var int */
	private $activationsUsed;

	/**
	 * Null significa "ilimitado".
	 *
	 * @var int|null
	 */
	private $activationsMax;

	/** @var DateTimeImmutable|null */
	private $lastCheckedAt;

	/**
	 * Instante-limite do período de graça (normalmente lastCheckedAt do
	 * último contato bem-sucedido + 14 dias). Null quando não há grace
	 * period em curso (ou porque nunca houve falha de contato, ou porque
	 * a licença nunca foi ativada).
	 *
	 * @var DateTimeImmutable|null
	 */
	private $graceUntil;

	/** @var string */
	private $productSlug;

	public function __construct(
		string $key,
		string $status,
		?DateTimeImmutable $expiresAt,
		int $activationsUsed,
		?int $activationsMax,
		?DateTimeImmutable $lastCheckedAt,
		?DateTimeImmutable $graceUntil,
		string $productSlug
	) {
		$this->key             = $key;
		$this->status          = LicenseStatus::isValid( $status ) ? $status : LicenseStatus::INVALID;
		$this->expiresAt       = $expiresAt;
		$this->activationsUsed = $activationsUsed;
		$this->activationsMax  = $activationsMax;
		$this->lastCheckedAt   = $lastCheckedAt;
		$this->graceUntil      = $graceUntil;
		$this->productSlug     = $productSlug;
	}

	/**
	 * Estado neutro para um site que nunca ativou a licença — o estado que
	 * o esqueleto (fatia 1) devolve onde a lógica de rede ainda não existe.
	 */
	public static function neutral( string $productSlug ): self {
		return new self( '', LicenseStatus::INACTIVE, null, 0, null, null, null, $productSlug );
	}

	public function getKey(): string {
		return $this->key;
	}

	public function getMaskedKey(): string {
		return LicenseKeyMasker::mask( $this->key );
	}

	public function getStatus(): string {
		return $this->status;
	}

	public function getExpiresAt(): ?DateTimeImmutable {
		return $this->expiresAt;
	}

	public function getActivationsUsed(): int {
		return $this->activationsUsed;
	}

	public function getActivationsMax(): ?int {
		return $this->activationsMax;
	}

	public function getLastCheckedAt(): ?DateTimeImmutable {
		return $this->lastCheckedAt;
	}

	public function getGraceUntil(): ?DateTimeImmutable {
		return $this->graceUntil;
	}

	public function getProductSlug(): string {
		return $this->productSlug;
	}

	public function withStatus( string $status ): self {
		return new self(
			$this->key,
			$status,
			$this->expiresAt,
			$this->activationsUsed,
			$this->activationsMax,
			$this->lastCheckedAt,
			$this->graceUntil,
			$this->productSlug
		);
	}

	public function withLastCheckedAt( ?DateTimeImmutable $lastCheckedAt ): self {
		return new self(
			$this->key,
			$this->status,
			$this->expiresAt,
			$this->activationsUsed,
			$this->activationsMax,
			$lastCheckedAt,
			$this->graceUntil,
			$this->productSlug
		);
	}

	public function withGraceUntil( ?DateTimeImmutable $graceUntil ): self {
		return new self(
			$this->key,
			$this->status,
			$this->expiresAt,
			$this->activationsUsed,
			$this->activationsMax,
			$this->lastCheckedAt,
			$graceUntil,
			$this->productSlug
		);
	}

	/**
	 * Verdadeiro quando a data de expiração já passou. Licença sem
	 * expiresAt (ilimitada/vitalícia) nunca expira por data.
	 */
	public function isExpiredByDate( ?DateTimeImmutable $now = null ): bool {
		if ( null === $this->expiresAt ) {
			return false;
		}

		$now = $now ?? new DateTimeImmutable();

		return $this->expiresAt < $now;
	}

	/**
	 * Licença ativa e, se tiver data de expiração, ainda dentro dela.
	 * Não considera período de graça — quem decide isso é isInGracePeriod().
	 */
	public function isValid( ?DateTimeImmutable $now = null ): bool {
		if ( LicenseStatus::ACTIVE !== $this->status ) {
			return false;
		}

		return ! $this->isExpiredByDate( $now );
	}

	/**
	 * Verdadeiro enquanto o instante $now ainda estiver dentro do período
	 * de graça (inclusive no limite exato do 14º dia).
	 */
	public function isInGracePeriod( ?DateTimeImmutable $now = null ): bool {
		if ( null === $this->graceUntil ) {
			return false;
		}

		$now = $now ?? new DateTimeImmutable();

		return $now <= $this->graceUntil;
	}

	/**
	 * Snapshot simples, sem considerar grace period: só licença ativa e
	 * dentro da validade recebe update. Expirada/revogada/inválida nunca
	 * recebem por este método — a decisão temporal completa (incluindo
	 * grace period de rede) é do V3R\Core\Updater\UpdateGate.
	 */
	public function canReceiveUpdates( ?DateTimeImmutable $now = null ): bool {
		return $this->isValid( $now );
	}

	/**
	 * Dias até a expiração (pode ser negativo se já expirou). Null quando
	 * a licença não tem data de expiração.
	 */
	public function daysUntilExpiry( ?DateTimeImmutable $now = null ): ?int {
		if ( null === $this->expiresAt ) {
			return null;
		}

		$now = $now ?? new DateTimeImmutable();

		$diff = $now->diff( $this->expiresAt );
		$days = (int) $diff->format( '%a' );

		return $diff->invert ? -$days : $days;
	}

	/**
	 * Representação segura para log/debug: a chave sai sempre mascarada.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'key'              => $this->getMaskedKey(),
			'status'           => $this->status,
			'expires_at'       => $this->expiresAt ? $this->expiresAt->format( DATE_ATOM ) : null,
			'activations_used' => $this->activationsUsed,
			'activations_max'  => $this->activationsMax,
			'last_checked_at'  => $this->lastCheckedAt ? $this->lastCheckedAt->format( DATE_ATOM ) : null,
			'grace_until'      => $this->graceUntil ? $this->graceUntil->format( DATE_ATOM ) : null,
			'product_slug'     => $this->productSlug,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
