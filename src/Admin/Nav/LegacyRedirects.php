<?php
declare(strict_types=1);

namespace V3R\Core\Admin\Nav;

/**
 * Redireciona o endereço de um submenu antigo para a entrada única
 * (docs/navegacao-do-painel.md, §"Substituir submenus antigos"). Adotar a
 * camada de navegação troca vários submenus por um só; os endereços
 * antigos deixam de existir, e quem os tem salvos — exatamente quem usa a
 * tela todo dia — passa a receber página em branco, sem erro nenhum. Esta
 * classe fecha esse buraco.
 *
 * **O mapa é do plugin.** `slug antigo => destino` não tem como ser
 * derivado automaticamente (medido no V3RLGPD: `v3rlgpd-atendimento` vira
 * `/atendimento`, `v3rlgpd-docs` vira `/manual`, e a entrada raiz não vira
 * nada) — quem declara é sempre o plugin. Esta classe cuida só do resto:
 * o laço de redirecionamento, a higienização, o momento certo do ciclo do
 * WordPress, a composição do destino e a preservação dos parâmetros.
 *
 * **Destino em três formas, todas detectáveis pelo próprio valor:**
 * - começando com `/` ou `#` — rota interna, composta como fragmento sobre
 *   o endereço da entrada única do plugin (`?page=<entrySlug>#<rota>`),
 *   **preservando os demais parâmetros da requisição**;
 * - com esquema (`://`) — URL absoluta, usada exatamente como está, sem
 *   composição nem parâmetro nenhum acrescentado (ela pode apontar para
 *   fora do site; misturar parâmetro do WordPress ali não faz sentido);
 * - qualquer outro valor **sem `/` nem `.`** — slug de outra página do
 *   próprio painel, composta como `?page=<slug>`, **também preservando os
 *   demais parâmetros da requisição** (mesma regra da rota interna: quem
 *   perde é sempre o link profundo salvo por quem usa a tela todo dia).
 *
 * ⚠️ **Só a URL absoluta não preserva parâmetro.** Ler "preserva os demais
 * parâmetros" como comportamento da classe inteira é o erro que já
 * aconteceu — é comportamento de dois dos três ramos, não de todos.
 *
 * ⚠️ **Recusa na declaração, não no redirecionamento.** Um mapa que
 * contenha o slug da própria entrada única cria um laço infinito — o
 * painel trava, não só a tela. A checagem acontece no construtor, chamado
 * no boot do plugin (nunca dentro de `admin_menu`), então falhar aqui é
 * seguro: `InvalidArgumentException` na inicialização é visível na hora,
 * bem diferente do painel travado que ela evita.
 *
 * **Preserva os demais parâmetros da requisição** — na rota interna e no
 * slug de outra página do painel, não na URL absoluta. `page=v3rlgpd-ropa
 * &id=5` precisa levar o `id` para o destino composto, seja ele o
 * fragmento de uma rota interna ou o `page` de outra tela do próprio
 * painel; perder contexto de link profundo em silêncio é o mesmo tipo de
 * defeito que esta peça existe para fechar. A URL absoluta não recebe
 * parâmetro nenhum, de propósito: "usada como está" é literal — ela pode
 * apontar para fora do plugin (ou fora do site), e misturar parâmetros do
 * WordPress ali não faz sentido.
 *
 * ⚠️ **Slug de página do painel é reconhecido, não adivinhado.** Um valor
 * que pareça caminho ou domínio sem esquema (contém `/`, ou contém `.` —
 * cara de domínio digitado sem `https://`) é recusado no construtor, pelo
 * mesmo motivo do laço: falhar no boot é visível na hora; adivinhar
 * errado falha em silêncio no dia em que alguém colar um link.
 *
 * **Não decide permissão.** Ela só redireciona; quem barra é o destino —
 * a guarda de rota do cliente ou a camada 1/2 desta mesma biblioteca
 * (§4), que já vale para toda rota, inclusive as que nunca tiveram
 * endereço de submenu. Repetir a decisão de permissão aqui criaria uma
 * segunda fonte de verdade sem fechar buraco nenhum. Consequência aceita:
 * quem não pode ver a tela é levado até ela, e recebe a recusa **dentro
 * do produto** — mensagem melhor que o erro genérico do WordPress.
 *
 * **Hook e momento:** `admin_init`, não `admin_menu`. É o que a doc pede
 * ("agir na hora certa do ciclo, antes de qualquer saída") — o WordPress
 * decide se a página existe DEPOIS de `admin_init` disparar; agir antes
 * evita que o hospedeiro chegue a desenhar (ou não desenhar) qualquer
 * coisa para o endereço antigo.
 *
 * **Testabilidade do redirecionamento real:** `maybeRedirect()` precisa
 * interromper a requisição depois de mandar o cabeçalho — em produção,
 * `exit`. Em vez de chamar `exit` direto (intestável sem matar o processo
 * do PHPUnit), o encerramento é uma dependência (`$terminate`), com um
 * `exit` de verdade como padrão. Teste passa um substituto que registra a
 * chamada, sem precisar terminar o processo.
 */
