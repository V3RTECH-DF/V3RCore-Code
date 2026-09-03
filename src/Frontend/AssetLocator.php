<?php
declare(strict_types=1);

namespace V3R\Core\Frontend;

/**
 * Convenção de ativo de front-end da biblioteca (ADR-014, V3RCore-Code#23).
 *
 * A v3r-core é biblioteca PHP embutida por Strauss no plugin hospedeiro;
 * algumas peças, porém, só entregam valor com uma metade no navegador — a
 * sugestão de domínio de e-mail aparece enquanto a pessoa digita, ou não
 * serve para nada. Esta classe é o único caminho por onde um ativo estático
 * da biblioteca vira URL enfileirável.
 *
 * As quatro decisões que ela materializa:
 *
 * 1. **Os ativos moram DENTRO de `src/`** (`src/Assets/`), e não numa pasta
 *    irmã. Não é organização: o Strauss copia para `vendor-prefixed/` o que
 *    está sob o caminho do autoload PSR-4 do pacote — inclusive arquivos que
 *    não são PHP — e ignora o resto. Ativo fora de `src/` simplesmente não
 *    chega ao plugin empacotado. (Verificado executando o Strauss com um
 *    pacote de teste, não por suposição.)
 * 2. **A biblioteca não conhece a própria URL**, porque ela depende de onde
 *    o hospedeiro a instalou. A URL é derivada do caminho real do arquivo
 *    via `plugins_url()`; um hospedeiro fora de `wp-content/plugins`
 *    (mu-plugin, tema) informa a base explicitamente no construtor.
 * 3. **A versão do ativo é a data de modificação do arquivo**, não a versão
 *    do plugin: a versão do plugin identifica a release, não o pacote
 *    gerado, e já produziu na casa cache servindo o arquivo anterior.
 * 4. **Nada é enfileirado sozinho.** A classe não registra hook nenhum;
 *    quem quer o ativo chama `enqueueScript()`. Plugin que não quer o front
 *    não carrega nada — a distribuição da biblioteca é opt-in por desenho.
 */
final class AssetLocator {

	/** @var string|null */
	private $baseUrl;

	/** @var string */
	private $baseDir;

	/**
	 * $baseUrl só é necessário quando a biblioteca não está sob
	 * `wp-content/plugins` (mu-plugin, tema) — nos demais casos a URL é
	 * derivada do caminho do próprio arquivo.
	 */
	public function __construct( ?string $baseUrl = null, ?string $baseDir = null ) {
		$this->baseUrl = null === $baseUrl ? null : rtrim( $baseUrl, '/' );
		$this->baseDir = rtrim( $baseDir ?? dirname( __DIR__ ) . '/Assets', '/' );
	}

	/**
	 * @param string $relativePath Caminho relativo a `src/Assets/` (ex.: `js/email-suggestion.js`).
	 */
	public function path( string $relativePath ): string {
		return $this->baseDir . '/' . ltrim( $relativePath, '/' );
	}

	public function url( string $relativePath ): string {
		$relativePath = ltrim( $relativePath, '/' );

		if ( null !== $this->baseUrl ) {
			return $this->baseUrl . '/' . $relativePath;
		}

		return plugins_url( basename( $relativePath ), $this->path( $relativePath ) );
	}

	/**
	 * Data de modificação do arquivo, como cache-buster. Devolve `null`
	 * quando o arquivo não existe — o chamador repassa a `wp_enqueue_script`,
	 * que trata `null` como "sem versão"; inventar uma versão fixa aqui
	 * seria pior, porque congelaria o cache num arquivo que mudou.
	 */
	public function version( string $relativePath ): ?string {
		$path = $this->path( $relativePath );

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$modifiedAt = filemtime( $path );

		return false === $modifiedAt ? null : (string) $modifiedAt;
	}

	/**
	 * Enfileira um script da biblioteca. Opt-in: só acontece porque o
	 * hospedeiro chamou.
	 *
	 * @param string   $handle
	 * @param string   $relativePath
	 * @param string[] $dependencies Handles dos quais este script depende.
	 * @param bool     $inFooter
	 */
	public function enqueueScript( string $handle, string $relativePath, array $dependencies = array(), bool $inFooter = true ): void {
		wp_enqueue_script(
			$handle,
			$this->url( $relativePath ),
			$dependencies,
			$this->version( $relativePath ),
			$inFooter
		);
	}
}
