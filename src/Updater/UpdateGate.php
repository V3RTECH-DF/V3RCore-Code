<?php
declare(strict_types=1);

namespace V3R\Core\Updater;

use DateTimeImmutable;
use V3R\Core\Licensing\LicenseState;
use V3R\Core\Licensing\LicenseStatus;

/**
 * Decide se ESTE site recebe atualização AGORA, dado o estado de licença.
 *
 * É a regra de negócio central do produto: licença expirada, revogada ou
 * inválida NUNCA derruba o plugin — ele continua 100% funcional. A única
 * coisa que se perde é a atualização automática. Por isso este gate nunca
 * é consultado para decidir se o plugin funciona, só se ele é atualizado.
 */
class UpdateGate {

	/**
	 * Período de graça, em dias, quando o servidor de licenças ficou
	 * inacessível na última checagem: mantemos o último estado conhecido
	 * por até este prazo antes de suspender a atualização.
	 */
	public const GRACE_PERIOD_DAYS = 14;

	/**
	 * @param LicenseState           $state Estado de licença corrente do site.
	 * @param DateTimeImmutable|null $now  Instante de referência (injetável para teste).
	 */
	public function canUpdate( LicenseState $state, ?DateTimeImmutable $now = null ): bool {
		$now = $now ?? new DateTimeImmutable();

		switch ( $state->getStatus() ) {
			case LicenseStatus::REVOKED:
			case LicenseStatus::INVALID:
			case LicenseStatus::INACTIVE:
				// Servidor confirmou (ou nunca houve ativação): não há "não sei",
				// a suspensão é imediata, sem grace period.
				return false;

			case LicenseStatus::EXPIRED:
				// Regra do produto: expirada não recebe update, mas o plugin
				// segue funcionando normalmente. Grace period não se aplica
				// aqui — expirado é um fato confirmado, não uma dúvida de rede.
				return false;

			case LicenseStatus::ACTIVE:
				return $this->canActiveStateUpdate( $state, $now );

			default:
				return false;
		}
	}

	private function canActiveStateUpdate( LicenseState $state, DateTimeImmutable $now ): bool {
		if ( $state->isExpiredByDate( $now ) ) {
			return false;
		}

		// graceUntil só é preenchido quando houve falha de contato com o
		// servidor após a última checagem bem-sucedida. Enquanto não
		// estourou, mantém o último estado conhecido (ativa) recebendo update.
		if ( null !== $state->getGraceUntil() && ! $state->isInGracePeriod( $now ) ) {
			return false;
		}

		return true;
	}
}
