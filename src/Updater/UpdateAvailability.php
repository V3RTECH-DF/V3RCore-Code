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

	/**
	 * Chaves de tamanho ('1x', '2x', ...) para URL pública do ícone do
	 * produto — ver getIcons() para o formato esperado.
	 *
	 * @var array<string, string>|null
	 */
	private $icons;

	/**
	 * @param bool                       $available
	 * @param string|null                $version
	 * @param string|null                $requires
	 * @param string|null                $requiresPhp
	 * @param string|null                $tested
	 * @param string|null                $changelogUrl
	 * @param string|null                $packageUrl
	 * @param array<string, string>|null $icons
	 */
	private function __construct(
		bool $available,
		?string $version,
		?string $requires,
		?string $requiresPhp,
		?string $tested,
		?string $changelogUrl,
		?string $packageUrl,
		?array $icons
	) {
		$this->available    = $available;
		$this->version      = $version;
		$this->requires     = $requires;
		$this->requiresPhp  = $requiresPhp;
		$this->tested       = $tested;
		$this->changelogUrl = $changelogUrl;
		$this->packageUrl   = $packageUrl;
		$this->icons        = $icons;
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
		return new self( false, null, null, null, null, null, null, null );
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
			isset( $payload['package_url'] ) && is_string( $payload['package_url'] ) ? $payload['package_url'] : null,
			self::extractIcons( $payload )
		);
	}

	/**
	 * Chave `icons` é opcional no payload (o servidor só a envia quando o
	 * produto tem ícone cadastrado — V3RLicense-Code#23) e, mesmo presente,
	 * não é garantidamente bem formada. Um payload malformado aqui não pode
	 * derrubar a checagem de atualização (caminho crítico): na dúvida,
	 * volta null, exatamente como se a chave não existisse.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, string>|null
	 */
	private static function extractIcons( array $payload ): ?array {
		if ( ! isset( $payload['icons'] ) || ! is_array( $payload['icons'] ) ) {
			return null;
		}

		$icons = array();
		foreach ( $payload['icons'] as $size => $url ) {
			if ( is_string( $size ) && is_string( $url ) && '' !== $url ) {
				$icons[ $size ] = $url;
			}
		}

		return array() === $icons ? null : $icons;
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

	/**
	 * URLs públicas do ícone do produto, por tamanho ('1x', '2x'), como o
	 * servidor as envia — null quando o produto não tem ícone cadastrado ou
	 * quando o payload trouxe algo que não é um mapa tamanho => URL.
	 *
	 * @return array<string, string>|null
	 */
	public function getIcons(): ?array {
		return $this->icons;
	}
}
