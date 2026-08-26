<?php
declare(strict_types=1);

namespace V3R\Core\Rest;

use DateTimeImmutable;
use V3R\Core\Licensing\ApiException;
use V3R\Core\Licensing\LicenseManager;
use V3R\Core\Licensing\LicenseStatePresenter;
use V3R\Core\Licensing\RefreshThrottle;
use V3R\Core\Updater\UpdateGate;

/**
 * Camada REST do protocolo interno tela-administrativa ↔ biblioteca
 * (docs/api-contract.md §8) — não confundir com o protocolo externo
 * cliente↔servidor (§§1–7), que autentica pela chave de licença e nunca
 * passa por aqui.
 *
 * Autenticação: nonce `wp_rest` + capability configurável **por operação**
 * (§8.2, issue #9) — leitura (`get_state`/`refresh`) e gestão
 * (`activate`/`deactivate`), nunca a mesma para as quatro. Nunca
 * `is_admin()` — não é autorização. Lógica de negócio não mora aqui: só
 * extração de parâmetros e tradução de exceções para o formato REST do
 * WordPress; quem decide é sempre LicenseManager/UpdateGate.
 */
final class LicenseController {

	/** @var LicenseManager */
	private $manager;

	/**
	 * Capability exigida para GET .../license e POST .../license/refresh.
	 *
	 * @var string
	 */
	private $readCapability;

	/**
	 * Capability exigida para POST .../license/activate e
	 * POST .../license/deactivate.
	 *
	 * @var string
	 */
	private $manageCapability;

	/** @var LicenseStatePresenter */
	private $presenter;

	/** @var RefreshThrottle */
	private $throttle;

	public function __construct(
		LicenseManager $manager,
		UpdateGate $gate,
		string $readCapability,
		string $manageCapability,
		?RefreshThrottle $throttle = null
	) {
		$this->manager          = $manager;
		$this->readCapability   = $readCapability;
		$this->manageCapability = $manageCapability;
		$this->presenter        = new LicenseStatePresenter( $gate );
		$this->throttle         = $throttle ?? new RefreshThrottle( $manager->getProductSlug() );
	}

	/**
	 * §8.2 — `permission_callback` de GET .../license e
	 * POST .../license/refresh: nonce `wp_rest` válido no cabeçalho
	 * `X-WP-Nonce` **e** a capability de leitura, nunca `is_admin()`.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function permission_callback_read( $request ): bool {
		return $this->checkCapabilityAndNonce( $request, $this->readCapability );
	}

	/**
	 * §8.2 — `permission_callback` de POST .../license/activate e
	 * POST .../license/deactivate: nonce `wp_rest` válido no cabeçalho
	 * `X-WP-Nonce` **e** a capability de gestão, nunca `is_admin()`. Quem
	 * só tem a capability de leitura recebe `403` aqui mesmo tendo nonce
	 * válido — é a checagem que fecha a issue #9.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function permission_callback_manage( $request ): bool {
		return $this->checkCapabilityAndNonce( $request, $this->manageCapability );
	}

	/**
	 * Nonce ausente ou inválido responde antes mesmo de chegar aqui, na
	 * maior parte dos casos (rest_cookie_invalid_nonce, erro padrão do
	 * próprio WordPress); esta checagem é defesa em profundidade e o que
	 * torna o requisito testável em unidade.
	 *
	 * @param \WP_REST_Request $request
	 */
	private function checkCapabilityAndNonce( $request, string $capability ): bool {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( $capability ) ) {
			return false;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return false;
		}

		return function_exists( 'wp_verify_nonce' ) && false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * §8.3 — GET .../license: só o cache local, nunca a rede.
	 *
	 * @param \WP_REST_Request $request
	 * @return array<string, mixed>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- assinatura exigida pelo `callback` de register_rest_route().
	public function get_state( $request ): array {
		return $this->presenter->present( $this->manager->getState() );
	}

