<?php
declare(strict_types=1);

namespace V3R\Core\Support;

/**
 * Sugestão de correção para erro de digitação no DOMÍNIO de um e-mail
 * (`fulano@gmail.con` → `fulano@gmail.com`). Promovida do V3REvent
 * (V3REvent-Code#157, v1.76.0) para a biblioteca em V3RCore-Code#23: o
 * problema é o mesmo em todo produto da casa que tem formulário com campo
 * de e-mail — endereço digitado errado é aceito pelo servidor de e-mail,
 * marcado como enviado, e rejeitado depois, sem o plugin saber.
 *
 * ⚠️ A REGRA INEGOCIÁVEL: sugere, NUNCA bloqueia. `suggest()` devolve uma
 * correção provável ou `null`, e nada mais — quem decide seguir com o valor
 * digitado é sempre a pessoa que preenche o formulário, e o chamador nunca
 * troca o valor sozinho. Validação agressiva rejeita endereço legítimo
 * (domínio próprio, extensão nova) e impede o cadastro: erro muito pior que
 * o que se corrige.
 *
 * Classe pura — sem WordPress, sem estado. `defaultDomains()` devolve a
 * lista embutida; quem instala estende a lista no próprio hospedeiro (a
 * biblioteca não conhece hook de produto), e `suggest()` recebe a lista já
 * resolvida.
 *
 * O mesmo algoritmo é espelhado em `src/Assets/js/email-suggestion.js`, que
 * é o que faz a sugestão aparecer enquanto a pessoa digita. As duas metades
 * são exercitadas pelo MESMO conjunto de casos
 * (`src/Assets/data/email-suggestion-cases.json`) — é o que impede
 * navegador e servidor de descolarem.
 */
final class EmailSuggestion {

	/**
	 * Domínios comuns no Brasil reconhecidos como "provavelmente era isto
	 * que a pessoa quis digitar".
	 *
	 * Domínios de rótulo curto (`uol`, `bol`, `aol`) ficam DE FORA do
	 * padrão de propósito: são parecidos entre si e com domínios próprios
	 * legítimos (`sol.com.br`), e o falso positivo aqui leva alguém a
	 * "corrigir" um endereço que estava certo. Quem precisar deles
	 * acrescenta na integração.
	 *
	 * @return string[]
	 */
	public static function defaultDomains(): array {
		return array(
			'gmail.com',
			'hotmail.com',
			'hotmail.com.br',
			'outlook.com',
			'outlook.com.br',
			'yahoo.com',
			'yahoo.com.br',
			'live.com',
			'live.com.br',
			'icloud.com',
			'terra.com.br',
			'globo.com',
			'globomail.com',
		);
	}

	/**
	 * Dado um e-mail digitado e a lista de domínios reconhecidos (já
	 * resolvida — ver `defaultDomains()`), devolve o e-mail com o domínio
	 * corrigido quando há candidato próximo o bastante para valer a
	 * sugestão, ou `null` quando não há nada a sugerir.
	 *
	 * Nunca sugere quando o domínio digitado já é EXATAMENTE um dos
	 * conhecidos, por mais perto que esteja de outro da lista.
	 *
	 * @param string   $email
	 * @param string[] $knownDomains Lista já resolvida (ver `defaultDomains()`).
	 */
	public static function suggest( string $email, array $knownDomains ): ?string {
		$email = trim( $email );
		$at    = strrpos( $email, '@' );

		if ( false === $at ) {
			return null;
		}

		$local  = substr( $email, 0, $at );
		$domain = strtolower( trim( substr( $email, $at + 1 ) ) );

		if ( '' === $local || '' === $domain || false === strpos( $domain, '.' ) ) {
			return null;
		}

		$best         = null;
		$bestDistance = null;

		foreach ( $knownDomains as $knownDomain ) {
			$known = strtolower( trim( (string) $knownDomain ) );

			if ( '' === $known ) {
				continue;
			}

			if ( $domain === $known ) {
				return null;
			}

			$distance = levenshtein( $domain, $known );

			if ( $distance > 0
				&& $distance <= self::thresholdFor( $known )
				&& ( null === $bestDistance || $distance < $bestDistance )
			) {
				$best         = $known;
				$bestDistance = $distance;
			}
		}

		return null === $best ? null : $local . '@' . $best;
	}

	/**
	 * Distância máxima aceita para considerar "perto o bastante" de
	 * `$known`, calibrada pelo comprimento do RÓTULO (a parte antes do
	 * primeiro ponto — "gmail" em "gmail.com").
	 *
	 * Rótulo curto (≤4) admite só 1 edição: duas edições num nome de três
	 * ou quatro letras já é outro nome, não erro de digitação. ⚠️ Ela não
	 * separa vizinhos que distam 1 (`uol`/`bol`/`aol`/`sol`) — contra
	 * esses, a defesa é a exclusão da lista padrão, guarda diferente e
	 * independente desta. Rótulo mais longo
	 * admite 2, o suficiente para pegar transposição de letras adjacentes
	 * (`gmial`→`gmail`, `hotmial`→`hotmail`).
	 */
	private static function thresholdFor( string $known ): int {
		$label = strtok( $known, '.' );

		return strlen( false === $label ? '' : $label ) <= 4 ? 1 : 2;
	}
}
