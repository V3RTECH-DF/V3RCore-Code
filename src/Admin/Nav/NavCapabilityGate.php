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
 * (§5, §6):** a capability sintética da raiz (`rootCapabilityFor()`) só é
 * concedida quando ao menos uma tela registrada é visível para o usuário —
 * o mesmo "grupo vazio não aparece" aplicado ao nível mais alto da árvore,
 * para a entrada nunca abrir numa tela vazia para quem não pode ver nada
 * dentro dela.
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
 * tela nossa, reaproveitando o MESMO cálculo agregado que já responde pela
 * capability da raiz. Nunca a NEGAMOS explicitamente — só acrescentamos o
 * `true` quando aplicável — porque ela não é nossa: outro plugin ou papel
 * pode já tê-la concedido por outro motivo, e negar tiraria acesso que não
 * nos pertence conceder nem revogar. O mesmo risco de bloqueio silencioso
 * vale para qualquer plugin da casa com navegação por papel próprio
 * convivendo com WooCommerce, ou com plugin de associação/área do cliente
 * que restrinja o painel do mesmo jeito.
 *
 * **Autoinvalidação do cache agregado (defeito medido investigando um 403 do
 * RIT360 Flow, 07/09/2026):** o resultado agregado era calculado uma única
 * vez e reusado para sempre — se qualquer coisa perguntasse pela
 * capability antes de o plugin terminar de declarar as telas (outro
 * plugin, o WooCommerce, qualquer código que rode cedo no ciclo do
 * WordPress), a resposta "não" ficava presa pelo resto da requisição,
 * mesmo depois de o `Registry` se completar. O cálculo agora guarda também
 * uma assinatura — a quantidade de telas de CADA registro conhecido no
 * momento em que calculou o agregado — e descarta TODO o cache agregado
 * sempre que essa assinatura muda, deduzido dos próprios `Registry`
 * envolvidos, sem o consumidor precisar avisar nada. Como `Registry` só
 * acumula (não há remoção), a assinatura muda se e somente se o conjunto
 * de telas declaradas mudou.
 *
 * **O cache agregado é POR PESSOA (defeito medido no mesmo levantamento,
 * mais grave que o anterior e que a correção acima não fechava sozinha):**
 * a assinatura por si só não muda quando quem pergunta cedo demais (antes
 * de a identidade do usuário estar resolvida, quando
 * `get_current_user_id()` ainda responde `0`) é diferente de quem pergunta
 * depois, já logado. O agregado calculado para o usuário `0` — que não
 * enxerga nada — ficava preso e era servido para QUALQUER usuário
 * seguinte, porque nada nele sabia "de quem" era a resposta. O cache virou
 * um mapa por ID de usuário corrente (`currentUserCacheKey()` — a mesma
 * noção de "usuário corrente" que `isAboutCurrentUser()` já usa, incluindo
 * o fail-safe para fora do WordPress). A mudança na assinatura continua
 * invalidando o mapa inteiro de uma vez (o conjunto de telas é dos
 * registros, não da pessoa); dentro da mesma assinatura, cada pessoa tem
 * sua própria entrada, calculada na primeira consulta dela e reaproveitada
 * nas seguintes.
 *
 * **Guarda responde só sobre o usuário corrente (defeito medido no mesmo
 * levantamento):** `grant()` recebe, no terceiro argumento (`$args`), o ID
 * do usuário sobre quem a pergunta é feita — `user_can( $outro, ... )` é uso
 * normal do WordPress e dispara este mesmo filtro para QUALQUER usuário, não
 * só o corrente. A guarda respondia sempre com base no usuário logado,
 * ignorando esse ID — podendo conceder ou negar errado para terceiros
 * (defeito de autorização, não de conveniência). `ScreenAccess::canView()`
 * responde por contrato (§3) sobre "a pessoa corrente"; perguntado sobre
 * outra pessoa, este filtro agora se cala — não mexe em `$allcaps`, nem
 * concede nem nega — porque inventar resposta para quem o respondente não
 * sabe responder seria pior que não responder. Vale para as capabilities
 * sintéticas e para `view_admin_dashboard`, sem exceção.
 *
 * **Reentrância durante o próprio cálculo (defeito medido em 07/09/2026,
 * mesma classe de risco da reentrância que já causou incidente em
 * `Licensing\CapabilityGate` — aqui na forma de sentinela publicada cedo
 * demais, não de recursão infinita):** o cálculo agregado chama
 * `ScreenAccess::canView()`, que tipicamente chama `current_user_can()` —
 * ou seja, dispara o filtro `user_has_cap` inteiro do WordPress DE NOVO,
 * com todos os plugins do site dentro dele. Se algo, durante essa
 * varredura, perguntar de novo pela capability de raiz para a MESMA
 * pessoa, a versão antiga publicava `false` no início do cálculo e só o
 * corrigia para `true` ao achar a primeira tela visível — quem perguntasse
 * nesse meio-tempo recebia o `false` provisório como se fosse a resposta
 * final, mesmo quando a tela visível existia e seria encontrada segundos
 * depois. Agora o estado em andamento fica separado do cache que só recebe
 * o resultado do cálculo COMPLETO: uma consulta reentrante nunca recomeça
 * um segundo laço (o que recursaria sem fim) e recebe o melhor resultado
 * já apurado pela varredura em curso — `true` assim que ela encontrar a
 * primeira tela visível, e enquanto isso ainda não aconteceu, SILÊNCIO (não
 * mexe em `$allcaps`, mesmo fail-safe honesto já usado para pergunta sobre
 * outra pessoa) em vez do `false` provisório de antes: "ainda não sei" não
 * é "não".
 *
 * **Um filtro por PROCESSO, não por instância (defeito medido em produção
 * no RIT360 Flow, 07/09/2026, provado com duas instâncias de `Navigation`
 * simultâneas — uma no boot, outra ao montar a tela):** `Navigation`
 * constrói e registra um `NavCapabilityGate` no próprio construtor
 * (docs/navegacao-do-painel.md §3), e construir a navegação mais de uma
 * vez no mesmo ciclo é uso normal da API. Cada construção pendurando o
 * PRÓPRIO filtro em `user_has_cap`, na mesma prioridade, virava uma
 * corrida: a segunda instância rodava depois da primeira e SOBRESCREVIA o
 * que ela tinha concedido, com o cálculo respondendo `true` numa instância
 * e `false` na outra para a mesma pessoa e a mesma tela — 403 para quem
 * deveria entrar.
 *
 * A correção: as declarações (`Registry` + `ScreenAccess` de cada
 * registro) são **estáticas — de processo, não de instância** — e o
 * filtro `user_has_cap` é pendurado **uma única vez por processo**,
 * qualquer que seja a quantidade de `NavCapabilityGate`/`Navigation`
 * construídos. `register()` sempre acrescenta a própria declaração à lista
 * estática (sem duplicar o MESMO par já registrado); só a PRIMEIRA chamada
 * de `register()` no processo pendura o filtro — as seguintes só
 * contribuem a declaração. `grant()` e o cálculo agregado passam a
 * enxergar TODOS os registros conhecidos, não só o do `$this` que por
 * acaso pendurou o filtro:
 * - a capability sintética de uma tela é respondida pelo `ScreenAccess` DO
 *   REGISTRO que declarou aquela tela — registros com respondentes
 *   diferentes continuam cada um respondendo pelas próprias telas, nunca
 *   pelo respondente de outro registro;
 * - o agregado ("enxerga ao menos uma tela?", usado pela capability da raiz
 *   e por `view_admin_dashboard`) é verdadeiro se QUALQUER registro tiver
 *   uma tela visível para a pessoa — a busca pára no primeiro `true`
 *   encontrado, percorrendo os registros na ordem em que foram feitos e,
 *   dentro de cada um, as telas na ordem em que foram declaradas;
 * - o cache por pessoa e a autoinvalidação por mudança de conjunto de
 *   telas (acima) continuam valendo, agora sobre a assinatura combinada de
 *   TODOS os registros — um registro novo (`Navigation` construída de
 *   novo) ou uma tela nova em qualquer um deles invalida o cache inteiro,
 *   exatamente como uma tela nova no mesmo registro já invalidava antes.
 *
 * Ignorar o segundo registro em vez de agregá-lo trocaria a corrida por um
 * BURACO — as telas declaradas só nele ficariam sem guarda nenhuma, abrindo
 * normalmente para qualquer um. A resposta final não pode depender de qual
 * `NavCapabilityGate` "ganhou" a corrida de registrar o filtro primeiro:
 * com a agregação, não importa mais qual foi.
 *
 * **A capability da entrada raiz é POR PLUGIN, nunca da biblioteca (defeito
 * medido num WordPress com oito plugins da casa, 07/09/2026, RIT360 Flow +
 * V3RLGPD instalados juntos):** o Strauss prefixa classes e namespaces, mas
 * **não prefixa o valor de uma constante de texto** — a antiga
 * `ROOT_CAPABILITY` era uma string fixa (`'v3r_nav_root_menu_entry'`) igual
 * em TODAS as cópias prefixadas da biblioteca. Cada plugin roda a própria
 * cópia (própria classe, próprio estado estático de processo — ver "Um
 * filtro por PROCESSO" acima), mas a string era a mesma nas duas. Resultado:
 * o `grant()` do plugin A respondia `true` para `v3r_nav_root_menu_entry`
 * (ele enxerga tela), e o `grant()` do plugin B — outra classe, outro
 * `add_filter()`, mas reconhecendo a MESMA string como sua própria raiz —
 * respondia `false` logo em seguida (B não tem tela visível para aquela
 * pessoa), sobrescrevendo o `true` de A no mesmo `$allcaps`. Quem respondia
 * por último vencia — e como isso só afeta a capability agregada da raiz
 * (as capabilities por tela já usam `CAPABILITY_PREFIX . $slug`, que difere
 * entre plugins), a reprodução por linha de comando com um plugin só nunca
 * falhava.
 *
 * A correção: a capability da raiz agora deriva do **slug do menu**
 * declarado por CADA plugin (`rootCapabilityFor()`, análogo a
 * `capabilityFor()` para telas) — `v3r_nav_root_<menuSlug>`, e
 * `Navigation::registerMenu()` avisa esta classe (`registerRootMenu()`) qual
 * slug este PROCESSO (= este plugin, depois do Strauss) reconhece como seu.
 * `grant()`, perguntado por uma capability com o prefixo de raiz, só
 * responde se o slug pedido está entre os que este processo registrou —
 * capability de raiz de OUTRO plugin (mesmo prefixo, slug que este processo
 * nunca viu) não é da conta desta cópia e o filtro se cala, mesma disciplina
 * já aplicada à capability sintética de tela desconhecida (`resolveScreen()`
 * devolvendo `null`). Como o slug do menu já precisa ser único por plugin
 * (é o `menu_slug` do próprio `add_menu_page()` do WordPress), a colisão que
 * causava o defeito deixa de existir.
 *
 * ⚠️ `WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY` **não muda**: continua agregando
 * TODAS as declarações conhecidas do processo (não é por slug de menu),
 * porque ela é do ecossistema — várias cópias concedendo é inofensivo e
 * correto (ver docblock dela). Só a capability da raiz precisava de escopo
 * por plugin.
 *
 * ⚠️ **Exceção deliberada a "sem estado estático"** (o padrão do resto da
 * biblioteca): o filtro `user_has_cap` do WordPress É estado de processo —
 * um `add_filter()` pendurado vale para TODAS as requisições do mesmo
 * processo, de qualquer código, e representar isso como estado estático
 * desta classe é honesto, não um atalho. A exceção vem com uma saída
 * explícita: `resetForTests()` zera TUDO (registros, o filtro pendurado, os
 * dois caches) — sem ela a suíte ficaria ordem-dependente, porque o
 * primeiro teste a chamar `register()` deixaria o filtro pendurado (e o
 * cache quente) para todos os testes seguintes do mesmo processo PHPUnit.
 * `NavCapabilityGateTest::setUp()` chama `resetForTests()` antes de cada
 * teste.
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
	 * Prefixo da capability sintética da entrada raiz do menu (§6) — SEMPRE
	 * seguido do slug do MENU (`MenuEntry::slug()`), nunca do slug de uma
	 * tela: é o que torna a capability POR PLUGIN em vez de compartilhada
	 * entre todas as cópias prefixadas da biblioteca (ver docblock da
	 * classe, "A capability da entrada raiz é POR PLUGIN"). Distinto de
	 * `CAPABILITY_PREFIX` para não colidir com a capability de uma tela cujo
	 * slug por acaso comece com `root_`.
	 */
	public const ROOT_CAPABILITY_PREFIX = 'v3r_nav_root_';

	/** @var Registry */
	private $registry;

	/** @var ScreenAccess */
	private $access;

	/**
	 * @var array<string, array{registry: Registry, access: ScreenAccess}>
	 * TODAS as declarações (`Registry` + `ScreenAccess`) de qualquer
	 * `NavCapabilityGate::register()` chamado neste processo — estático de
	 * propósito (ver docblock da classe, "Um filtro por PROCESSO"). Chave:
	 * `spl_object_id($registry) . ':' . spl_object_id($access)`, só para
	 * registrar o MESMO par duas vezes sem duplicar nada.
	 */
	private static $registrations = array();

	/**
	 * @var array<string, array<string, true>> Para cada slug de menu
	 * (`MenuEntry::slug()`, §6) que ESTE processo reconhece como próprio, o
	 * CONJUNTO de declarações (chaves de `$registrations`) associadas a ele
	 * — alimentado por `registerRootMenu()`, chamado por
	 * `Navigation::registerMenu()` assim que o plugin declara a entrada.
	 * `grant()` só responde por uma capability de raiz
	 * (`ROOT_CAPABILITY_PREFIX . $slug`) cujo `$slug` estiver aqui —
	 * capability de raiz de outro plugin (mesmo prefixo, slug que este
	 * processo nunca registrou) fica de fora e o filtro se cala (ver
	 * docblock da classe). E quando o cálculo acontece, ele varre SÓ as
	 * declarações amarradas a ESTE slug — nunca todas as conhecidas do
	 * processo — para que a raiz de UM menu não misture telas de OUTRO menu
	 * do mesmo plugin.
	 */
	private static $rootMenuRegistrations = array();

	/**
	 * @var bool Se o filtro `user_has_cap` já foi pendurado NESTE processo
	 * — no máximo uma vez, qualquer que seja a quantidade de instâncias
	 * construídas (ver docblock da classe).
	 */
	private static $hooked = false;

	/**
	 * @var array<string, array<int|string, bool>> Resultado agregado
	 * cacheado do cálculo "alguma tela é visível?", por ESCOPO (`view:` +
	 * `WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY`, agregando TODAS as declarações
	 * do processo; ou `root:` + o slug do menu, agregando só as declarações
	 * daquele menu — ver `hasAnyVisibleScreen()`) e, dentro de cada escopo,
	 * por chave de usuário corrente (`currentUserCacheKey()`) — vazio até a
	 * primeira consulta daquele escopo/pessoa (ou depois de invalidado, ver
	 * `$registrationsSignature`). Uma resposta calculada para uma pessoa
	 * nunca é servida para outra, e uma calculada para um escopo nunca é
	 * servida para outro.
	 */
	private static $visibleByScopeAndUser = array();

	/**
	 * @var array<string, int> Assinatura da última vez em que QUALQUER
	 * cache agregado foi calculado: quantidade de telas de cada declaração
	 * conhecida (mesma chave de `$registrations`, todas — não só as do
	 * escopo em cálculo), no momento do cálculo. Usada para invalidar TODOS
	 * os escopos de uma vez quando QUALQUER declaração muda de conjunto de
	 * telas, ou quando uma declaração nova aparece/desaparece — mais
	 * conservador que invalidar só o escopo afetado, e mais simples.
	 */
	private static $registrationsSignature = array();

	/**
	 * @var array<string, array<int|string, bool>> Melhor resultado
	 * conhecido de um cálculo agregado AINDA EM ANDAMENTO, por escopo e por
	 * chave de usuário — existe uma entrada aqui só entre o início e o fim
	 * da varredura daquele escopo para aquela pessoa (ver
	 * hasAnyVisibleScreen()). `true` quando a varredura em andamento já
	 * encontrou uma tela visível; `false` enquanto ainda não encontrou —
	 * nunca é o resultado final publicado, só o estado interno de uma
	 * varredura que ainda não terminou.
	 */
	private static $inProgressByScopeAndUser = array();

	public function __construct( Registry $registry, ScreenAccess $access ) {
		$this->registry = $registry;
		$this->access   = $access;
	}

	/** A capability sintética correspondente a uma tela. Nunca verificada diretamente pelo plugin (§4). */
	public static function capabilityFor( string $slug ): string {
		return self::CAPABILITY_PREFIX . $slug;
	}

	/**
	 * A capability sintética correspondente à entrada raiz do menu de UM
	 * plugin específico — nunca verificada diretamente pelo plugin (§4),
	 * mesma disciplina de `capabilityFor()`. `$menuSlug` é o `slug()` do
	 * `MenuEntry` que o plugin declarou em `Navigation::registerMenu()` — o
	 * que torna esta capability distinta entre plugins, mesmo depois do
	 * Strauss (ver docblock da classe).
	 */
	public static function rootCapabilityFor( string $menuSlug ): string {
		return self::ROOT_CAPABILITY_PREFIX . $menuSlug;
	}

	/**
	 * Associa a declaração `$registry` + `$access` ao slug `$menuSlug` como
	 * a entrada raiz do menu que ela alimenta — chamado por
	 * `Navigation::registerMenu()` assim que o plugin declara a entrada, não
	 * esperando o hook `admin_menu` disparar (outra coisa pode perguntar
	 * pela capability antes disso). `grant()` só responde por
	 * `rootCapabilityFor( $menuSlug )` quando `$menuSlug` está aqui, e o
	 * cálculo agregado ("enxerga ao menos uma tela?") varre SÓ as
	 * declarações amarradas a ESTE slug — nunca as de outro menu deste
	 * mesmo processo, nem as de outro plugin (ver docblock da classe, "A
	 * capability da entrada raiz é POR PLUGIN").
	 *
	 * `$registry`/`$access` precisam já ter sido passados a `register()`
	 * (a própria `Navigation::registerMenu()` garante isso: o construtor já
	 * chamou `$this->gate->register()` antes de `registerMenu()` poder ser
	 * chamado) — associar um par nunca registrado não quebra nada, só não
	 * conta para nenhum cálculo (a declaração continua ausente de
	 * `$registrations`).
	 *
	 * Idempotente: associar o mesmo par ao mesmo slug mais de uma vez (duas
	 * `Navigation` do mesmo plugin, ambas chamando `registerMenu()` com o
	 * mesmo slug) não muda nada.
	 */
	public static function registerRootMenu( Registry $registry, ScreenAccess $access, string $menuSlug ): void {
		$key = spl_object_id( $registry ) . ':' . spl_object_id( $access );

		self::$rootMenuRegistrations[ $menuSlug ][ $key ] = true;
	}

	/**
	 * Acrescenta esta declaração (`Registry` + `ScreenAccess`) à lista de
	 * processo e pendura o filtro `user_has_cap` — mas SÓ NA PRIMEIRA
	 * chamada de `register()` do processo inteiro, qualquer que seja a
	 * instância. Chamadas seguintes (de outras instâncias, de outras
	 * `Navigation`) só contribuem a própria declaração à lista; o filtro já
	 * pendurado passa a enxergá-las também, porque `grant()` percorre a
	 * lista estática, não `$this->registry`/`$this->access` (ver docblock
	 * da classe).
	 *
	 * Registrar o MESMO par `Registry`+`ScreenAccess` mais de uma vez (a
	 * mesma instância chamando `register()` de novo, ou duas instâncias
	 * embrulhando o mesmo par) não duplica a declaração — mesma noção de
	 * idempotência que a classe já garantia para o filtro.
	 *
	 * Seguro fora do WordPress (mesmo padrão de Licensing\CapabilityGate).
	 */
	public function register(): void {
		$key = spl_object_id( $this->registry ) . ':' . spl_object_id( $this->access );

		if ( ! array_key_exists( $key, self::$registrations ) ) {
			self::$registrations[ $key ] = array(
				'registry' => $this->registry,
				'access'   => $this->access,
			);
		}

		if ( self::$hooked ) {
			return;
		}

		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'user_has_cap', array( $this, 'grant' ), 10, 4 );

		self::$hooked = true;
	}

	/**
	 * Zera TODO o estado de processo (registros, filtro pendurado e os dois
	 * caches) — só para uso em teste (`setUp()`), nunca em produção: é o
	 * que mantém a suíte livre de dependência de ordem apesar do estado ser
	 * estático (ver docblock da classe, "Exceção deliberada").
	 */
	public static function resetForTests(): void {
		self::$registrations            = array();
		self::$rootMenuRegistrations    = array();
		self::$hooked                   = false;
		self::$visibleByScopeAndUser    = array();
		self::$registrationsSignature   = array();
		self::$inProgressByScopeAndUser = array();
	}

	/**
	 * Callback de `user_has_cap` — pendurado por, no máximo, UMA instância
	 * por processo (ver `register()`), mas responde por TODOS os registros
	 * conhecidos do processo, não só pelo `$this->registry`/`$this->access`
	 * de quem pendurou.
	 *
	 * @param array<string, bool> $allcaps
	 * @param array<int, string>  $caps
	 * @param array<int, mixed>   $args
	 * @param \WP_User|null       $user
	 * @return array<string, bool>
	 */
	public function grant( array $allcaps, array $caps, array $args, $user = null ): array {
		if ( ! $this->isAboutCurrentUser( $args ) ) {
			// Pergunta sobre outra pessoa (`user_can( $outro, ... )`):
			// ScreenAccess::canView() só sabe responder sobre o usuário
			// corrente (§3 do contrato). Calar-se — sem conceder, sem negar
			// — é o fail-safe honesto; ver docblock da classe.
			return $allcaps;
		}

		foreach ( $caps as $cap ) {
			if ( 0 === strpos( $cap, self::ROOT_CAPABILITY_PREFIX ) ) {
				$menuSlug = substr( $cap, strlen( self::ROOT_CAPABILITY_PREFIX ) );

				if ( ! array_key_exists( $menuSlug, self::$rootMenuRegistrations ) ) {
					// Capability de raiz de OUTRO plugin (mesmo prefixo, mas
					// um slug que este processo nunca registrou via
					// registerRootMenu()) — não é da nossa conta. Mesma
					// disciplina de resolveScreen() devolvendo null: silêncio,
					// sem conceder nem negar (ver docblock da classe, "A
					// capability da entrada raiz é POR PLUGIN").
					continue;
				}

				// Varre SÓ as declarações amarradas a ESTE menu — nunca
				// todas as conhecidas do processo (ver registerRootMenu()).
				$visible = self::hasAnyVisibleScreen(
					array_keys( self::$rootMenuRegistrations[ $menuSlug ] ),
					'root:' . $menuSlug
				);

				if ( null !== $visible ) {
					// `null` só acontece numa consulta reentrante, disparada
					// de dentro do próprio ScreenAccess::canView() enquanto
					// a varredura desta pessoa ainda está em andamento e
					// ainda não achou tela visível — nesse caso nos calamos
					// (não tocamos em $allcaps) em vez de publicar um
					// `false` que seria só o estado interno inacabado (ver
					// docblock da classe). Fora de uma reentrância, a
					// varredura sempre termina antes de responder, então o
					// resultado aqui é sempre definitivo.
					$allcaps[ $cap ] = $visible;
				}
				continue;
			}

			if ( self::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY === $cap ) {
				// Nunca negar: capability alheia, só acrescentamos o `true`
				// (ver docblock da classe). Um valor já concedido por outra
				// origem (outro plugin, outro papel) é preservado. Esta,
				// diferente da capability de raiz, agrega TODAS as
				// declarações conhecidas do processo — ela é do ecossistema,
				// não de um menu específico (ver docblock da classe).
				if ( true === self::hasAnyVisibleScreen( array_keys( self::$registrations ), self::WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY ) ) {
					$allcaps[ $cap ] = true;
				}
				continue;
			}

			if ( 0 !== strpos( $cap, self::CAPABILITY_PREFIX ) ) {
				continue;
			}

			$slug     = substr( $cap, strlen( self::CAPABILITY_PREFIX ) );
			$resolved = self::resolveScreen( $slug );

			if ( null === $resolved ) {
				continue;
			}

			[ $screen, $access ] = $resolved;

			$allcaps[ $cap ] = $access->canView( $screen->permission() );
		}

		return $allcaps;
	}

	/**
	 * Acha, entre TODOS os registros conhecidos do processo, o primeiro
	 * cujo `Registry` declarou o slug pedido — percorridos na ordem em que
	 * foram registrados (§4 do docblock da classe). O `ScreenAccess` que
	 * responde por essa tela é sempre o DAQUELE registro, nunca o de outro
	 * — registros com respondentes diferentes não se misturam.
	 *
	 * @return array{0: Screen, 1: ScreenAccess}|null
	 */
	private static function resolveScreen( string $slug ): ?array {
		foreach ( self::$registrations as $registration ) {
			$screen = $registration['registry']->findScreen( $slug );

			if ( null !== $screen ) {
				return array( $screen, $registration['access'] );
			}
		}

		return null;
	}

	/**
	 * Se alguma tela de QUALQUER declaração em `$registrationKeys` é
	 * visível para o usuário corrente — varre só essas declarações (as
	 * chaves de `$registrations` passadas por quem chama: TODAS as
	 * conhecidas do processo para `WOOCOMMERCE_ADMIN_ACCESS_CAPABILITY`, ou
	 * só as de UM menu para a capability de raiz daquele menu — ver
	 * `grant()`), na ordem em que aparecem em `$registrationKeys` e, dentro
	 * de cada uma, na ordem de declaração das telas, na primeira chamada da
	 * requisição **para este escopo e esta pessoa**, e pára no primeiro
	 * `true` encontrado; chamadas seguintes do MESMO escopo pela MESMA
	 * pessoa devolvem o valor cacheado, sem tocar em nenhum `ScreenAccess`
	 * de novo, **enquanto a assinatura combinada de TODOS os registros
	 * conhecidos não mudar** (ver docblock da classe) — mudou, TODOS os
	 * escopos se invalidam e cada um é recalculado na sua próxima consulta.
	 *
	 * Fora de uma reentrância, sempre devolve um resultado definitivo
	 * (`true`/`false`) — a varredura roda inteira, síncrona, antes de
	 * responder. Só devolve `null` quando CHAMADA REENTRANTEMENTE (de
	 * dentro do próprio `ScreenAccess::canView()`, enquanto a varredura
	 * deste MESMO escopo para esta mesma pessoa ainda está em andamento) e
	 * essa varredura ainda não encontrou nenhuma tela visível: `null` é
	 * "ainda não sei", nunca "não" — quem chama trata como silêncio (ver
	 * `grant()`). Achada uma tela visível, a reentrância passa a receber
	 * `true` na hora, sem nunca recomeçar um segundo laço (o que recursaria
	 * sem fim).
	 *
	 * @param string[] $registrationKeys Chaves de `$registrations` a varrer.
	 * @param string   $scope            Identifica o cálculo nos caches
	 *                                   (`$visibleByScopeAndUser`,
	 *                                   `$inProgressByScopeAndUser`) — dois
	 *                                   escopos nunca compartilham cache
	 *                                   nem estado de varredura em
	 *                                   andamento, mesmo na mesma pessoa.
	 */
	private static function hasAnyVisibleScreen( array $registrationKeys, string $scope ): ?bool {
		$userKey = self::currentUserCacheKey();

		if ( array_key_exists( $userKey, self::$inProgressByScopeAndUser[ $scope ] ?? array() ) ) {
			return self::$inProgressByScopeAndUser[ $scope ][ $userKey ] ? true : null;
		}

		$signature = self::registrationsSignature();

		if ( $signature !== self::$registrationsSignature ) {
			self::$visibleByScopeAndUser  = array();
			self::$registrationsSignature = $signature;
		}

		if ( array_key_exists( $userKey, self::$visibleByScopeAndUser[ $scope ] ?? array() ) ) {
			return self::$visibleByScopeAndUser[ $scope ][ $userKey ];
		}

		self::$inProgressByScopeAndUser[ $scope ][ $userKey ] = false;

		foreach ( $registrationKeys as $key ) {
			$registration = self::$registrations[ $key ] ?? null;

			if ( null === $registration ) {
				continue;
			}

			foreach ( $registration['registry']->screens() as $screen ) {
				if ( $registration['access']->canView( $screen->permission() ) ) {
					self::$inProgressByScopeAndUser[ $scope ][ $userKey ] = true;
					break 2;
				}
			}
		}

		$result = self::$inProgressByScopeAndUser[ $scope ][ $userKey ];
		unset( self::$inProgressByScopeAndUser[ $scope ][ $userKey ] );

		// Só o cálculo COMPLETO publica no cache — nunca o estado
		// intermediário que uma consulta reentrante pôde ter lido acima.
		self::$visibleByScopeAndUser[ $scope ][ $userKey ] = $result;

		return $result;
	}

	/**
	 * A assinatura combinada de TODOS os registros conhecidos do processo
	 * (não só os de um escopo/menu): quantidade de telas de cada um, pela
	 * mesma chave de `$registrations`. Comparada por igualdade de array
	 * (ordem e valores) contra `$registrationsSignature` em
	 * `hasAnyVisibleScreen()` — muda quando QUALQUER registro ganha/perde
	 * telas, ou quando um registro novo aparece (chave nova no array), e
	 * invalida TODOS os escopos de uma vez (mais simples e mais
	 * conservador que invalidar só o escopo afetado).
	 *
	 * @return array<string, int>
	 */
	private static function registrationsSignature(): array {
		$signature = array();

		foreach ( self::$registrations as $key => $registration ) {
			$signature[ $key ] = count( $registration['registry']->screens() );
		}

		return $signature;
	}

	/**
	 * `$args` é o array cru que o WordPress passa ao filtro `user_has_cap`:
	 * `array( $cap, $userId, ...$originalArgs )` — `$args[1]` é o ID do
	 * usuário sobre quem a pergunta é feita, que pode ser QUALQUER usuário
	 * (`user_can( $outro, ... )`), não só o corrente.
	 *
	 * Sem como determinar (função do WordPress ausente, ou `$args[1]`
	 * ausente/nulo) o fail-safe é responder que sim — mesmo comportamento
	 * de sempre, para não regredir nenhum consumidor existente.
	 *
	 * @param array<int, mixed> $args
	 */
	private function isAboutCurrentUser( array $args ): bool {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return true;
		}

		$userId = $args[1] ?? null;

		if ( null === $userId ) {
			return true;
		}

		return (int) get_current_user_id() === (int) $userId;
	}

	/**
	 * A chave de cache do usuário corrente — usada por hasAnyVisibleScreen()
	 * para nunca servir a resposta de uma pessoa a outra. Mesma noção de
	 * "usuário corrente" que isAboutCurrentUser() já usa, incluindo o
	 * fail-safe fora do WordPress (função ausente): aqui o fail-safe não é
	 * "responder sim" (isAboutCurrentUser() já filtrou a chamada antes de
	 * chegar aqui), é uma chave própria e estável para esse contexto, para
	 * não colidir com nenhum ID de usuário real (que é sempre inteiro
	 * não-negativo, inclusive `0` para "identidade ainda não resolvida").
	 *
	 * @return int|string
	 */
	private static function currentUserCacheKey() {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return 'v3r-nav-no-wp-context';
		}

		return (int) get_current_user_id();
	}
}
