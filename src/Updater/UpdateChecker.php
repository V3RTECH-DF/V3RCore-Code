<?php
declare(strict_types=1);

namespace V3R\Core\Updater;

use V3R\Core\Licensing\LicenseManager;

/**
 * Encapsula o Plugin Update Checker (YahnisElsts\PluginUpdateChecker), a
 * lib de terceiro embarcada via Strauss sob V3R\Core\Vendor\. Decide COMO
 * o transiente de update do WordPress é populado; a decisão de SE este site
 * recebe update é sempre delegada ao UpdateGate.
 *
 * TODO(fatia-2): instanciar o PucFactory apontando para o endpoint
 * GET /update-check do servidor de licenças (docs/api-contract.md), e
 * curto-circuitar a resposta quando UpdateGate::canUpdate() for falso.
 */
class UpdateChecker {

	/** @var string */
	private $pluginFile;

	/** @var string */
	private $productSlug;

	/** @var LicenseManager */
	private $licenseManager;

	/** @var UpdateGate */
	private $gate;

	public function __construct(
		string $pluginFile,
		string $productSlug,
		LicenseManager $licenseManager,
		UpdateGate $gate
	) {
		$this->pluginFile     = $pluginFile;
		$this->productSlug    = $productSlug;
		$this->licenseManager = $licenseManager;
		$this->gate           = $gate;
	}

	/**
	 * Registra os hooks do WordPress (pre_set_site_transient_update_plugins,
	 * plugins_api, upgrader_pre_download) que fazem a atualização acontecer.
	 *
	 * Nesta fatia, não registra nada — só precisa existir e ser
	 * instanciável sem quebrar o carregamento do plugin (ver Bootstrap).
	 */
	public function register(): void {
		// Intencionalmente vazio nesta fatia.
	}

	public function getPluginFile(): string {
		return $this->pluginFile;
	}

	public function getProductSlug(): string {
		return $this->productSlug;
	}

	public function getLicenseManager(): LicenseManager {
		return $this->licenseManager;
	}

	public function getGate(): UpdateGate {
		return $this->gate;
	}
}
