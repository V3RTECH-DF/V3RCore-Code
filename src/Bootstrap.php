<?php
declare(strict_types=1);

namespace V3R\Core;

use V3R\Core\Licensing\HttpApiClient;
use V3R\Core\Licensing\LicenseManager;
use V3R\Core\Licensing\LicenseStorage;
use V3R\Core\Updater\UpdateChecker;
use V3R\Core\Updater\UpdateGate;

/**
 * Ponto de entrada único que um plugin consumidor chama para ligar
 * licenciamento e auto-atualização. Decide como as peças internas se
 * conectam; não decide política de licenciamento nem de update — isso é
 * do LicenseManager e do UpdateGate, respectivamente.
 *
 * Ver README.md para o exemplo completo de uso num plugin.
 */
final class Bootstrap {

	/** @var string */
	private $productSlug;

	/** @var string */
	private $pluginFile;

	/** @var LicenseManager */
	private $licenseManager;

	/** @var UpdateGate */
	private $updateGate;

	/** @var UpdateChecker */
	private $updateChecker;

	/**
	 * @param string $productSlug   Slug do produto no servidor de licenças (ex.: "v3rlgpd").
	 * @param string $pluginFile    Caminho absoluto do arquivo principal do plugin (__FILE__).
	 * @param string $apiBaseUrl    URL base do servidor de licenças (v3r-license/v1).
	 */
	public function __construct( string $productSlug, string $pluginFile, string $apiBaseUrl ) {
		$this->productSlug = $productSlug;
		$this->pluginFile  = $pluginFile;

		$apiClient            = new HttpApiClient( $apiBaseUrl );
		$storage              = new LicenseStorage( $productSlug );
		$this->licenseManager = new LicenseManager( $productSlug, $apiClient, $storage );
		$this->updateGate     = new UpdateGate();
		$this->updateChecker  = new UpdateChecker( $pluginFile, $productSlug, $this->licenseManager, $this->updateGate );
	}

	/**
	 * Liga os hooks do WordPress necessários. Idempotente e seguro de
	 * chamar mesmo antes da fatia 2 existir: as peças internas ainda não
	 * fazem rede, mas o carregamento nunca quebra o plugin hospedeiro.
	 */
	public function boot(): void {
		$this->updateChecker->register();
	}

	public function getLicenseManager(): LicenseManager {
		return $this->licenseManager;
	}

	public function getUpdateGate(): UpdateGate {
		return $this->updateGate;
	}

	public function getProductSlug(): string {
		return $this->productSlug;
	}

	public function getPluginFile(): string {
		return $this->pluginFile;
	}
}
