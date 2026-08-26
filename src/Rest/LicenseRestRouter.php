<?php
declare(strict_types=1);

namespace V3R\Core\Rest;

/**
 * Registra as quatro rotas do protocolo interno (docs/api-contract.md §8)
 * sob `v3r-core/v1/<product_slug>/license`. O slug do produto vai no
 * caminho — nunca remova isso "porque só há um plugin instalado hoje": é o
 * que evita duas cópias desta biblioteca (uma por plugin hospedeiro, cada
 * uma prefixada pelo próprio Strauss do plugin) colidindo no mesmo
 * namespace REST do WordPress (§8.1).
 */
final class LicenseRestRouter {

	private const NAMESPACE_NAME = 'v3r-core/v1';

	/** @var LicenseController */
	private $controller;

	/** @var string */
	private $productSlug;

	public function __construct( LicenseController $controller, string $productSlug ) {
		$this->controller  = $controller;
		$this->productSlug = $productSlug;
	}

	/**
	 * No-op seguro fora do WordPress (ex.: Bootstrap instanciado em teste
	 * de unidade sem o WP carregado) — mesma defesa de
	 * Updater\UpdateChecker::register().
	 */
	public function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$base       = '/' . rawurlencode( $this->productSlug ) . '/license';
		$permission = array( $this->controller, 'permission_callback' );

		register_rest_route(
			self::NAMESPACE_NAME,
			$base,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this->controller, 'get_state' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE_NAME,
			$base . '/activate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->controller, 'activate' ),
				'permission_callback' => $permission,
				'args'                => array(
					'license_key' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_NAME,
			$base . '/deactivate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->controller, 'deactivate' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE_NAME,
			$base . '/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->controller, 'refresh' ),
				'permission_callback' => $permission,
			)
		);
	}
}
