<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

/**
 * Concede as capabilities sintéticas de licença (leitura/gestão) via
 * `user_has_cap`, com a guarda de saída antecipada embutida e
 * inescapável — é o que fecha V3RCore-Code#12 (a lib exigia as
 * capabilities e deixava cada plugin se defender sozinho contra a
 * recursão `user_has_cap → user_can → user_has_cap`).
 *
 * **Contrato com o plugin hospedeiro:** a função de decisão passada ao
 * construtor SÓ é chamada quando `$caps` (as capabilities pedidas na
 * chamada corrente a `current_user_can()`/`user_can()`) contém a
 * capability de leitura ou a de gestão desta licença. Para qualquer
 * outra capability — incluindo `manage_options`, que o RBAC do plugin
 * hospedeiro tipicamente consulta por dentro da própria função de
 * decisão — `grant()` devolve `$allcaps` sem tocar na função de decisão.
 * É essa saída antecipada, e não a função de decisão, que quebra o
 * ciclo: quando `user_can($uid, 'manage_options')` reentra em
 * `user_has_cap`, `$caps` pedido é `['manage_options']`, a guarda não
 * encontra nenhuma capability de licença ali e sai na hora — sem chamar
 * a função de decisão de novo. O plugin pode chamar
 * `user_can()`/`current_user_can()` à vontade dentro da função de
 * decisão sem criar recursão.
 *
 * **A guarda acima depende de uma premissa que ela sozinha não garante:**
 * a capability-ponte precisa ter nome próprio — algo que mais nada no
 * WordPress consulta. Se a ponte FOR uma capability nativa (ex.:
 * `manage_options`), a premissa cai e o ciclo se fecha por dentro: a
 * própria consulta a essa capability nativa, em qualquer lugar do site,
 * já é `$caps === ['manage_options']`, então a guarda de saída antecipada
 * NÃO sai — encontra a ponte ali e chama a função de decisão, que
 * tipicamente traduz para a permissão nativa correspondente chamando
 * `user_can()`/`current_user_can()` com o MESMO nome, reentrando em
 * `user_has_cap` pela mesma capability, indefinidamente. Resultado:
 * estouro de pilha em toda requisição autenticada, com o site anônimo
 * respondendo normalmente (V3RCore-Code#18). Por isso o construtor
 * recusa esse nome — antes de qualquer filtro ser registrado.
 */
final class CapabilityGate {

	/**
	 * Capabilities nativas do WordPress (núcleo, single-site e
	 * multisite) — nomes que o próprio WordPress consulta por conta
	 * própria em algum ponto de uma requisição normal (menu admin,
	 * `map_meta_cap`, telas de núcleo), e que por isso não podem servir
	 * de capability-ponte (V3RCore-Code#18). Lista estática, levantada a
	 * partir dos papéis padrão do núcleo (`wp-admin/includes/schema.php`,
	 * `populate_roles()`); o WordPress raramente acrescenta capability
	 * nativa nova, mas esta lista pode envelhecer — não é geração
	 * dinâmica via `wp_roles()` porque o Bootstrap precisa validar antes
	 * de qualquer garantia de que os papéis já estejam carregados (e
	 * precisa funcionar em teste de unidade, fora do WordPress).
	 *
	 * Deliberadamente NÃO inclui capability de terceiro (de outro
	 * plugin): essas não têm como ser enumeradas, e a ameaça mecânica
	 * documentada acima só existe para capability que o núcleo consulta
	 * sozinho — uma capability de outro plugin só entraria em conflito
	 * se o próprio hospedeiro reentrasse nela de propósito, o que já é
	 * decisão dele, não desta lib.
	 *
	 * @var string[]
	 */
	private const NATIVE_WORDPRESS_CAPABILITIES = array(
		// Papéis de núcleo — single site.
		'activate_plugins',
		'create_users',
		'customize',
		'delete_others_pages',
		'delete_others_posts',
		'delete_pages',
		'delete_plugins',
		'delete_posts',
		'delete_private_pages',
		'delete_private_posts',
		'delete_published_pages',
		'delete_published_posts',
		'delete_themes',
		'delete_users',
		'edit_dashboard',
		'edit_files',
		'edit_others_pages',
		'edit_others_posts',
		'edit_pages',
		'edit_plugins',
		'edit_posts',
		'edit_private_pages',
		'edit_private_posts',
		'edit_published_pages',
		'edit_published_posts',
		'edit_theme_options',
		'edit_themes',
		'edit_users',
		'export',
		'import',
		'install_plugins',
		'install_themes',
		'list_users',
		'manage_categories',
		'manage_links',
		'manage_options',
		'moderate_comments',
		'promote_users',
		'publish_pages',
		'publish_posts',
		'read',
		'read_private_pages',
		'read_private_posts',
		'remove_users',
		'switch_themes',
		'unfiltered_html',
		'unfiltered_upload',
		'update_core',
		'update_plugins',
		'update_themes',
		'upload_files',
		// Multisite (super admin) — só existem em rede, mas o nome é
		// reservado pelo núcleo de qualquer forma.
		'create_sites',
		'delete_site',
		'delete_sites',
		'manage_network',
		'manage_network_options',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_users',
		'manage_sites',
		'setup_network',
		'upgrade_network',
		'upgrade_php',
	);

