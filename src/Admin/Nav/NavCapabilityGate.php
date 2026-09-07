<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * A guarda de acesso direto (docs/navegacao-do-painel.md §4): responde
 * pela capability sintética `v3r_nav_<slug>` no filtro `user_has_cap`,
 * delegando ao `ScreenAccess` do plugin — a MESMA instância e a MESMA
 * `Screen::permission()` que `TreeBuilder` usa para filtrar a árvore.
 * É essa fonte única que torna impossível a tela aparecer/sumir da
 * navegação e o endereço direto discordar dela.
 *
 * Efeito (§4): o WordPress passa a poder registrar `add_submenu_page()`
 * com uma capability só sua para toda tela, mesmo quando o plugin usa um
 * motor de permissão próprio sem capability nenhuma — sem que o plugin
 * precise inventar capability nativa de mentira.
 *
 * Sem risco de recursão como o de `Licensing\CapabilityGate`
 * (V3RCore-Code#12/#18): a capability-ponte aqui tem sempre o prefixo
 * `v3r_nav_`, escolhido por esta classe e nunca pelo hospedeiro — não há
 * como colidir com uma capability nativa do WordPress nem com a
 * permissão real que `ScreenAccess::canView()` consulta por baixo (essa é
 * outra string, sem o prefixo). Uma capability pedida que carregue o
 * prefixo mas não corresponda a nenhuma tela registrada não é concedida
 * nem negada explicitamente — o filtro simplesmente não mexe nela.
 *
 * **A entrada raiz do menu segue a mesma regra de derivação dos grupos
 * (§5, §6):** `ROOT_CAPABILITY` só é concedida quando ao menos uma tela
 * registrada é visível para o usuário — o mesmo "grupo vazio não
 * aparece" aplicado ao nível mais alto da árvore, para a entrada nunca
 * abrir numa tela vazia para quem não pode ver nada dentro dela. A
 * resposta é computada uma vez por requisição (`$rootVisible`,
 * `hasAnyVisibleScreen()`) e reaproveitada em qualquer chamada seguinte
 * do filtro — sem isso, cada consulta a `current_user_can(ROOT_CAPABILITY)`
 * (inclusive as que o próprio `wp-admin` faz ao montar a coluna do menu)
 * varreria todas as telas de novo. `ScreenAccess::canView()` já cacheia
 * por permissão (contrato do §3); este cache aqui é sobre o RESULTADO
 * agregado da varredura, não substitui aquele.
 *
 * **`view_admin_dashboard` (defeito medido em produção em 06/09/2026, na
 * primeira adoção real desta camada, RIT360 Flow):** não é capability
 * nossa — é a saída que o próprio WooCommerce desenhou para este caso.
 * `WC_Admin::prevent_admin_access()`
 * (`wp-content/plugins/woocommerce/includes/admin/class-wc-admin.php:175-215`)
 * redireciona para fora do `wp-admin` (302 para a página de conta) todo
 * usuário que não tenha nenhuma destas três capabilities: `edit_posts`,
 * `manage_woocommerce`, `view_admin_dashboard`. Papel próprio de plugin
 * costuma ter só `read` mais as capabilities do próprio plugin, e por isso
 * caía nesse bloqueio ANTES de a nossa camada agir — a tela negada dava
 * 403 (correto), mas a tela PERMITIDA também nunca era alcançada, porque o
 * WooCommerce já tinha expulsado a pessoa do painel antes de chegar lá.
 * A correção: conceder `view_admin_dashboard` a quem enxerga ao menos uma
 * tela nossa, reaproveitando o MESMO `hasAnyVisibleScreen()`/cache que já
 * respondia por `ROOT_CAPABILITY`. Nunca a NEGAMOS explicitamente — só
 * acrescentamos o `true` quando aplicável — porque ela não é nossa: outro
 * plugin ou papel pode já tê-la concedido por outro motivo, e negar
 * tiraria acesso que não nos pertence conceder nem revogar. O mesmo risco
 * de bloqueio silencioso vale para qualquer plugin da casa com navegação
 * por papel próprio convivendo com WooCommerce, ou com plugin de
 * associação/área do cliente que restrinja o painel do mesmo jeito.
 */
final class NavCapabilityGate {

	public const CAPABILITY_PREFIX = 'v3r_nav_';

	/**
	 * Não é nossa — é a capability do próprio WooCommerce que evita o
	 * redirecionamento para fora do `wp-admin` (ver docblock da classe).
	 * Fica fora do `CAPABILITY_PREFIX` de propósito: não é uma
	 * capability-ponte para uma tela, é a condição de um terceiro sendo
	 * satisfeita.
	 */
	public const WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY = 'view_admin_dashboard';

	/**
	 * A capability sintética da entrada raiz do menu (§6) — nunca deriva de
	 * um slug de tela, por isso não usa `CAPABILITY_PREFIX` seguido de um
	 * slug real: evita colisão com uma tela que por acaso se chamasse
	 * `root`.
	 */
	public const ROOT_CAPABILITY = 'v3r_nav_root_menu_entry';

	/** @var Registry */
	private $registry;

	/** @var ScreenAccess */
	private $access;

	/** @var bool */
	private $registered = false;

	/** @var bool|null Resultado agregado cacheado de hasAnyVisibleScreen() — null até a primeira consulta. */
	private $rootVisible;

	public function __construct( Registry $registry, ScreenAccess $access ) {
		$this->registry = $registry;
		$this->access   = $access;
	}

	/** A capability sintética correspondente a uma tela. Nunca verificada diretamente pelo plugin (§4). */
	public static function capabilityFor( string $slug ): string {
		return self::CAPABILITY_PREFIX . $slug;
	}

	/**
	 * Registra o filtro `user_has_cap`. Idempotente e seguro fora do
	 * WordPress (mesmo padrão de Licensing\CapabilityGate).
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'user_has_cap', array( $this, 'grant' ), 10, 4 );

		$this->registered = true;
	}

	/**
	 * Callback de `user_has_cap`.
	 *
	 * @param array<string, bool> $allcaps
	 * @param array<int, string>  $caps
	 * @param array<int, mixed>   $args
	 * @param \WP_User|null       $user
	 * @return array<string, bool>
	 */
	public function grant( array $allcaps, array $caps, array $args, $user = null ): array {
		foreach ( $caps as $cap ) {
			if ( self::ROOT_CAPABILITY === $cap ) {
				$allcaps[ $cap ] = $this->hasAnyVisibleScreen();
				continue;
			}

			if ( self::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY === $cap ) {
				// Nunca negar: capability alheia, só acrescentamos o `true`
				// (ver docblock da classe). Um valor já concedido por outra
				// origem (outro plugin, outro papel) é preservado.
				if ( $this->hasAnyVisibleScreen() ) {
					$allcaps[ $cap ] = true;
				}
				continue;
			}

			if ( 0 !== strpos( $cap, self::CAPABILITY_PREFIX ) ) {
				continue;
			}

			$slug   = substr( $cap, strlen( self::CAPABILITY_PREFIX ) );
			$screen = $this->registry->findScreen( $slug );

			if ( null === $screen ) {
				continue;
			}

			$allcaps[ $cap ] = $this->access->canView( $screen->permission() );
		}

		return $allcaps;
	}

	/**
	 * Se alguma tela registrada é visível para o usuário corrente —
	 * varre `Registry::screens()` só na primeira chamada da requisição e
	 * pára no primeiro `true` encontrado; chamadas seguintes devolvem o
	 * valor cacheado, sem tocar em `ScreenAccess` de novo.
	 */
	private function hasAnyVisibleScreen(): bool {
		if ( null === $this->rootVisible ) {
			$this->rootVisible = false;

			foreach ( $this->registry->screens() as $screen ) {
				if ( $this->access->canView( $screen->permission() ) ) {
					$this->rootVisible = true;
					break;
				}
			}
		}

		return $this->rootVisible;
	}
}
