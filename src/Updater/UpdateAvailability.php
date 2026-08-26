<?php
declare(strict_types=1);

namespace V3R\Core\Updater;

/**
 * Retrato imutável do resultado de uma consulta a GET /update-check
 * (docs/api-contract.md §2.4) — ou a ausência de atualização, que também é
 * um resultado válido, não um erro.
 *
 * Decide só como ler os campos do `payload` já verificado; não decide se
 * o site tem direito a instalar essa atualização (isso é do UpdateGate,
 * consultado antes por V3R\Core\Updater\UpdateMetadataResolver).
 */
final class UpdateAvailability {

	/** @var bool */
	private $available;

	/** @var string|null */
	private $version;

	/** @var string|null */
	private $requires;

	/** @var string|null */
	private $requiresPhp;

	/** @var string|null */
	private $tested;

	/** @var string|null */
	private $changelogUrl;

	/** @var string|null */
	private $packageUrl;

	private function __construct(
		bool $available,
		?string $version,
		?string $requires,
		?string $requiresPhp,
		?string $tested,
		?string $changelogUrl,
		?string $packageUrl
	) {
		$this->available    = $available;
		$this->version      = $version;
		$this->requires     = $requires;
		$this->requiresPhp  = $requiresPhp;
		$this->tested       = $tested;
		$this->changelogUrl = $changelogUrl;
		$this->packageUrl   = $packageUrl;
	}

	/**
	 * Nenhuma atualização — seja porque o servidor confirmou
	 * `update_available: false`, seja porque o UpdateGate negou, seja
	 * porque a consulta falhou (rede, licença sem chave, erro de negócio).
	 * Os três casos são indistinguíveis daqui para fora de propósito: o
	 * WordPress só entende "há update" ou "não há" — qualquer dúvida vira
	 * "não há", nunca "há, mas talvez".
	 */
	public static function none(): self {
		return new self( false, null, null, null, null, null, null );
	}

	/**
	 * @param array<string, mixed> $payload payload já com assinatura
	 *                                       verificada de GET /update-check,
	 *                                       com update_available = true.
	 */
	public static function fromPayload( array $payload ): self {
		return new self(
			true,
			isset( $payload['version'] ) && is_string( $payload['version'] ) ? $payload['version'] : null,
			isset( $payload['requires'] ) && is_string( $payload['requires'] ) ? $payload['requires'] : null,
			isset( $payload['requires_php'] ) && is_string( $payload['requires_php'] ) ? $payload['requires_php'] : null,
			isset( $payload['tested'] ) && is_string( $payload['tested'] ) ? $payload['tested'] : null,
			isset( $payload['changelog_url'] ) && is_string( $payload['changelog_url'] ) ? $payload['changelog_url'] : null,
			isset( $payload['package_url'] ) && is_string( $payload['package_url'] ) ? $payload['package_url'] : null
		);
	}

	public function isAvailable(): bool {
		return $this->available;
	}

	public function getVersion(): ?string {
		return $this->version;
	}

	public function getRequires(): ?string {
		return $this->requires;
	}

	public function getRequiresPhp(): ?string {
		return $this->requiresPhp;
	}

	public function getTested(): ?string {
		return $this->tested;
	}

	public function getChangelogUrl(): ?string {
		return $this->changelogUrl;
	}

	/**
	 * URL com o token efêmero já embutido pelo servidor (docs/api-contract.md
	 * §2.4/§2.5) — nunca montada à mão aqui, nunca com a chave de licença.
	 */
	public function getPackageUrl(): ?string {
		return $this->packageUrl;
	}
}
