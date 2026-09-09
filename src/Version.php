<?php
declare(strict_types=1);

namespace V3R\Core;

/**
 * A versão publicada desta biblioteca (v3r-core) — não do plugin hospedeiro.
 *
 * Escrita por `bin/bump-version.sh` no momento da publicação, via o ponto
 * declarado em `bin/config.sh` (`VERSION_POINTS`); nunca mantida à mão fora
 * dele — constante desalinhada da tag mentiria com autoridade, que é pior
 * que não existir (V3RCore-Code#44).
 *
 * É uma CONSTANTE DE CLASSE, não um `define()` global, de propósito: dois
 * plugins da casa embutindo versões diferentes desta biblioteca, cada um
 * prefixado pelo próprio Strauss, viram `Prefixo\Vendor\V3R\Core\Version`
 * distintos — um `define()` de nome fixo colidiria entre eles no mesmo
 * WordPress, porque a prefixação por namespace não alcança constantes
 * globais.
 *
 * Existe para que o hospedeiro, e a receita de empacotamento
 * (`v3r-release`), possam perguntar em tempo de execução — ou inspecionando
 * o pacote montado — QUAL versão da biblioteca foi de fato embutida. O guard
 * de prefixação já confere que ela chegou; isto é o que falta para conferir
 * qual chegou.
 */
final class Version {

	/**
	 * Versão semântica publicada, escrita pelo bump. Nunca vazia.
	 */
	public const CURRENT = '0.22.0';
}
