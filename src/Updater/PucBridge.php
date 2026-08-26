<?php
declare(strict_types=1);

namespace V3R\Core\Updater;

use YahnisElsts\PluginUpdateChecker\v5p7\Plugin\PluginInfo;
use YahnisElsts\PluginUpdateChecker\v5p7\Plugin\Update as PucPluginUpdate;
use YahnisElsts\PluginUpdateChecker\v5p7\Plugin\UpdateChecker as PucPluginUpdateChecker;

/**
 * Ponte entre o Plugin Update Checker (biblioteca de terceiro, referenciada
 * aqui pelo namespace ORIGINAL, sem prefixo) e o resto desta biblioteca. Só
 * existe fora de Updater\UpdateChecker porque estender uma classe de
 * terceiro precisa de um arquivo próprio.
 *
 * É o plugin hospedeiro, via Strauss, quem prefixa v3r-core e o
 * plugin-update-checker juntos numa única passada — ver
 * docs/integracao-em-plugin.md. Referenciar aqui um namespace já
 * pré-prefixado quebraria essa passada (produziria um prefixo aninhado que
 * não bate com o das classes reais do pacote transitivo).
 *
 * Toda a decisão de negócio (o site tem direito à atualização? qual a
 * versão disponível?) já veio pronta de Updater\UpdateMetadataResolver —
 * esta classe só traduz esse resultado para o formato que o Plugin Update
 * Checker entende (Plugin\PluginInfo), substituindo por completo a busca
 * remota que ele faria sozinho contra um `metadataUrl` (nunca usamos essa
 * busca nativa dele: o protocolo de docs/api-contract.md §2.4 não é o
 * formato JSON que o PUC espera).
 *
 * Sem hooks próprios além dos que a classe-pai já registra sozinha ao ser
 * instanciada (site_transient_update_plugins, plugins_api,
 * upgrader_source_selection — este último é quem resolve a armadilha do
 * nome da pasta do zip, ver README/contrato; não precisamos reimplementá-lo).
 */
class PucBridge extends PucPluginUpdateChecker {

	/** @var UpdateMetadataResolver */
	private $resolver;

	/** @var string */
	private $pluginDisplayName;

	/**
	 * Valor de `requires` resolvido na última chamada de requestInfo() —
	 * ver injectMissingRequires() para o porquê de precisar deste cache.
	 *
	 * @var string|null
	 */
	private $lastResolvedRequires;

	public function __construct(
		string $metadataUrl,
		string $pluginFile,
		string $slug,
		UpdateMetadataResolver $resolver,
		string $pluginDisplayName,
		int $checkPeriod = 12
	) {
		$this->resolver          = $resolver;
		$this->pluginDisplayName = $pluginDisplayName;

		parent::__construct( $metadataUrl, $pluginFile, $slug, $checkPeriod );

		// V3RCore-Code#8: reinjeta `requires`, perdido pelo PUC entre
		// PluginInfo e Update — ver PucUpdateWithRequires e
		// injectMissingRequires() para o porquê deste ser o hook certo.
		add_filter( $this->getUniqueName( 'pre_inject_update' ), array( $this, 'injectMissingRequires' ) );
	}

	/**
	 * Substitui por completo o comportamento padrão da classe-pai (que
	 * faria um GET no $metadataUrl esperando o formato de manifesto do
	 * próprio PUC). Aqui a fonte da verdade é sempre
	 * UpdateMetadataResolver::resolve(), que já aplicou o UpdateGate antes
	 * de qualquer chamada de rede.
	 *
	 * Chamado tanto por requestUpdate() (popula o transiente de update do
	 * WordPress) quanto por injectInfo() (tela "Ver detalhes"), então é o
	 * único ponto que precisa aplicar o gate — os dois fluxos passam por
	 * aqui.
	 *
	 * BUG CORRIGIDO (validação ao vivo): a versão instalada usada na
	 * pergunta ao servidor é SEMPRE a lida daqui — getInstalledVersion()
	 * (herdado da classe-pai, lê o cabeçalho `Version:` do arquivo real do
	 * plugin) — nunca a versão fixada na construção do Bootstrap. As duas
	 * podem divergir (plugin atualizado sem o hospedeiro atualizar o valor
	 * que passa ao Bootstrap); quem decide "há novidade?" é sempre o que
	 * está de fato instalado agora.
	 *
	 * @param array<string, mixed> $queryArgs Ignorado: não fazemos requisição HTTP própria aqui.
	 * @return PluginInfo|null
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- assinatura exigida pela classe-pai.
	public function requestInfo( $queryArgs = array() ) {
		$installedVersion = $this->getInstalledVersion();

		if ( null === $installedVersion ) {
			// Cabeçalho do plugin ilegível — a própria classe-pai já
			// registra um E_USER_WARNING para este caso (checkForUpdates()).
			// Sem versão instalada não há como perguntar nada ao servidor.
			return null;
		}

		// Nunca passa um segundo argumento aqui: esta é a checagem de
		// ROTINA ("há algo mais novo?"), nunca um pedido de rollback — ver
		// UpdateMetadataResolver::resolve() e LicenseManager::checkForUpdate()
		// para o histórico completo do bug que isto corrige.
		$availability = $this->resolver->resolve( $installedVersion );

		if ( ! $availability->isAvailable() ) {
			return null;
		}

		$info               = new PluginInfo();
		$info->name         = $this->pluginDisplayName;
		$info->slug         = $this->slug;
		$info->filename     = $this->pluginFile;
		$info->version      = (string) $availability->getVersion();
		$info->requires     = $availability->getRequires();
		$info->requires_php = $availability->getRequiresPhp();
		$info->tested       = $availability->getTested();
		$info->download_url = (string) $availability->getPackageUrl();

		// Cache para injectMissingRequires(): requestUpdate() chama este
		// método via requestInfo() e converte o resultado num Update ANTES
		// de disparar o filtro pre_inject_update — sem guardar aqui, o
		// filtro não teria como recuperar o valor sem uma segunda chamada
		// (que repetiria a consulta ao servidor).
		$this->lastResolvedRequires = $info->requires;

		$changelogUrl = $availability->getChangelogUrl();
		if ( null !== $changelogUrl ) {
			// V3RCore-Code#10: mesma URL que alimenta o link "Ver
			// changelog completo" da tela de detalhes vira o destino do
			// link "Ver detalhes da versão" da lista de plugins (o campo
			// `url` do transiente vem de `homepage`, ver
			// Plugin\Update::toWpFormat() no upstream). Se o servidor não
			// mandar changelog_url, homepage fica null — nunca string
			// vazia, que apontaria para lugar nenhum.
			$info->homepage = $changelogUrl;

			$info->sections = array(
				'changelog' => sprintf(
					'<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
					esc_url( $changelogUrl ),
					esc_html__( 'Ver changelog completo', 'v3r-core' )
				),
			);
		}

		return $info;
	}

	/**
	 * Callback do filtro `pre_inject_update` (registrado no construtor).
	 * Ver PucUpdateWithRequires para o porquê deste ser o mecanismo certo
	 * do PUC para reinjetar um campo que `Update::getFieldNames()` não
	 * conhece.
	 *
	 * @param mixed $update Instância de Plugin\Update, ou null/false (sem update disponível).
	 * @return mixed
	 */
	public function injectMissingRequires( $update ) {
		if ( ! ( $update instanceof PucPluginUpdate ) || null === $this->lastResolvedRequires || '' === $this->lastResolvedRequires ) {
			return $update;
		}

		return PucUpdateWithRequires::fromExisting( $update, $this->lastResolvedRequires );
	}
}
