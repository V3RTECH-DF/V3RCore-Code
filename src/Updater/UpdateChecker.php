<?php
declare(strict_types=1);

namespace V3R\Core\Updater;

use V3R\Core\Licensing\HttpApiClient;
use V3R\Core\Licensing\LicenseManager;

/**
 * Encapsula o Plugin Update Checker (YahnisElsts\PluginUpdateChecker). Esta
 * lib de terceiro NÃO é prefixada por esta biblioteca — o namespace usado em
 * todo o código desta fatia é o original, sem prefixo. É o plugin hospedeiro
 * que prefixa v3r-core E suas dependências transitivas (incluindo esta) numa
 * única passada do Strauss (ver docs/integracao-em-plugin.md); prefixar aqui
 * também produziria um namespace aninhado que a passada do hospedeiro não
 * consegue reconciliar. Decide COMO o transiente de update do WordPress é
 * populado; a decisão de SE este site recebe update é sempre delegada ao
 * UpdateGate, através de UpdateMetadataResolver (a peça pura e testável desta
 * fatia — ver Updater\PucBridge para a ponte com a lib de terceiro em si).
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

	/** @var UpdateMetadataResolver */
	private $resolver;

	/** @var PucBridge|null */
	private $pucBridge;

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
		$this->resolver       = new UpdateMetadataResolver( $licenseManager, $gate );
		$this->pucBridge      = null;
	}

	/**
	 * Registra os hooks do WordPress que fazem a atualização acontecer,
	 * via o Plugin Update Checker (Updater\PucBridge).
	 *
	 * Instanciar o PucBridge sem o WordPress carregado (ex.: PHPUnit desta
	 * própria biblioteca, ou o Bootstrap de um plugin hospedeiro rodando
	 * fora do wp-admin em algum contexto exótico) faria a classe-pai do PUC
	 * quebrar — ela chama plugin_basename(), add_filter() etc. no próprio
	 * construtor. Por isso este método só age quando reconhece um WordPress
	 * de verdade por baixo (function_exists('add_filter')); do contrário, é
	 * um no-op seguro, exatamente como a fatia 1 já garantia.
	 *
	 * Idempotente: chamadas repetidas não duplicam hooks.
	 */
	public function register(): void {
		if ( null !== $this->pucBridge ) {
			return;
		}

		if ( ! function_exists( 'add_filter' ) ) {
			// Ambiente sem WordPress carregado (ex.: testes de unidade).
			return;
		}

		$apiClient   = $this->licenseManager->getApiClient();
		$metadataUrl = $apiClient instanceof HttpApiClient
			? $apiClient->getBaseUrl() . '/update-check'
			// Nunca efetivamente buscado pelo PucBridge (ele sobrescreve
			// requestInfo() por completo) — só precisa ser uma URL
			// sintaticamente válida para os usos cosméticos que a
			// classe-pai do PUC faz dela (ex.: allowMetadataHost()).
			: 'https://v3r-core.invalid/' . rawurlencode( $this->productSlug ) . '/update-check';

		$this->pucBridge = new PucBridge(
			$metadataUrl,
			$this->pluginFile,
			$this->productSlug,
			$this->resolver,
			$this->pluginDisplayName()
		);
	}

	/**
	 * Nome de exibição na tela "Ver detalhes". Lido do cabeçalho do próprio
	 * plugin quando o WordPress oferece a função para isso; na ausência
	 * (não deveria acontecer em produção, mas o método é definido
	 * defensivamente), cai para o slug do produto.
	 */
	private function pluginDisplayName(): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			return $this->productSlug;
		}

		$data = get_plugin_data( $this->pluginFile, false, false );

		return '' !== $data['Name'] ? $data['Name'] : $this->productSlug;
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

	/**
	 * Exposto para teste e para o integrador consultar "há atualização
	 * disponível?" sem depender do transiente do WordPress (ex.: a
	 * AdminPage, se quiser mostrar isso fora do fluxo nativo).
	 */
	public function getResolver(): UpdateMetadataResolver {
		return $this->resolver;
	}
}
