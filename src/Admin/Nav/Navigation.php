<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * Fachada que um plugin instancia para consumir a camada de governo da
 * navegação (docs/navegacao-do-painel.md). Liga as três peças que, juntas,
 * cumprem o contrato:
 *
 * - `tree()` — a árvore filtrada (§5), pronta para o front consumir;
 * - `canView()` — a mesma pergunta que a árvore já respondeu para cada
 *   tela, exposta para a própria tela reconferir antes de desenhar (§4,
 *   camada 2) — sem WordPress, sem capability nenhuma, só a mesma
 *   `Screen::permission()` via o mesmo `ScreenAccess`;
 * - `accessMap()` — `canView()` para TODAS as telas de uma vez, inclusive
 *   as ocultas, pensado para virar dado da página e ser consultado pelo
 *   roteador de quem roteia no cliente (§4, RIT360 Flow, 06/09/2026) —
 *   ver docblock do método;
 * - `registerMenu()` — a entrada única no menu (§6) e o registro das
 *   páginas ocultas que sustentam a camada 1 da guarda dupla (§4).
 *
 * O gate de `user_has_cap` (`NavCapabilityGate`) é registrado no
 * construtor, não em `registerMenu()`: a guarda de acesso direto não pode
 * depender de o plugin ter chamado `registerMenu()` — ela é o que faz o
 * `add_submenu_page()` (§4) valer alguma coisa, mas responde pelo filtro
 * de qualquer forma, mesmo para quem só consome `tree()`.
 *
 * Esta classe **não desenha nada** (§7): o callback das páginas ocultas
 * registradas por `registerMenu()` é vazio — a única função delas é
 * existir com a capability sintética certa, para o WordPress ter o que
 * barrar. Renderizar o que o usuário vê ao acessar a URL diretamente é
 * decisão de produto que esta camada não toma (fica para a #26).
 *
 * **`$access` aceita uma função OU um objeto `ScreenAccess`** (§3): a forma
 * recomendada é `function( string $permission ): bool`, pelo mesmo motivo de
 * `Bootstrap::withCapabilityDecider()` — `implements ScreenAccess` obrigaria
 * o plugin a declarar a classe condicionalmente, porque a biblioteca pode
 * estar presente e ainda não prefixada. A interface continua existindo e
 * continua válida para quem preferir objeto. As duas formas são normalizadas
 * para um `ScreenAccess` único no construtor (`CallableScreenAccess` para a
 * função) — `NavCapabilityGate` e `TreeBuilder` recebem sempre a forma
 * normalizada, sem dois caminhos de execução internos. Passar algo que não é
 * nem função nem `ScreenAccess` falha aqui, no construtor — nunca depois,
 * em silêncio.
 *
 * ⚠️ **Ciclo de vida do respondente:** quem o constrói é o plugin, e esta
 * classe o reusa — o mesmo respondente serve a `tree()`, a `accessMap()` e
 * ao gate (§3, §4). Um cache guardado dentro dele (o que o §3 pede de toda
 * implementação) vale por requisição **se, e só se**, o plugin criar um
 * respondente só e uma `Navigation` só. Duas instâncias de `Navigation`
 * construídas no mesmo ciclo com respondentes diferentes releem tudo duas
 * vezes em `tree()`/`canView()`/`accessMap()` — sem nada quebrar
 * visivelmente, é só o custo de não reaproveitar o cache de uma instância
 * na outra.
 *
 * **Construir `Navigation` mais de uma vez no mesmo ciclo é uso normal da
 * API — o gate de acesso direto (`NavCapabilityGate`) é único por
 * PROCESSO, não por instância** (defeito medido em produção no RIT360
 * Flow, 07/09/2026: duas `Navigation`, uma no boot e outra ao montar a
 * tela, penduravam cada uma o próprio filtro `user_has_cap`, e a segunda
 * sobrescrevia a resposta da primeira — 403 para tela permitida). O que se
 * paga por instância adicional é só releitura (parágrafo acima), nunca
 * concorrência entre respostas: o gate agrega TODAS as declarações
 * (`Registry`+`ScreenAccess`) de todas as `Navigation` construídas no
 * processo antes de responder por qualquer capability sintética ou pela
 * entrada raiz do menu (ver docblock de `NavCapabilityGate`).
 */
final class Navigation {

	/** @var Registry */
	private $registry;

	/** @var ScreenAccess */
	private $access;

	/** @var NavCapabilityGate */
	private $gate;

	/** @var MenuEntry|null */
	private $menuEntry;

	/**
	 * @param Registry              $registry
	 * @param ScreenAccess|callable $access   `function( string $permission ): bool`,
	 *                                        ou um objeto `ScreenAccess` (§3).
	 *
	 * @throws \InvalidArgumentException Se `$access` não for nem uma função,
	 *                                    nem um objeto `ScreenAccess`.
	 */
	public function __construct( Registry $registry, $access ) {
		$this->registry = $registry;
		$this->access   = self::normalizeAccess( $access );
		$this->gate     = new NavCapabilityGate( $registry, $this->access );
		$this->gate->register();
	}

	/**
	 * Normaliza `$access` para um `ScreenAccess` único — o único ponto da
	 * biblioteca que sabe que existem duas formas de entrada (docblock da
	 * classe). Objeto que já implementa a interface passa direto, sem
	 * embrulho: nenhuma consulta extra é introduzida em nenhuma das formas.
	 *
	 * @param mixed $access
	 *
	 * @throws \InvalidArgumentException Se `$access` não for nem uma função,
	 *                                    nem um objeto `ScreenAccess`.
	 */
	private static function normalizeAccess( $access ): ScreenAccess {
		if ( $access instanceof ScreenAccess ) {
			return $access;
		}

		if ( is_callable( $access ) ) {
			return new CallableScreenAccess( $access );
		}

		throw new \InvalidArgumentException(
			'Navigation::__construct(): $access precisa ser uma função ' .
			'`function( string $permission ): bool` ou um objeto que implemente ' .
			'V3R\\Core\\Admin\\Nav\\ScreenAccess (docs/navegacao-do-painel.md §3). Recebido: ' .
			( is_object( $access ) ? get_class( $access ) : gettype( $access ) ) . '.'
		);
	}

	/**
	 * A árvore já filtrada para o usuário corrente (§5).
	 *
	 * @param string|null $surface Superfície a filtrar (§2) — string livre do
	 *                             produto, não da biblioteca. `null` (padrão):
	 *                             sem filtro, exatamente o comportamento de
	 *                             antes desta opção existir. Informada: só as
	 *                             telas que pertencem a ela (`Screen::surfaces()`
	 *                             vazio pertence a qualquer superfície).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function tree( ?string $surface = null ): array {
		return ( new TreeBuilder( $this->registry, $this->access, $surface ) )->build();
	}

	/**
	 * A mesma pergunta que a árvore responde para uma tela, por slug —
	 * para a própria tela reconferir antes de desenhar (§4, camada 2).
	 * Tela desconhecida nunca é vista: falha fechado.
	 */
	public function canView( string $slug ): bool {
		$screen = $this->registry->findScreen( $slug );

		if ( null === $screen ) {
			return false;
		}

		return $this->access->canView( $screen->permission() );
	}

	/**
	 * `canView()` estendido para TODAS as telas declaradas de uma vez —
	 * visíveis e ocultas —, no formato pensado para virar dado da página
	 * (ex.: `wp_localize_script`) e ser consultado por slug, sem o
	 * consumidor percorrer nada: `array<slug, bool>`.
	 *
	 * Nasceu do buraco medido no RIT360 Flow (06/09/2026, §4): quem roteia
	 * inteiramente no cliente (`HashRouter` e afins) tem um roteador que
	 * decide o que desenhar **antes** de qualquer requisição ao servidor —
	 * e `tree()` não serve de fonte de autorização para ele, porque omite
	 * as ocultas (§5) e o deixaria sem guarda exatamente nas rotas
	 * transitórias que motivaram `hidden`. Este método é o dado que falta
	 * para o roteador saber, e não só a árvore desenhar.
	 *
	 * ⚠️ Tela sem permissão para o usuário corrente **entra no mapa com
	 * `false`** — nunca é omitida. Omitir a tornaria indistinguível de
	 * slug inexistente, e é exatamente essa distinção que o roteador
	 * precisa para diferenciar "não pode" de "não existe".
	 *
	 * Reaproveita o mesmo `ScreenAccess` (§3): `canView()` dele é chamado
	 * no máximo uma vez por permissão DISTINTA, mesmo com muitas telas
	 * compartilhando a mesma permissão — o cache é desta função (não deste
	 * método reaproveitar o cache interno de uma implementação específica
	 * de `ScreenAccess`, que é opcional; aqui é garantido sempre).
	 *
	 * Tela duplicada (mesmo slug declarado duas vezes) produz uma entrada
	 * só no mapa — a do primeiro registro, mesmo critério que
	 * `Registry::findScreen()` (e por extensão `canView()`) já usam para
	 * decidir qual `Screen` responde por um slug.
	 *
	 * **Superfície (`$surface`, adoção do V3RLGPD, 07/09/2026):** tela fora
	 * da superfície pedida é **omitida** do mapa — não entra com `false`.
	 * É o oposto deliberado da regra de "sem permissão entra com `false`"
	 * logo acima, e a distinção importa: presente com `false` é "existe e
	 * você não pode"; ausente é "essa tela não existe aqui". Superfície é
	 * escopo, não permissão — omitir é o que preserva essa diferença para
	 * o roteador do consumidor (docs/navegacao-do-painel.md §5). `null`
	 * (padrão): sem filtro, mapa completo como sempre foi.
	 *
	 * @return array<string, bool>
	 */
	public function accessMap( ?string $surface = null ): array {
		$map          = array();
		$byPermission = array();

		foreach ( $this->registry->screens() as $screen ) {
			if ( ! $screen->belongsToSurface( $surface ) ) {
				continue;
			}

			$slug = $screen->slug();

			if ( array_key_exists( $slug, $map ) ) {
				continue;
			}

			$permission = $screen->permission();

			if ( ! array_key_exists( $permission, $byPermission ) ) {
				$byPermission[ $permission ] = $this->access->canView( $permission );
			}

			$map[ $slug ] = $byPermission[ $permission ];
		}

		return $map;
	}

	/**
	 * Guarda a entrada declarada e prende `renderMenu()` ao hook
	 * `admin_menu` — mesmo padrão de Licensing\AdminPage::register(): o
	 * método que de fato registra as páginas (`renderMenu()`) é público e
	 * chamável direto por teste, sem depender do hook disparar.
	 *
	 * No-op fora do WordPress, mesmo padrão do resto da biblioteca.
	 */
	public function registerMenu( MenuEntry $entry ): void {
		$this->menuEntry = $entry;

		// Avisa a guarda de acesso direto, imediatamente — não espera o hook
		// `admin_menu` disparar, porque outra coisa (accessMap() consultado
		// cedo, outro plugin perguntando) pode perguntar pela capability da
		// raiz antes disso (ver docblock de NavCapabilityGate, "A capability
		// da entrada raiz é POR PLUGIN").
		NavCapabilityGate::registerRootMenu( $this->registry, $this->access, $entry->slug() );

		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'renderMenu' ) );
	}

	/**
	 * Registra a entrada única visível (§6) e, como página oculta por
	 * tela (§4, camada 1), cada `Screen` acumulada no `Registry` até este
	 * momento — motivo pelo qual toda declaração de tela deve acontecer
	 * antes de `admin_menu` disparar (mesma janela que já vale para
	 * qualquer callback desse hook no WordPress).
	 *
	 * Chamado pelo próprio WordPress (via `registerMenu()`); no-op se
	 * `registerMenu()` nunca foi chamado.
	 */
	public function renderMenu(): void {
		if ( null === $this->menuEntry ) {
			return;
		}

		$this->addMainMenuPage( $this->menuEntry );
		$this->addHiddenScreenPages();
	}

	/**
	 * A capability da entrada visível é a sintética
	 * `NavCapabilityGate::rootCapabilityFor( $entry->slug() )`, nunca
	 * `'read'` — a entrada é o nó raiz da árvore, e visibilidade de um nó
	 * com filhos é sempre derivada dos filhos (§5): sem nenhuma tela
	 * visível para o usuário, a entrada some da coluna do painel em vez de
	 * abrir numa tela vazia. Derivar do slug do MENU (não de uma constante
	 * fixa da biblioteca) é o que torna esta capability própria de CADA
	 * plugin — ver docblock de `NavCapabilityGate`.
	 */
	private function addMainMenuPage( MenuEntry $entry ): void {
		if ( ! function_exists( 'add_menu_page' ) ) {
			return;
		}

		add_menu_page(
			$entry->title(),
			$entry->title(),
			NavCapabilityGate::rootCapabilityFor( $entry->slug() ),
			$entry->slug(),
			static function (): void {
				// Esta camada não desenha nada (§7) — quem consome
				// registra o próprio conteúdo, ou usa uma barra vinda da
				// camada de desenho (#26) quando ela existir.
			},
			self::iconDataUri( $entry->family() )
		);
	}

	private function addHiddenScreenPages(): void {
		if ( ! function_exists( 'add_submenu_page' ) ) {
			return;
		}

		foreach ( $this->registry->screens() as $screen ) {
			add_submenu_page(
				null,
				$screen->label(),
				$screen->label(),
				NavCapabilityGate::capabilityFor( $screen->slug() ),
				$screen->slug(),
				static function (): void {
					// Página oculta que só existe para o WordPress ter o
					// que barrar (§4, camada 1) — não desenha nada (§7).
				}
			);
		}
	}

	/**
	 * O ícone da família, como data URI (`fill="currentColor"` — o
	 * WordPress tinge sozinho, ver src/Assets/brand/README.md). Nunca o
	 * ícone do produto individual.
	 */
	private static function iconDataUri( string $family ): string {
		$path = dirname( __DIR__, 2 ) . '/Assets/brand/' . Family::iconFileName( $family );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lê ativo local embutido na própria biblioteca, não uma URL remota.
		$svg = is_readable( $path ) ? file_get_contents( $path ) : false;

		if ( false === $svg ) {
			return 'dashicons-admin-generic';
		}

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
