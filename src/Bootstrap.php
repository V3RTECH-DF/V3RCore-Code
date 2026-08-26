<?php
declare(strict_types=1);

namespace V3R\Core;

use V3R\Core\Licensing\HttpApiClient;
use V3R\Core\Licensing\LicenseManager;
use V3R\Core\Licensing\LicenseStorage;
use V3R\Core\Licensing\SignatureVerifier;
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

	/**
	 * Capability padrão exigida pelos endpoints REST internos (fatia 2b,
	 * docs/api-contract.md §8.2) quando o plugin hospedeiro não informar a
	 * própria. Nunca fixamos isso dentro do código dos endpoints — cada
	 * plugin da casa pode ter seu próprio RBAC.
	 */
	public const DEFAULT_CAPABILITY = 'manage_options';

	/** @var string */
	private $productSlug;

	/** @var string */
	private $pluginFile;

	/** @var string */
	private $capability;

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
	 * @param string $publicKey     Chave pública ed25519 do servidor, base64 (não é segredo — docs/api-contract.md §4).
	 * @param string $pluginVersion Versão instalada do plugin hospedeiro (semver), enviada em toda chamada ao servidor.
	 * @param string $capability    Capability do WordPress exigida pela tela/endpoints internos (fatia 2b).
	 *                              Configurável por plugin (docs/api-contract.md §8.2) — default manage_options.
	 */
	public function __construct(
		string $productSlug,
		string $pluginFile,
		string $apiBaseUrl,
		string $publicKey,
		string $pluginVersion,
		string $capability = self::DEFAULT_CAPABILITY
	) {
		$this->productSlug = $productSlug;
		$this->pluginFile  = $pluginFile;
		$this->capability  = $capability;

		$verifier             = new SignatureVerifier( $publicKey );
		$apiClient            = new HttpApiClient( $apiBaseUrl, null, $verifier );
		$storage              = new LicenseStorage( $productSlug );
		$this->licenseManager = new LicenseManager( $productSlug, $apiClient, $storage, $pluginVersion );
		$this->updateGate     = new UpdateGate();
		$this->updateChecker  = new UpdateChecker( $pluginFile, $productSlug, $this->licenseManager, $this->updateGate );
	}

	/**
	 * Liga os hooks do WordPress necessários. Idempotente e seguro de
	 * chamar mesmo antes da fatia 2b existir: o UpdateChecker ainda não
	 * registra os hooks reais de atualização, mas o carregamento nunca
	 * quebra o plugin hospedeiro.
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

	/**
	 * Exposto para a fatia 2b (endpoints REST internos e AdminPage) usar a
	 * mesma capability configurada aqui, sem duplicar o parâmetro.
	 */
	public function getCapability(): string {
		return $this->capability;
	}
}
