<?php
declare(strict_types=1);

namespace V3R\Core;

use V3R\Core\Licensing\AdminPage;
use V3R\Core\Licensing\HttpApiClient;
use V3R\Core\Licensing\LicenseManager;
use V3R\Core\Licensing\LicenseStorage;
use V3R\Core\Licensing\SignatureVerifier;
use V3R\Core\Rest\LicenseController;
use V3R\Core\Rest\LicenseRestRouter;
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

	/**
	 * Capability exigida para as operações de leitura dos endpoints REST
	 * internos: `GET .../license` e `POST .../license/refresh`
	 * (docs/api-contract.md §8.2, issue #9). Também usada pela AdminPage
	 * para decidir se a tela existe para o usuário.
	 *
	 * @var string
	 */
	private $readCapability;

	/**
	 * Capability exigida para as operações de gestão dos endpoints REST
	 * internos: `POST .../license/activate` e `POST .../license/deactivate`
	 * (docs/api-contract.md §8.2, issue #9) — as duas mexem no estado da
	 * licença, e `deactivate` libera a cota do domínio no servidor.
	 *
	 * @var string
	 */
	private $manageCapability;

	/** @var LicenseManager */
	private $licenseManager;

	/** @var UpdateGate */
	private $updateGate;

	/** @var UpdateChecker */
	private $updateChecker;

	/** @var LicenseRestRouter */
	private $restRouter;

	/**
	 * @param string      $productSlug      Slug do produto no servidor de licenças (ex.: "v3rlgpd").
	 * @param string      $pluginFile       Caminho absoluto do arquivo principal do plugin (__FILE__).
	 * @param string      $apiBaseUrl       URL base do servidor de licenças (v3r-license/v1).
	 * @param string      $publicKey        Chave pública ed25519 do servidor, base64 (não é segredo — docs/api-contract.md §4).
	 * @param string      $pluginVersion    Versão instalada do plugin hospedeiro (semver), enviada em toda chamada ao servidor.
	 * @param string      $readCapability   Capability exigida para consultar o estado da licença (GET .../license e
	 *                                      POST .../license/refresh) — docs/api-contract.md §8.2. Default manage_options.
	 * @param string|null $manageCapability Capability exigida para ativar/desativar a licença (POST .../license/activate
	 *                                      e POST .../license/deactivate) — docs/api-contract.md §8.2. Quando omitida
	 *                                      (null), cai para $readCapability — mesmo comportamento de quem passa uma
	 *                                      capability só, sem ficar acidentalmente mais permissivo.
	 */
	public function __construct(
		string $productSlug,
		string $pluginFile,
		string $apiBaseUrl,
		string $publicKey,
		string $pluginVersion,
		string $readCapability = self::DEFAULT_CAPABILITY,
		?string $manageCapability = null
	) {
		$this->productSlug      = $productSlug;
		$this->pluginFile       = $pluginFile;
		$this->readCapability   = $readCapability;
		$this->manageCapability = $manageCapability ?? $readCapability;

		$verifier             = new SignatureVerifier( $publicKey );
		$apiClient            = new HttpApiClient( $apiBaseUrl, null, $verifier );
		$storage              = new LicenseStorage( $productSlug );
		$this->licenseManager = new LicenseManager( $productSlug, $apiClient, $storage, $pluginVersion );
		$this->updateGate     = new UpdateGate();
		$this->updateChecker  = new UpdateChecker( $pluginFile, $productSlug, $this->licenseManager, $this->updateGate );

		$controller       = new LicenseController( $this->licenseManager, $this->updateGate, $this->readCapability, $this->manageCapability );
		$this->restRouter = new LicenseRestRouter( $controller, $productSlug );
	}

	/**
	 * Liga os hooks do WordPress necessários: auto-atualização
	 * (Updater\UpdateChecker) e os quatro endpoints REST internos de
	 * docs/api-contract.md §8 (Rest\LicenseRestRouter). Idempotente e
	 * seguro de chamar mesmo fora do WordPress (ex.: em teste) — os dois só
	 * agem quando reconhecem um WordPress de verdade por baixo.
	 *
	 * Não inclui a AdminPage: ela é opcional (docs/api-contract.md §8,
	 * critério "um plugin sem SPA própria decide se a registra"). O plugin
	 * hospedeiro que quiser a tela padrão chama
	 * `$bootstrap->createAdminPage()->register()` explicitamente.
	 */
	public function boot(): void {
		$this->updateChecker->register();
		$this->restRouter->register();
	}

	/**
	 * Fábrica da tela administrativa padrão (fatia 2b) — deliberadamente
	 * NÃO chamada por boot(). Plugins sem SPA própria (ex.: V3RProp) chamam
	 * `$bootstrap->createAdminPage()->register()`; plugins que desenham a
	 * própria aba (V3RLGPD, V3REvent) simplesmente nunca chamam isto, e
	 * nenhuma tela é registrada.
	 *
	 * A tela não separa leitura de gestão (mistura estado e botões de
	 * ativar/desativar numa página só, issue #9) — por isso usa
	 * $manageCapability, nunca mais permissiva do que o endpoint que ela
	 * aciona.
	 */
	public function createAdminPage(): AdminPage {
		return new AdminPage( $this->licenseManager, $this->updateGate, $this->manageCapability );
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
	 * Capability de leitura (GET .../license e POST .../license/refresh) —
	 * docs/api-contract.md §8.2.
	 */
	public function getReadCapability(): string {
		return $this->readCapability;
	}

	/**
	 * Capability de gestão (POST .../license/activate e
	 * POST .../license/deactivate) — docs/api-contract.md §8.2. Igual a
	 * getReadCapability() quando o hospedeiro não informou a própria.
	 */
	public function getManageCapability(): string {
		return $this->manageCapability;
	}

	/**
	 * Alias de compatibilidade com quem consumia a capability única de
	 * antes da issue #9. Devolve a capability de gestão — a mais
	 * restritiva das duas, e a que a AdminPage usa — para nunca reabrir
	 * acidentalmente o acesso que a issue #9 fechou. Prefira
	 * getReadCapability()/getManageCapability() em código novo.
	 */
	public function getCapability(): string {
		return $this->manageCapability;
	}
}
