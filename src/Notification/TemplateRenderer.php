<?php
declare(strict_types=1);

namespace V3R\Core\Notification;

/**
 * Monta texto a partir de um modelo com marcadores `{{marcador}}` — para que
 * o conteúdo venha de texto editável pelo consumidor (ex.: tela de
 * configuração do plugin hospedeiro) e mudar uma frase não exija alteração
 * de código.
 *
 * Puro: não conhece de onde o modelo veio (banco, option, arquivo) nem para
 * onde o texto vai (e-mail, tela). Marcador sem valor correspondente em
 * $placeholders permanece literal no texto — nunca lança exceção nem apaga
 * silenciosamente, porque um texto com o marcador visível é mais fácil de
 * diagnosticar (e menos perigoso, num aviso já enviado) do que um buraco no
 * meio da frase.
 */
final class TemplateRenderer {

	/**
	 * @param string                $template
	 * @param array<string, string> $placeholders Nomes SEM as chaves duplas
	 *   (ex.: 'nome', não '{{nome}}').
	 */
	public function render( string $template, array $placeholders ): string {
		$search  = array();
		$replace = array();

		foreach ( $placeholders as $name => $value ) {
			$search[]  = '{{' . $name . '}}';
			$replace[] = $value;
		}

		return str_replace( $search, $replace, $template );
	}

	/**
	 * Marcadores ainda presentes no template que $placeholders não cobre —
	 * para o consumidor decidir (avisar na tela de configuração, por
	 * exemplo) sem precisar reimplementar a extração.
	 *
	 * @param string                $template
	 * @param array<string, string> $placeholders
	 * @return list<string> Nomes dos marcadores, sem as chaves duplas, sem duplicatas.
	 */
	public function missingPlaceholders( string $template, array $placeholders ): array {
		if ( 0 === preg_match_all( '/\{\{([a-zA-Z0-9_]+)\}\}/', $template, $matches ) ) {
			return array();
		}

		$found   = array_unique( $matches[1] );
		$missing = array_values( array_diff( $found, array_keys( $placeholders ) ) );

		return $missing;
	}
}
