<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Documents;

use PHPUnit\Framework\TestCase;
use V3R\Core\Documents\Cpf;

final class CpfTest extends TestCase {

	/**
	 * @dataProvider valores
	 */
	public function testValidade( string $entrada, bool $esperado, string $porque ): void {
		$this->assertSame( $esperado, Cpf::isValid( $entrada ), $porque );
	}

	/**
	 * @return array<string, array{0: string, 1: bool, 2: string}>
	 */
	public function valores(): array {
		return array(
			'com máscara'             => array( '529.982.247-25', true, '' ),
			'sem máscara'             => array( '52998224725', true, '' ),
			'com espaços'             => array( ' 52998224725 ', true, 'espaço em volta é ruído de digitação' ),
			'outro válido'            => array( '111.444.777-35', true, '' ),
			'primeiro DV errado'      => array( '529.982.247-15', false, '' ),
			'segundo DV errado'       => array( '529.982.247-24', false, '' ),
			'todos zeros'             => array( '00000000000', false, 'passa no módulo 11 por acidente aritmético' ),
			'todos uns'               => array( '11111111111', false, 'idem, e é o placeholder mais digitado' ),
			'todos noves'             => array( '99999999999', false, '' ),
			'curto demais'            => array( '5299822472', false, '' ),
			'longo demais'            => array( '529982247250', false, '' ),
			'vazio'                   => array( '', false, '' ),
			'só máscara'              => array( '.-', false, '' ),
			'com letra'               => array( '5299822472A', false, 'CPF não tem formato alfanumérico — a mudança da Receita é só da pessoa jurídica' ),
			// Comportamento herdado das quatro cópias, mantido de propósito:
			// a normalização REMOVE o que não é dígito, em vez de recusar a
			// entrada. É o que faz a máscara funcionar, e o preço é aceitar
			// caractere grudado. Recusar exigiria decidir qual pontuação é
			// máscara legítima — decisão de produto, não da biblioteca.
			'com letra grudada'       => array( '52998224725A', true, 'a letra sai na normalização e sobram 11 dígitos válidos' ),
			'dígitos de largura total' => array( '５２９９８２２４７２５', false, '' ),
		);
	}

	/**
	 * A borda do módulo 11: resto 0 **ou 1** produz dígito 0. Sem o caso de
	 * resto 1, `resto < 2` e `resto === 0` são indistinguíveis — e a versão
	 * errada recusa calada um CPF legítimo.
	 *
	 * @dataProvider bordasDoModulo11
	 */
	public function testBordaDoModulo11( string $valido ): void {
		$this->assertTrue( Cpf::isValid( $valido ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function bordasDoModulo11(): array {
		return array(
			'1º DV com resto 0' => array( '98983478802' ),
			'1º DV com resto 1' => array( '02426462809' ),
			'2º DV com resto 0' => array( '58770658900' ),
			'2º DV com resto 1' => array( '61675136190' ),
		);
	}

	public function testNormalizeMantemSoDigitos(): void {
		$this->assertSame( '52998224725', Cpf::normalize( '529.982.247-25' ) );
		$this->assertSame( '', Cpf::normalize( 'abc' ) );
		$this->assertSame( '', Cpf::normalize( '' ) );
	}

	public function testFormatAplicaAMascara(): void {
		$this->assertSame( '529.982.247-25', Cpf::format( '52998224725' ) );
		$this->assertSame( '529.982.247-25', Cpf::format( '529.982.247-25' ) );
	}

	/**
	 * Divergência resolvida na promoção: a cópia do RIT360 Solidário
	 * devolvia a ENTRADA CRUA quando não havia 11 dígitos; aqui devolve o
	 * normalizado, como todas as demais e como `Cnpj`.
	 */
	public function testFormatDeEntradaIncompletaDevolveONormalizadoENaoOCru(): void {
		$this->assertSame( '529', Cpf::format( '529.' ) );
		$this->assertSame( '', Cpf::format( '<script>' ) );
		$this->assertSame( '', Cpf::format( 'não informado' ) );
	}

	public function testTrocarUmDigitoQualquerInvalida(): void {
		$valido = '52998224725';

		for ( $posicao = 0; $posicao < 11; $posicao++ ) {
			$mutante             = $valido;
			$mutante[ $posicao ] = (string) ( ( (int) $valido[ $posicao ] + 1 ) % 10 );

			$this->assertFalse( Cpf::isValid( $mutante ), "trocar a posição {$posicao} deveria invalidar: {$mutante}" );
		}
	}

	/**
	 * As duas peças precisam falar o mesmo dialeto: divergir entre elas
	 * dentro da biblioteca reintroduziria, em escala menor, a diferença que
	 * a promoção veio eliminar.
	 */
	public function testAPIEspelhaADoCnpj(): void {
		$metodos = array( 'normalize', 'isValid', 'format' );

		foreach ( $metodos as $metodo ) {
			$this->assertTrue( method_exists( Cpf::class, $metodo ), "Cpf::{$metodo}()" );
			$this->assertTrue( method_exists( \V3R\Core\Documents\Cnpj::class, $metodo ), "Cnpj::{$metodo}()" );
		}
	}
}