	/**
	 * §8.6 — POST .../license/activate.
	 *
	 * @param \WP_REST_Request $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function activate( $request ) {
		$licenseKey = $request->get_param( 'license_key' );

		if ( ! is_string( $licenseKey ) || '' === trim( $licenseKey ) ) {
			return new \WP_Error(
				'missing_license_key',
				'Informe a chave de licença.',
				array( 'status' => 400 )
			);
		}

		try {
			$state = $this->manager->activate( trim( $licenseKey ) );
		} catch ( ApiException $exception ) {
			return $this->errorFromException( $exception );
		}

		return $this->presenter->present( $state );
	}

	/**
	 * §8.7 — POST .../license/deactivate.
	 *
	 * @param \WP_REST_Request $request
	 * @return array<string, mixed>|\WP_Error
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- assinatura exigida pelo `callback` de register_rest_route().
	public function deactivate( $request ) {
		try {
			$this->manager->deactivate();
		} catch ( ApiException $exception ) {
			return $this->errorFromException( $exception );
		}

		return array( 'deactivated' => true );
	}

	/**
	 * §8.8 — POST .../license/refresh, com o throttle local de 1 minuto de
	 * §8.8.1: dentro da janela, responde 200 com o estado do cache e
	 * `throttled: true` — nunca um erro, e nunca contata o servidor.
	 *
	 * @param \WP_REST_Request $request
	 * @return array<string, mixed>|\WP_Error
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- assinatura exigida pelo `callback` de register_rest_route().
	public function refresh( $request ) {
		$now = new DateTimeImmutable();

		$retryAfter = $this->throttle->secondsRemaining( $now );

		if ( null !== $retryAfter ) {
			$payload                = $this->presenter->present( $this->manager->getState() );
			$payload['throttled']   = true;
			$payload['retry_after'] = $retryAfter;

			return $payload;
		}

		$this->throttle->markAttempt( $now );

		try {
			$state = $this->manager->refresh( true, $now );
		} catch ( ApiException $exception ) {
			return $this->errorFromException( $exception );
		}

		return $this->presenter->present( $state );
	}

	/**
	 * Traduz ApiException para o formato de erro REST do WordPress
	 * (§8.9): repassa o código de negócio vindo do servidor tal como veio
	 * (invalid_key, product_mismatch, license_expired, ...), e mapeia os
	 * dois códigos que a própria biblioteca produz —
	 * ApiException::SIGNATURE_INVALID (502, §8.10) e
	 * ApiException::COMMUNICATION_FAILURE (503, server_unreachable) — para
	 * códigos distintos, nunca confundindo "não deu para confirmar a
	 * assinatura" com "servidor fora do ar".
	 */
	private function errorFromException( ApiException $exception ): \WP_Error {
		if ( $exception->isSignatureInvalid() ) {
			return new \WP_Error(
				'signature_invalid',
				'Não foi possível confirmar sua licença agora. Tente novamente em instantes.',
				array( 'status' => 502 )
			);
		}

		if ( ApiException::COMMUNICATION_FAILURE === $exception->getErrorCode() ) {
			return new \WP_Error(
				'server_unreachable',
				'Não foi possível contatar o servidor de licenças agora. Tente novamente em instantes.',
				array( 'status' => 503 )
			);
		}

		return new \WP_Error(
			$exception->getErrorCode(),
			$exception->getMessage(),
			array( 'status' => $this->httpStatusFor( $exception ) )
		);
	}

	/**
	 * ApiException guarda o HTTP status original do servidor no código da
	 * exceção base (RuntimeException::getCode()) — ver
	 * HttpApiClient::businessErrorFromBody(). Sem esse valor (não deveria
	 * acontecer para erros de negócio repassados), cai num 502 genérico em
	 * vez de inventar um 200 de sucesso.
	 */
	private function httpStatusFor( ApiException $exception ): int {
		$status = $exception->getCode();

		return $status > 0 ? $status : 502;
	}
}
