<?php
declare(strict_types=1);

namespace V3R\Core\Support;

/**
 * Resolve a versão de um plugin a partir do cabeçalho `Version:` do seu
 * arquivo principal — o cabeçalho é a fonte da verdade; a constante que o
 * plugin expõe (ex.: `MEUPLUGIN_VERSION`) é derivada dele em tempo de boot.
 *
 * Movida da implementação original em V3RLGPD (`Core\PluginVersion`,
 * issue #72 daquele repositório) para a v3r-core — v3rtech-scripts#32 — para
 * que outros plugins da casa deixem de manter cópia hardcoded sem fonte
 * comum. A cópia local do V3RLGPD foi removida em favor desta.
 *
 * Guarda: a versão é lida no boot do plugin. Se a leitura falhar, vier
 * vazia ou o arquivo/ambiente se comportar de forma inesperada, o método
 * NUNCA deixa uma exceção escapar nem devolve string vazia — cai para
 * `$fallback`. Uma exceção aqui derrubaria o carregamento do plugin
 * inteiro; string vazia quebraria em silêncio o cache-busting de todo
 * asset enfileirado com a constante.
 */
class PluginVersion {

	/**
	 * @param string $file     Caminho absoluto do arquivo com o cabeçalho de plugin (normalmente __FILE__ do bootstrap).
	 * @param string $fallback Valor a usar caso a leitura do cabeçalho falhe, venha vazia ou malformada.
	 */
	public static function resolve( string $file, string $fallback ): string {
		try {
			if ( is_readable( $file ) && function_exists( 'get_file_data' ) ) {
				$data    = get_file_data( $file, array( 'Version' => 'Version' ) );
				$version = $data['Version'] ?? '';
				if ( '' !== $version ) {
					return $version;
				}
			}
		} catch ( \Throwable $e ) {
			// Leitura do cabeçalho não pode derrubar o boot do plugin — cai para o fallback abaixo.
			unset( $e );
		}

		return $fallback;
	}
}
