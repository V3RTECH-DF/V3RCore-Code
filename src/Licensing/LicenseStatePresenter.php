<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

use V3R\Core\Updater\UpdateGate;

/**
 * Traduz um LicenseState (mais o veredito do UpdateGate) para o schema de
 * docs/api-contract.md §8.3 — o mesmo array que `GET .../license`,
 * `POST .../license/activate` e `POST .../license/refresh` devolvem, e que
 * a Licensing\AdminPage também consome para não reescrever a mesma lógica
 * de apresentação em dois lugares.
 *
 * Único ponto que decide o texto de `status_message` (§8.4) — nenhum outro
 * lugar desta biblioteca (nem os plugins hospedeiros) deveria derivar essa
 * frase a partir de `status` por conta própria.
 */
final class LicenseStatePresenter {

	/** @var UpdateGate */
	private $gate;

	public function __construct( UpdateGate $gate ) {
		$this->gate = $gate;
	}

	/**
	 * @param LicenseState            $state
	 * @param \DateTimeImmutable|null $now   Instante de referência (injetável para teste).
	 * @return array<string, mixed>
	 */
	public function present( LicenseState $state, ?\DateTimeImmutable $now = null ): array {
		$now             = $now ?? new \DateTimeImmutable();
		$receivesUpdates = $this->gate->canUpdate( $state, $now );
		$inGracePeriod   = $state->isInGracePeriod( $now );

		return array(
			'license_key_masked' => $state->getMaskedKey(),
			'status'              => $state->getStatus(),
			'expires_at'          => $this->formatDate( $state->getExpiresAt() ),
			'activations_used'    => $state->getActivationsUsed(),
			'activations_max'     => $state->getActivationsMax(),
			'last_checked_at'     => $this->formatDate( $state->getLastCheckedAt() ),
			'in_grace_period'     => $inGracePeriod,
			'grace_until'         => $this->formatDate( $state->getGraceUntil() ),
			'receives_updates'    => $receivesUpdates,
			'status_message'      => $this->statusMessage( $state->getStatus(), $receivesUpdates, $inGracePeriod ),
		);
	}

	/**
	 * Mapeamento literal da tabela de docs/api-contract.md §8.4. Nenhuma
	 * destas frases usa "bloqueado", "desativado" ou "suspenso" referindo-se
	 * ao plugin (§8.11) — licença sem direito a update nunca é apresentada
	 * como o plugin tendo parado de funcionar.
	 */
	private function statusMessage( string $status, bool $receivesUpdates, bool $inGracePeriod ): string {
		if ( LicenseStatus::ACTIVE === $status ) {
			if ( ! $receivesUpdates ) {
				return 'Não conseguimos confirmar sua licença há mais de 14 dias. As atualizações foram pausadas até a próxima verificação bem-sucedida.';
			}

			if ( $inGracePeriod ) {
				return 'Não conseguimos confirmar sua licença nos últimos dias, mas você continua recebendo atualizações durante o período de tolerância.';
			}

			return 'Licença ativa. Você recebe atualizações normalmente.';
		}

		switch ( $status ) {
			case LicenseStatus::EXPIRED:
				return 'Sua licença expirou. O plugin continua funcionando normalmente, mas você não recebe mais atualizações. Renove para voltar a recebê-las.';

			case LicenseStatus::REVOKED:
				return 'Esta licença foi revogada. O plugin continua funcionando normalmente, mas não recebe atualizações.';

			case LicenseStatus::INACTIVE:
				return 'Nenhuma licença ativada neste site.';

			case LicenseStatus::INVALID:
			default:
				return 'Não foi possível validar esta licença. Verifique a chave informada.';
		}
	}

	private function formatDate( ?\DateTimeImmutable $date ): ?string {
		return null === $date ? null : $date->format( DATE_ATOM );
	}
}