final class LegacyRedirects {

	/** @var string */
	private $ownEntrySlug;

	/** @var array<string, string> */
	private $map;

	/** @var callable */
	private $terminate;

	/**
	 * @param string                $ownEntrySlug O slug da entrada única deste plugin
	 *                                            (o mesmo de `MenuEntry::slug()`) — usado
	 *                                            para compor rota interna e para recusar
	 *                                            o laço.
	 * @param array<string, string> $map          `slug antigo => destino`. Destino
	 *                                            começando com `/` ou `#` é composto
	 *                                            como rota interna; destino com esquema
	 *                                            (`://`) é usado como URL absoluta;
	 *                                            qualquer outro valor é tratado como slug
	 *                                            de outra página do próprio painel — e é
	 *                                            recusado no construtor se tiver `/` ou
	 *                                            `.` (cara de caminho ou de domínio sem
	 *                                            esquema).
	 * @param callable|null         $terminate    `function(): void`, chamado depois do
	 *                                            redirecionamento. Padrão: `exit`. Ponto
	 *                                            de substituição só para teste.
	 *
	 * @throws \InvalidArgumentException `ownEntrySlug` vazio, uma chave/destino vazio no
	 *                                    mapa, o mapa contendo o slug da própria entrada
	 *                                    única (laço de redirecionamento), ou um destino
	 *                                    ambíguo (nem rota interna, nem URL absoluta, mas
	 *                                    com `/` ou `.` no valor).
	 */
	public function __construct( string $ownEntrySlug, array $map, ?callable $terminate = null ) {
		if ( '' === trim( $ownEntrySlug ) ) {
			throw new \InvalidArgumentException( 'LegacyRedirects::ownEntrySlug não pode ser vazio.' );
		}

		foreach ( $map as $oldSlug => $destination ) {
			if ( ! is_string( $oldSlug ) || '' === trim( $oldSlug ) ) {
				throw new \InvalidArgumentException( 'LegacyRedirects: slug antigo não pode ser vazio.' );
			}

			if ( ! is_string( $destination ) || '' === trim( $destination ) ) {
				throw new \InvalidArgumentException( "LegacyRedirects: destino de '{$oldSlug}' não pode ser vazio." );
			}

			if ( $oldSlug === $ownEntrySlug ) {
				throw new \InvalidArgumentException(
					"LegacyRedirects: o mapa contém a entrada '{$oldSlug}', que é o slug da própria " .
					'entrada única do plugin. Mapear o slug único para si mesmo cria um laço de ' .
					'redirecionamento infinito no painel (docs/navegacao-do-painel.md).'
				);
			}

			if ( self::isAmbiguousPageSlug( $destination ) ) {
				throw new \InvalidArgumentException(
					"LegacyRedirects: o destino '{$destination}' (de '{$oldSlug}') não começa com " .
					"'/' nem '#', e não tem esquema ('://') para ser tratado como URL absoluta — " .
					"mas contém '/' ou '.', o que dá cara de caminho ou de domínio digitado sem " .
					'esquema. Um slug de página do painel não tem essa forma, então este destino ' .
					'não é aceito como tal: corrija o destino, ou anteceda-o de "https://" se a ' .
					'intenção era mesmo uma URL absoluta.'
				);
			}
		}

		$this->ownEntrySlug = $ownEntrySlug;
		$this->map          = $map;
		$this->terminate    = $terminate ?? static function (): void {
			exit;
		};
	}

