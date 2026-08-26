<?php
declare(strict_types=1);

namespace V3R\Core\Updater;

use V3R\Core\Licensing\ApiException;
use V3R\Core\Licensing\LicenseManager;

/**
 * Decide, para uma instalação concreta, se há atualização disponível AGORA
 * — sempre consultando primeiro o UpdateGate (nunca inventa uma segunda
 * regra de "este site pode atualizar"). Só depois de o gate aprovar é que
 * contata o servidor de licenças (LicenseManager::checkForUpdate()).
 *
 * Peça pura, sem nenhuma dependência do WordPress ou do Plugin Update
 * Checker: é o que faz esta parte da fatia 2b testável em unidade, sem
 * subir um WordPress de verdade (ver Updater\UpdateChecker, a camada que
 * liga isto aos hooks reais).
 */
final class UpdateMetadataResolver {

	/** @var LicenseManager */
	private $licenseManager;

	/** @var UpdateGate */
	private $gate;

	public function __construct( LicenseManager $licenseManager, UpdateGate $gate ) {
		$this->licenseManager = $licenseManager;
		$this->gate           = $gate;
	}

	/**
	 * BUG CORRIGIDO (validação ao vivo): $installedVersion é obrigatório —
	 * a chamada de rotina do WordPress ("há versão nova?") nunca deve
	 * mandar $requestedVersion, sob pena de o servidor entender isso como
	 * "quero exatamente esta versão" e responder update_available=false
	 * mesmo havendo release mais nova (ver LicenseManager::checkForUpdate()
	 * para o histórico completo).
	 *
	 * @param string      $installedVersion Versão REALMENTE instalada agora (lida por quem chama,
	 *                                       nunca a fixada na construção do Bootstrap — ver
	 *                                       Updater\PucBridge::getInstalledVersion()).
	 * @param string|null $requestedVersion Só para rollback explícito (docs/api-contract.md §2.4).
	 *                                       Ausente na checagem de rotina.
	 */
	public function resolve( string $installedVersion, ?string $requestedVersion = null ): UpdateAvailability {
		$state = $this->licenseManager->getState();

		if ( ! $this->gate->canUpdate( $state ) ) {
			// Licença sem direito a atualização: nem chegamos a perguntar
			// ao servidor. O WordPress não pode enxergar update nenhum
			// aqui — é a regra central desta fatia (docs/api-contract.md §6).
			return UpdateAvailability::none();
		}

		try {
			$response = $this->licenseManager->checkForUpdate( $installedVersion, $requestedVersion );
		} catch ( ApiException $exception ) {
			// Falha ao consultar update-check (rede, 5xx, assinatura ruim,
			// erro de negócio do servidor) nunca deve quebrar a tela de
			// plugins do WordPress: equivale a "sem novidade por enquanto".
			// A licença em si já tem seu próprio ciclo de grace period via
			// /validate (LicenseManager::refresh()) — este é só o "há
			// versão nova?", não afeta o estado de licença.
			return UpdateAvailability::none();
		}

		if ( null === $response ) {
			// Nunca houve ativação neste site: não há chave para perguntar.
			return UpdateAvailability::none();
		}

		$payload = isset( $response['payload'] ) && is_array( $response['payload'] ) ? $response['payload'] : array();

		if ( empty( $payload['update_available'] ) ) {
			return UpdateAvailability::none();
		}

		return UpdateAvailability::fromPayload( $payload );
	}
}
