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

	public function __construct( Registry $registry, ScreenAccess $access ) {
		$this->registry = $registry;
		$this->access   = $access;
		$this->gate     = new NavCapabilityGate( $registry, $access );
		$this->gate->register();
	}

	/**
	 * A árvore já filtrada para o usuário corrente (§5).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function tree(): array {
		return ( new TreeBuilder( $this->registry, $this->access ) )->build();
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
	 * @return array<string, bool>
	 */
	public function accessMap(): array {
		$map          = array();
		$byPermission = array();

		foreach ( $this->registry->screens() as $screen ) {
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
	 * A capability da entrada visível é a sintética `ROOT_CAPABILITY`
	 * (`NavCapabilityGate`), nunca `'read'` — a entrada é o nó raiz da
	 * árvore, e visibilidade de um nó com filhos é sempre derivada dos
	 * filhos (§5): sem nenhuma tela visível para o usuário, a entrada
	 * some da coluna do painel em vez de abrir numa tela vazia.
	 */
	private function addMainMenuPage( MenuEntry $entry ): void {
		if ( ! function_exists( 'add_menu_page' ) ) {
			return;
		}

		add_menu_page(
			$entry->title(),
			$entry->title(),
			NavCapabilityGate::ROOT_CAPABILITY,
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
