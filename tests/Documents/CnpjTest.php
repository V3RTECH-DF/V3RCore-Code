<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Documents;

use PHPUnit\Framework\TestCase;
use V3R\Core\Documents\Cnpj;

final class CnpjTest extends TestCase {

	/**
	 * Vetores do documento oficial da Receita Federal (perguntas 14 e 17):
	 * o alfanumérico `12.ABC.345/01DE-35`, com o cálculo demonstrado passo
	 * a passo, e o numérico `12.345.678/0001-95`. São a única fonte externa
	 * desta suíte — os demais casos foram conferidos contra uma segunda
	 * implementação, escrita à parte a partir do mesmo documento.
	 */
	public function testVetoresOficiaisDaReceita(): void {
		$this->assertTrue( Cnpj::isValid( '12.ABC.345/01DE-35' ) );
		$this->assertTrue( Cnpj::isValid( '12.345.678/0001-95' ) );
	}

	/**
	 * @dataProvider valores
	 */
	public function testValidade( string $entrada, bool $esperado, string $porque ): void {
		$this->assertSame( $esperado, Cnpj::isValid( $entrada ), $porque );
	}

	/**
	 * @return array<string, array{0: string, 1: bool, 2: string}>
	 */
	public function valores(): array {
		return array(
			'alfanumérico com máscara'      => array( '12.ABC.345/01DE-35', true, 'vetor oficial' ),
			'alfanumérico sem máscara'      => array( '12ABC34501DE35', true, 'máscara é exibição, não conteúdo' ),
			'alfanumérico em minúsculas'    => array( '12abc34501de35', true, 'letra minúscula digitada é normalizada, não recusada' ),
			'alfanumérico com espaços'      => array( '  12ABC34501DE35  ', true, 'espaço em volta é ruído de digitação' ),
			'numérico com máscara'          => array( '11.222.333/0001-81', true, '' ),
			'numérico sem máscara'          => array( '11222333000181', true, '' ),
			'só letras nas 12 primeiras'    => array( 'ZZZZZZZZZZZZ62', true, 'as 12 posições podem ser todas letras' ),
			'letras iguais com DV correto'  => array( 'AAAAAAAAAAAA45', true, 'não é placeholder: os verificadores são numéricos, então os 14 nunca são iguais' ),
			'primeiro DV errado'            => array( '12.ABC.345/01DE-45', false, '' ),
			'segundo DV errado'             => array( '12.ABC.345/01DE-34', false, '' ),
			'DV alfabético'                 => array( '12ABC34501DEAB', false, 'os dois verificadores continuam numéricos' ),
			'todos os dígitos iguais'       => array( '11111111111111', false, 'placeholder histórico' ),
			'todos zeros'                   => array( '00000000000000', false, 'passa no módulo 11 por acidente aritmético' ),
			'curto demais'                  => array( '1234567800019', false, '' ),
			'longo demais'                  => array( '123456780001955', false, '' ),
			'vazio'                         => array( '', false, 'campo não preenchido não é documento inválido, mas também não é válido' ),
			'só máscara'                    => array( '../-', false, '' ),
			'com caractere fora do alfabeto' => array( '12.345.678/0001-9#', false, 'o # é removido, sobram 13 caracteres' ),
			'dígitos fora do ASCII'         => array( '１２３４５６７８０００１９５', false, 'dígito de largura total não é [0-9]' ),
		);
	}

	/**
	 * A borda do módulo 11: quando o resto é 0 **ou 1**, o dígito é 0. Sem
	 * o caso de resto 1 nenhum teste distingue `resto < 2` de `resto === 0`
	 * — a versão errada calcula 10, que nunca casa com um dígito, e recusa
	 * calada um documento legítimo. Vetores localizados por busca, um para
	 * cada verificador.
	 *
	 * @dataProvider bordasDoModulo11
	 */
	public function testBordaDoModulo11( string $valido ): void {
		$this->assertTrue( Cnpj::isValid( $valido ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function bordasDoModulo11(): array {
		return array(
			'1º DV com resto 0' => array( 'ZB6CN6Z43DVY04' ),
			'1º DV com resto 1' => array( 'RKTTNJFBF5JX05' ),
			'2º DV com resto 0' => array( 'PP6UP3C4DSA710' ),
			'2º DV com resto 1' => array( '7OCUBRL5PTP540' ),
		);
	}

	public function testNormalizeRemoveMascaraEEspacoESobeACaixa(): void {
		$this->assertSame( '12ABC34501DE35', Cnpj::normalize( ' 12.abc.345/01de-35 ' ) );
		$this->assertSame( '', Cnpj::normalize( '' ) );
		$this->assertSame( '', Cnpj::normalize( './-' ) );
	}

	public function testFormatAplicaAMascara(): void {
		$this->assertSame( '12.ABC.345/01DE-35', Cnpj::format( '12abc34501de35' ) );
		$this->assertSame( '11.222.333/0001-81', Cnpj::format( '11222333000181' ) );
	}

	/**
	 * Entrada incompleta devolve o normalizado, nunca o texto cru:
	 * formatar é operação de exibição, e devolver o cru jogaria de volta na
	 * tela exatamente o que a normalização existe para tirar.
	 */
	public function testFormatDeEntradaIncompletaDevolveONormalizadoENaoOCru(): void {
		$this->assertSame( '123', Cnpj::format( '12 3' ) );
		$this->assertSame( '12ABC', Cnpj::format( ' 12.abc ' ) );

		// Letra é caractere legítimo de CNPJ, então sobra o miolo — mas os
		// sinais que abrem marcação não sobrevivem à normalização.
		$this->assertSame( 'SCRIPT', Cnpj::format( '<script>' ) );
		$this->assertStringNotContainsString( '<', Cnpj::format( '<script>' ) );
	}

	/**
	 * O numérico é caso particular do alfanumérico — uma implementação só.
	 * Este teste falharia se alguém separasse os dois caminhos e deixasse
	 * um deles para trás, que é exatamente o risco que motivou a promoção.
	 */
	public function testAMesmaImplementacaoValidaOsDoisFormatos(): void {
		$numericos     = array( '11222333000181', '12345678000195' );
		$alfanumericos = array( '12ABC34501DE35', 'ZZZZZZZZZZZZ62' );

		foreach ( array_merge( $numericos, $alfanumericos ) as $valor ) {
			$this->assertTrue( Cnpj::isValid( $valor ), "deveria ser válido: {$valor}" );
		}
	}

	/**
	 * Qualquer caractere trocado invalida — a guarda de que os pesos e o
	 * módulo estão sendo aplicados de verdade, e não que a função devolve
	 * `true` para o que tem o formato certo.
	 */
	public function testTrocarUmCaractereQualquerInvalida(): void {
		$valido   = '12ABC34501DE35';
		$alfabeto = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

		for ( $posicao = 0; $posicao < 14; $posicao++ ) {
			$mutante = $valido;
			// Troca pelo caractere seguinte do alfabeto permitido NAQUELA
			// posição, para o formato continuar válido e a recusa vir do
			// dígito verificador, não do regex.
			$permitidos = $posicao >= 12 ? '0123456789' : $alfabeto;
			$indice     = strpos( $permitidos, $valido[ $posicao ] );

			$this->assertIsInt( $indice );

			$mutante[ $posicao ] = $permitidos[ ( $indice + 1 ) % strlen( $permitidos ) ];

			$this->assertFalse( Cnpj::isValid( $mutante ), "trocar a posição {$posicao} deveria invalidar: {$mutante}" );
		}
	}
}
