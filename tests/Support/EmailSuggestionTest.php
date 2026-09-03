<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Support;

use PHPUnit\Framework\TestCase;
use V3R\Core\Support\EmailSuggestion;

final class EmailSuggestionTest extends TestCase {

	private const CASES_FILE = __DIR__ . '/../../src/Assets/data/email-suggestion-cases.json';

	/**
	 * @return array{dominiosPadrao: string[], casos: array<int, array<string, mixed>>}
	 */
	private static function spec(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- arquivo local do próprio repositório, não URL remota.
		$raw = file_get_contents( self::CASES_FILE );
		self::assertIsString( $raw, 'Conjunto de casos compartilhado ilegível.' );

		/** @var array{dominiosPadrao: string[], casos: array<int, array<string, mixed>>} $spec */
		$spec = json_decode( $raw, true );

		return $spec;
	}

	/**
	 * A lista embutida e a do conjunto compartilhado precisam ser a MESMA
	 * — o JS recebe a lista do servidor, e é este teste que garante que os
	 * casos exercitados nas duas metades falam dos mesmos domínios.
	 */
	public function testListaPadraoEhIdenticaADoConjuntoCompartilhado(): void {
		$this->assertSame( self::spec()['dominiosPadrao'], EmailSuggestion::defaultDomains() );
	}

	/**
	 * @dataProvider casosCompartilhados
	 * @param string      $email
	 * @param string[]    $domains
	 * @param string|null $esperado
	 */
	public function testCasoCompartilhado( string $email, array $domains, ?string $esperado ): void {
		$this->assertSame( $esperado, EmailSuggestion::suggest( $email, $domains ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string[], 2: string|null}>
	 */
	public function casosCompartilhados(): array {
		$spec     = self::spec();
		$provided = array();

		foreach ( $spec['casos'] as $caso ) {
			$rotulo              = $caso['grupo'] . ': ' . $caso['nome'];
			$provided[ $rotulo ] = array(
				$caso['email'],
				array_key_exists( 'dominios', $caso ) ? $caso['dominios'] : $spec['dominiosPadrao'],
				$caso['esperado'],
			);
		}

		return $provided;
	}

	/**
	 * Guarda contra o conjunto compartilhado ser esvaziado ou perder o
	 * grupo que mais importa: sugerir correção a quem digitou certo faz a
	 * pessoa "corrigir" para um endereço errado.
	 */
	public function testOConjuntoCompartilhadoCobreOsGruposQueImportam(): void {
		$grupos = array();

		foreach ( self::spec()['casos'] as $caso ) {
			$grupos[ $caso['grupo'] ] = ( $grupos[ $caso['grupo'] ] ?? 0 ) + 1;
		}

		$this->assertGreaterThanOrEqual( 5, $grupos['sugere'] ?? 0 );
		$this->assertGreaterThanOrEqual( 5, $grupos['falso-positivo'] ?? 0 );
		$this->assertGreaterThanOrEqual( 2, $grupos['ja-correto'] ?? 0 );
		$this->assertGreaterThanOrEqual( 3, $grupos['formato'] ?? 0 );
	}

	/**
	 * A calibração pelo comprimento do rótulo separa "errou uma tecla" de
	 * "é outro domínio": num rótulo de três ou quatro letras, duas edições
	 * já mudam o nome inteiro.
	 *
	 * ⚠️ Ela NÃO separa vizinhos de uma edição só (`uol`/`bol`/`aol`/`sol`
	 * distam 1 entre si) — contra esses, a defesa é outra: eles ficam fora
	 * da lista padrão (ver `defaultDomains()`). São duas guardas
	 * diferentes, e confundi-las leva a achar que dá para reintroduzir os
	 * domínios curtos no padrão.
	 */
	public function testRotuloCurtoAdmiteApenasUmaEdicao(): void {
		$curtos = array( 'uol.com.br' );

		// Uma edição: sugere.
		$this->assertSame( 'fulano@uol.com.br', EmailSuggestion::suggest( 'fulano@uol.com.hr', $curtos ) );
		// Duas edições num rótulo de três letras já é outro nome.
		$this->assertNull( EmailSuggestion::suggest( 'fulano@dob.com.br', $curtos ) );
	}

	/**
	 * A guarda que realmente segura os vizinhos de uma edição: eles não
	 * estão na lista padrão. Se alguém os acrescentar, passam a se sugerir
	 * mutuamente — e é isso que este teste documenta.
	 */
	public function testVizinhosDeUmaEdicaoSoSaoSegurosPorqueFicamForaDaListaPadrao(): void {
		$this->assertNull( EmailSuggestion::suggest( 'fulano@sol.com.br', EmailSuggestion::defaultDomains() ) );

		$comCurtos = array_merge( EmailSuggestion::defaultDomains(), array( 'uol.com.br' ) );
		$this->assertSame( 'fulano@uol.com.br', EmailSuggestion::suggest( 'fulano@sol.com.br', $comCurtos ) );
	}

	public function testRotuloLongoAdmiteDuasEdicoes(): void {
		$longos = array( 'globomail.com' );

		$this->assertSame( 'fulano@globomail.com', EmailSuggestion::suggest( 'fulano@glomobail.com', $longos ) );
	}

	/**
	 * A peça sugere e não decide: o retorno é sempre a sugestão ou null,
	 * nunca um sinal de recusa que o chamador possa confundir com "este
	 * e-mail é inválido".
	 */
	public function testONucleoNuncaDevolveNadaAlemDeSugestaoOuNulo(): void {
		foreach ( self::spec()['casos'] as $caso ) {
			$domains = array_key_exists( 'dominios', $caso ) ? $caso['dominios'] : self::spec()['dominiosPadrao'];
			$result  = EmailSuggestion::suggest( $caso['email'], $domains );

			if ( null !== $result ) {
				$this->assertStringContainsString( '@', $result );
				$this->assertNotSame( $caso['email'], $result, 'Sugerir exatamente o que foi digitado é ruído.' );
			} else {
				$this->assertNull( $result );
			}
		}
	}
}