	/** @var string */
	private $readCapability;

	/** @var string */
	private $manageCapability;

	/** @var callable */
	private $decider;

	/** @var bool */
	private $registered;

	/**
	 * @param string   $readCapability   Capability de leitura (docs/api-contract.md §8.2).
	 * @param string   $manageCapability Capability de gestão (docs/api-contract.md §8.2).
	 * @param callable $decider          `function( int $userId, string $capability ): bool`.
	 *                                   Responde se $userId pode $capability (a de
	 *                                   leitura ou a de gestão — nunca chamada para
	 *                                   outra). Aqui, e só aqui, o plugin hospedeiro
	 *                                   consulta o próprio RBAC.
	 *
	 * @throws \InvalidArgumentException Se `$readCapability` ou
	 *                                    `$manageCapability` for uma
	 *                                    capability nativa do WordPress
	 *                                    (V3RCore-Code#18) — ver o
	 *                                    docblock da classe para o porquê.
	 */
	public function __construct( string $readCapability, string $manageCapability, callable $decider ) {
		self::assertBridgeable( $readCapability, 'de leitura' );
		self::assertBridgeable( $manageCapability, 'de gestão' );

		$this->readCapability   = $readCapability;
		$this->manageCapability = $manageCapability;
		$this->decider          = $decider;
		$this->registered       = false;
	}

	/**
	 * Recusa uma capability-ponte que colida com o núcleo do WordPress —
	 * ver o docblock da classe (V3RCore-Code#18) para o mecanismo exato
	 * da recursão que isso evita.
	 *
	 * @throws \InvalidArgumentException Nomeando a capability e o motivo.
	 */
	private static function assertBridgeable( string $capability, string $papel ): void {
		if ( in_array( $capability, self::NATIVE_WORDPRESS_CAPABILITIES, true ) ) {
			throw new \InvalidArgumentException(
				"Capability-ponte {$papel} inválida: '{$capability}' é uma capability nativa do " .
				'WordPress. O WordPress já a consulta sozinho (menu admin, map_meta_cap, telas de ' .
				'núcleo) em qualquer requisição autenticada; usá-la como ponte fecha um ciclo em ' .
				'user_has_cap e derruba o site para todo usuário logado. Use um nome próprio do ' .
				"plugin (ex.: 'meuplugin_license_" . ( 'de leitura' === $papel ? 'view' : 'manage' ) . "') " .
				'e traduza para a permissão real dentro da função de decisão passada a ' .
				'Bootstrap::withCapabilityDecider().'
			);
		}
	}

	/**
	 * Registra o filtro `user_has_cap`. Idempotente e seguro fora do
	 * WordPress (mesmo padrão de Updater\UpdateChecker::register()) — em
	 * teste de unidade sem `add_filter()` disponível, é um no-op.
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
	 * Callback de `user_has_cap`. A guarda das linhas abaixo — devolver
	 * $allcaps sem chamar $this->decider — roda ANTES de qualquer
	 * consulta ao plugin hospedeiro; é ela, não a função de decisão, que
	 * garante que a reentrância nunca chega ao RBAC do hospedeiro.
	 *
	 * @param array<string, bool> $allcaps
	 * @param array<int, string>  $caps
	 * @param array<int, mixed>   $args
	 * @param \WP_User|null       $user
	 * @return array<string, bool>
	 */
	public function grant( array $allcaps, array $caps, array $args, $user = null ): array {
		$relevant = array_intersect( $caps, array( $this->readCapability, $this->manageCapability ) );

		if ( empty( $relevant ) ) {
			return $allcaps;
		}

		$userId = isset( $args[1] ) ? (int) $args[1] : ( isset( $user->ID ) ? (int) $user->ID : 0 );

		foreach ( $relevant as $capability ) {
			$allcaps[ $capability ] = (bool) call_user_func( $this->decider, $userId, $capability );
		}

		return $allcaps;
	}
}