	/**
	 * Prende `maybeRedirect()` ao hook `admin_init`. `maybeRedirect()`
	 * continua público e chamável direto por teste, sem depender do hook
	 * disparar (mesmo padrão de `Navigation::renderMenu()`).
	 *
	 * No-op fora do WordPress.
	 */
	public function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_init', array( $this, 'maybeRedirect' ) );
	}

	/**
	 * Chamado pelo hook `admin_init`. Sem `$_GET['page']`, ou com um
	 * `page` que não está no mapa — inclusive o de qualquer outro
	 * plugin — não faz nada: **não intercepta requisição que não é
	 * deste plugin.**
	 */
	public function maybeRedirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só leitura de navegação (redireciona um GET para outro), nada muda de estado.
		if ( ! isset( $_GET['page'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- higienizado abaixo, antes de qualquer uso.
		$requestedSlug = $this->sanitizeSlug( wp_unslash( $_GET['page'] ) );

		$target = $this->targetFor( $requestedSlug );

		if ( null === $target ) {
			return;
		}

		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $target );
		}

		( $this->terminate )();
	}

	/**
	 * A decisão pura, sem efeito colateral nenhum — separada de
	 * `maybeRedirect()` para o teste provar a composição do destino sem
	 * precisar simular o hook nem o encerramento da requisição.
	 *
	 * @return string|null O destino, ou `null` quando `$requestedSlug` não
	 *                      está no mapa (nunca intercepta o que não é
	 *                      deste plugin).
	 */
	public function targetFor( string $requestedSlug ): ?string {
		if ( ! array_key_exists( $requestedSlug, $this->map ) ) {
			return null;
		}

		$destination = $this->map[ $requestedSlug ];

		if ( self::isInternalRoute( $destination ) ) {
			return $this->composeInternalRoute( $destination );
		}

		if ( self::isAbsoluteUrl( $destination ) ) {
			// URL absoluta: usada como está — nenhuma composição, nenhum
			// parâmetro acrescentado (docblock da classe).
			return $destination;
		}

		// Slug de outra página do próprio painel: valor já validado no
		// construtor (nem rota interna, nem URL absoluta, nem ambíguo).
		return $this->composePageRoute( $destination );
	}

	private static function isInternalRoute( string $destination ): bool {
		return 0 === strpos( $destination, '/' ) || 0 === strpos( $destination, '#' );
	}

	/**
	 * Esquema explícito (`://`) é o único sinal usado para reconhecer URL
	 * absoluta — nunca um domínio "adivinhado" pela presença de `.`, que é
	 * exatamente o que a guarda de ambiguidade do construtor recusa.
	 */
	private static function isAbsoluteUrl( string $destination ): bool {
		return false !== strpos( $destination, '://' );
	}

	/**
	 * Um valor que não é rota interna nem URL absoluta só é aceito como
	 * slug de página do painel se não tiver cara de caminho (`/`) nem de
	 * domínio digitado sem esquema (`.`) — docblock da classe e do
	 * construtor.
	 */
	private static function isAmbiguousPageSlug( string $destination ): bool {
		if ( self::isInternalRoute( $destination ) || self::isAbsoluteUrl( $destination ) ) {
			return false;
		}

		return false !== strpos( $destination, '/' ) || false !== strpos( $destination, '.' );
	}

	/**
	 * `?page=<entrySlug>` + os demais parâmetros da requisição original
	 * (tudo menos `page`, higienizado) + `#<rota>` como fragmento. Usa
	 * `admin_url()` — nunca um caminho `/wp-admin/` escrito à mão — para
	 * funcionar também com o WordPress instalado em subdiretório.
	 */
	private function composeInternalRoute( string $route ): string {
		$base  = $this->adminBaseUrl();
		$query = $this->queryWithPreservedParams( array( 'page' => $this->ownEntrySlug ) );

		$fragment = '#' . ltrim( $route, '#' );

		return $base . '?' . http_build_query( $query ) . $fragment;
	}

	/**
	 * `?page=<slug>` + os demais parâmetros da requisição original (tudo
	 * menos `page`, higienizado) — mesma preservação da rota interna, sem
	 * fragmento, porque o destino já É a página do painel.
	 */
	private function composePageRoute( string $slug ): string {
		$base  = $this->adminBaseUrl();
		$query = $this->queryWithPreservedParams( array( 'page' => $this->sanitizeSlug( $slug ) ) );

		return $base . '?' . http_build_query( $query );
	}

	private function adminBaseUrl(): string {
		return function_exists( 'admin_url' )
			? admin_url( 'admin.php' )
			: 'admin.php';
	}

	/**
	 * `$seed` (tipicamente `array( 'page' => ... )`) + os demais parâmetros
	 * de `$_GET` da requisição original, exceto `page` — o parâmetro do
	 * endereço ANTIGO nunca vaza para o destino composto.
	 *
	 * @param array<string, string> $seed
	 *
	 * @return array<string, string>
	 */
	private function queryWithPreservedParams( array $seed ): array {
		$query = $seed;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só leitura de navegação, cada valor é higienizado abaixo antes de compor o destino.
		foreach ( $_GET as $param => $value ) {
			if ( 'page' === $param ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- higienizado a seguir.
			$query[ $this->sanitizeSlug( (string) $param ) ] = $this->sanitizeSlug( (string) wp_unslash( $value ) );
		}

		return $query;
	}

	/**
	 * Higienização única desta classe: um parâmetro de navegação (slug de
	 * página, nome/valor de query string), nunca conteúdo rico. Mesmo
	 * utilitário do WordPress que `Licensing\AdminPage` já usa para o
	 * mesmo tipo de dado.
	 *
	 * @param mixed $value
	 */
	private function sanitizeSlug( $value ): string {
		$value = (string) $value;

		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( $value );
	}
}
