<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * Mantém as entradas da casa **juntas** na coluna do painel, em dois
 * blocos em sequência — família RIT e depois família V3RTECH —, sem
 * nenhum plugin de terceiro entre elas, e em ordem alfabética dentro de
 * cada bloco (V3RCore-Code#25, decisão de projeto de 05/09/2026).
 *
 * A posição pedida no registro (`add_menu_page()`) é só um número que
 * qualquer plugin de terceiro também pode pedir: declarar posições
 * vizinhas reduz a probabilidade de intromissão, não a elimina. Por isso a
 * contiguidade é garantida **depois** que todos os plugins se registraram,
 * pelo par de filtros `custom_menu_order`/`menu_order` do próprio
 * WordPress — o único momento em que a coluna inteira já existe.
 *
 * ## ⚠️ O anúncio é COMPARTILHADO entre as cópias prefixadas — de propósito
 *
 * Cada plugin da casa embute a própria cópia da biblioteca (Strauss), com
 * as próprias classes e o próprio estado estático: a cópia do plugin A não
 * enxerga o `Registry` da cópia do plugin B. Uma reordenação que
 * conhecesse apenas a própria entrada não teria como manter os blocos
 * contíguos — cada cópia empurraria a sua para o lugar certo e
 * atropelaria a decisão da anterior, que é exatamente a briga que a issue
 * previu.
 *
 * A saída é anunciar as entradas num lugar que TODAS as cópias enxergam:
 * uma global do PHP de nome fixo (`GLOBAL_KEY`), deliberadamente **não**
 * prefixada. Com o anúncio compartilhado, `reorder()` é uma função pura do
 * conjunto anunciado mais a ordem recebida — **idempotente** (rodar de
 * novo devolve o mesmo array) e **convergente** (todas as cópias calculam
 * o mesmo resultado). Não importa quantas cópias pendurem o filtro nem em
 * que ordem elas rodem.
 *
 * É o oposto deliberado do defeito da capability de raiz, que a mesma
 * camada pagou caro (`NavCapabilityGate`, "A capability da entrada raiz é
 * POR PLUGIN"): lá, o valor de texto igual em todas as cópias fazia um
 * plugin responder pela pergunta do outro, e a correção foi separar por
 * plugin. Aqui a distinção é a natureza do dado — capability é uma
 * RESPOSTA sobre uma pessoa, e responder pelo alheio é errado; posição no
 * menu é um FATO sobre a coluna do site, um só para todo mundo, e a única
 * forma de acertá-lo é todas as cópias partirem do mesmo conjunto. O que
 * torna o compartilhamento seguro aqui é a chave: o slug do menu já é
 * único por plugin no WordPress, então duas cópias nunca escrevem a mesma
 * chave com significados diferentes.
 *
 * Entrada anunciada por uma cópia de versão desconhecida (formato
 * diferente do esperado) é **ignorada** na leitura, nunca causa erro:
 * pior que reordenar mal é derrubar o menu de quem instalou uma
 * combinação de versões que ninguém previu.
 *
 * ## O que o filtro NÃO faz
 *
 * Não move entrada de terceiro para longe, não esconde nada e não mexe na
 * posição relativa entre os de terceiros: as entradas de fora mantêm a
 * ordem em que já estavam, e o bloco da casa é inserido inteiro onde a
 * PRIMEIRA entrada nossa já estava. Reordenar o menu dos outros seria
 * assumir uma decisão que não é nossa.
 */
final class MenuOrder {

	/**
	 * Chave da global compartilhada entre as cópias prefixadas da
	 * biblioteca (ver docblock da classe). **Nunca prefixar** — o valor
	 * igual em todas as cópias é o mecanismo, não um descuido.
	 */
	public const GLOBAL_KEY = 'v3r_nav_family_menu_entries';

	/**
	 * Posição pedida a `add_menu_page()` para cada família — logo acima do
	 * separador que antecede Aparência/Plugins, onde o menu de produto
	 * costuma morar. Só define o BAIRRO e a ordem degradada: a ordem final
	 * é de `reorder()`.
	 *
	 * Existem duas, e não uma, para o degradê ser correto quando a
	 * reordenação não puder rodar (outro plugin devolvendo `false` em
	 * `custom_menu_order` depois de nós) — aí os dois blocos ainda saem na
	 * ordem certa, apenas sem a garantia de contiguidade.
	 *
	 * Posição repetida não se perde: o próprio WordPress desloca a segunda
	 * entrada por uma fração derivada do slug quando a chave já existe.
	 */
	public const POSITION_RIT = 58.0;

	public const POSITION_V3RTECH = 58.5;

	/** Ordem dos blocos na coluna: família RIT primeiro, V3RTECH em seguida. */
	private const FAMILY_RANK = array(
		Family::RIT     => 0,
		Family::V3RTECH => 1,
	);

	/**
	 * @var bool O filtro é pendurado UMA vez por processo (= por cópia
	 * prefixada), como em `NavCapabilityGate::register()`. Outras cópias
	 * penduram o próprio — e isso é inofensivo, porque `reorder()` é
	 * idempotente.
	 */
	private static $hooked = false;

	/**
	 * Publica a entrada no anúncio compartilhado e garante o filtro
	 * pendurado. Chamado por `Navigation::registerMenu()` no momento em que
	 * o plugin declara a entrada — antes de `admin_menu` disparar, portanto
	 * antes de o WordPress montar a coluna.
	 *
	 * No-op fora do WordPress no que depende de hook; o anúncio em si é PHP
	 * puro e acontece sempre, o que torna `reorder()` testável sem WordPress.
	 */
	public static function announce( MenuEntry $entry ): void {
		if ( ! isset( $GLOBALS[ self::GLOBAL_KEY ] ) || ! is_array( $GLOBALS[ self::GLOBAL_KEY ] ) ) {
			$GLOBALS[ self::GLOBAL_KEY ] = array();
		}

		$GLOBALS[ self::GLOBAL_KEY ][ $entry->slug() ] = array(
			'family' => $entry->family(),
			'title'  => $entry->title(),
		);

		self::hook();
	}

	/** A posição pedida no registro, pela família (ver constantes). */
	public static function positionFor( string $family ): float {
		return Family::V3RTECH === $family ? self::POSITION_V3RTECH : self::POSITION_RIT;
	}

	/**
	 * Callback de `custom_menu_order`: liga a reordenação — mas só quando
	 * há entrada nossa anunciada. Sem entrada da casa no site não há nada a
	 * manter contíguo, e ligar a reordenação à toa mudaria o
	 * comportamento do menu de quem não pediu nada.
	 *
	 * Nunca devolve `false` sobre um `true` de outro plugin: quem já ligou
	 * a reordenação tem os próprios motivos, e desligá-la seria mexer no
	 * que não é nosso.
	 *
	 * @param mixed $enabled
	 */
	public static function enableCustomOrder( $enabled = false ): bool {
		if ( true === $enabled ) {
			return true;
		}

		return array() !== self::announcedEntries();
	}

	/**
	 * Callback de `menu_order`: recebe os slugs das entradas de topo na
	 * ordem que o WordPress montou e devolve a ordem final.
	 *
	 * @param mixed $order
	 * @return array<int, string>
	 */
	public static function reorder( $order ): array {
		if ( ! is_array( $order ) ) {
			return array();
		}

		$announced = self::announcedEntries();
		$ours      = array();
		$theirs    = array();
		$anchor    = null;

		foreach ( array_values( $order ) as $index => $slug ) {
			if ( ! is_string( $slug ) ) {
				// Item de forma inesperada: preservado onde está, nunca descartado.
				$theirs[] = array(
					'index' => $index,
					'slug'  => $slug,
				);
				continue;
			}

			if ( array_key_exists( $slug, $announced ) ) {
				if ( null === $anchor ) {
					$anchor = $index;
				}

				$ours[] = $slug;
				continue;
			}

			$theirs[] = array(
				'index' => $index,
				'slug'  => $slug,
			);
		}

		if ( count( $ours ) < 2 ) {
			// Zero ou uma entrada nossa na coluna: não há bloco a formar, e
			// mover a única que existe seria mexer na posição sem motivo.
			return array_values( $order );
		}

		usort(
			$ours,
			static function ( string $a, string $b ) use ( $announced ): int {
				return self::compareEntries( $a, $announced[ $a ], $b, $announced[ $b ] );
			}
		);

		$final = array();

		foreach ( $theirs as $item ) {
			if ( $item['index'] > $anchor && array() !== $ours ) {
				foreach ( $ours as $slug ) {
					$final[] = $slug;
				}

				$ours = array();
			}

			$final[] = $item['slug'];
		}

		foreach ( $ours as $slug ) {
			$final[] = $slug;
		}

		return $final;
	}

	/**
	 * Bloco primeiro (RIT antes de V3RTECH), depois o título em ordem
	 * alfabética — insensível a maiúsculas e a acento, porque "Solidário" e
	 * "Solidario" precisam cair no mesmo lugar. Empate de título é
	 * desempatado pelo slug, para a ordem nunca depender de qual cópia
	 * anunciou primeiro.
	 *
	 * @param string                               $slugA
	 * @param array{family: string, title: string} $dataA
	 * @param string                               $slugB
	 * @param array{family: string, title: string} $dataB
	 */
	private static function compareEntries( string $slugA, array $dataA, string $slugB, array $dataB ): int {
		$rank = self::familyRank( $dataA['family'] ) <=> self::familyRank( $dataB['family'] );

		if ( 0 !== $rank ) {
			return $rank;
		}

		$byTitle = strcmp( self::sortKey( $dataA['title'] ), self::sortKey( $dataB['title'] ) );

		return 0 !== $byTitle ? $byTitle : strcmp( $slugA, $slugB );
	}

	private static function familyRank( string $family ): int {
		return self::FAMILY_RANK[ $family ] ?? count( self::FAMILY_RANK );
	}

	/**
	 * Chave de ordenação do título: sem acento e em caixa baixa, para
	 * `strcmp()` produzir a ordem que uma pessoa chamaria de alfabética
	 * sem depender do `locale` do servidor (que varia por hospedagem, e
	 * faria a mesma família sair em ordens diferentes em dois sites).
	 */
	private static function sortKey( string $title ): string {
		$replacements = array(
			'á' => 'a',
			'à' => 'a',
			'â' => 'a',
			'ã' => 'a',
			'ä' => 'a',
			'é' => 'e',
			'ê' => 'e',
			'è' => 'e',
			'ë' => 'e',
			'í' => 'i',
			'ì' => 'i',
			'î' => 'i',
			'ï' => 'i',
			'ó' => 'o',
			'ò' => 'o',
			'ô' => 'o',
			'õ' => 'o',
			'ö' => 'o',
			'ú' => 'u',
			'ù' => 'u',
			'û' => 'u',
			'ü' => 'u',
			'ç' => 'c',
			'ñ' => 'n',
		);

		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title, 'UTF-8' ) : strtolower( $title );

		return strtr( $lower, $replacements );
	}

	/**
	 * O anúncio compartilhado, já higienizado: só entradas com a forma
	 * esperada e família conhecida sobrevivem (ver docblock da classe).
	 *
	 * @return array<string, array{family: string, title: string}>
	 */
	private static function announcedEntries(): array {
		$raw = $GLOBALS[ self::GLOBAL_KEY ] ?? array();

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$entries = array();

		foreach ( $raw as $slug => $data ) {
			if ( ! is_string( $slug ) || '' === $slug || ! is_array( $data ) ) {
				continue;
			}

			$family = $data['family'] ?? null;
			$title  = $data['title'] ?? null;

			if ( ! is_string( $family ) || ! Family::isValid( $family ) || ! is_string( $title ) || '' === $title ) {
				continue;
			}

			$entries[ $slug ] = array(
				'family' => $family,
				'title'  => $title,
			);
		}

		return $entries;
	}

	private static function hook(): void {
		if ( self::$hooked ) {
			return;
		}

		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'custom_menu_order', array( self::class, 'enableCustomOrder' ) );
		add_filter( 'menu_order', array( self::class, 'reorder' ) );

		self::$hooked = true;
	}

	/**
	 * Zera o estado de processo e o anúncio compartilhado — só para uso em
	 * teste, mesma saída explícita de `NavCapabilityGate::resetForTests()`,
	 * e pelo mesmo motivo: sem ela a suíte fica ordem-dependente.
	 */
	public static function resetForTests(): void {
		self::$hooked                = false;
		$GLOBALS[ self::GLOBAL_KEY ] = array();
	}

	private function __construct() {
		// Classe estática — o anúncio é do processo, não de uma instância.
	}
}
