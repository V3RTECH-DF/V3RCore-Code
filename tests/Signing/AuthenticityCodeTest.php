<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Signing;

use PHPUnit\Framework\TestCase;
use V3R\Core\Signing\AuthenticityCode;

final class AuthenticityCodeTest extends TestCase {

	public function testGeraNoFormatoComQuatroGruposDeQuatro(): void {
		$code = AuthenticityCode::generate();

		$this->assertMatchesRegularExpression(
			'/\A[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-' .
				'[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}\z/',
			$code->value()
		);
	}

	public function testAlfabetoNuncaContemCaracteresAmbiguos(): void {
		$ambiguos = array( '0', '1', 'I', 'L', 'O' );

		// Gera muitas vezes para dar chance real de os ambíguos aparecerem
		// se estivessem no alfabeto — não é prova formal, mas o teste
		// falharia rapidamente se alguém reintroduzisse um deles.
		for ( $i = 0; $i < 200; $i++ ) {
			$value = AuthenticityCode::generate()->value();

			foreach ( $ambiguos as $caractere ) {
				$this->assertStringNotContainsString( $caractere, $value );
			}
		}
	}

	public function testDoisCodigosGeradosNaoColidem(): void {
		$primeiro = AuthenticityCode::generate();
		$segundo  = AuthenticityCode::generate();

		$this->assertNotSame( $primeiro->value(), $segundo->value() );
	}

	public function testFromStringToleraEspacosHifensEMinusculas(): void {
		$gerado        = AuthenticityCode::generate();
		$semFormatacao = strtolower( str_replace( '-', ' ', $gerado->value() ) );

		$reconstruido = AuthenticityCode::fromString( $semFormatacao );

		$this->assertSame( $gerado->value(), $reconstruido->value() );
	}

	public function testFromStringRecusaComprimentoErrado(): void {
		$this->expectException( \InvalidArgumentException::class );

		AuthenticityCode::fromString( 'ABCD-ABCD' );
	}

	/**
	 * Controle negativo do teste anterior: comprimento CERTO não deve ser
	 * recusado por esse motivo — prova que a exceção acima é por
	 * comprimento, não por outra coisa qualquer.
	 */
	public function testFromStringAceitaComprimentoCertoComAlfabetoValido(): void {
		$reconstruido = AuthenticityCode::fromString( 'ABCD-2345-6789-CDEF' );

		$this->assertSame( 'ABCD-2345-6789-CDEF', $reconstruido->value() );
	}

	public function testFromStringRecusaCaractereAmbiguoForaDoAlfabeto(): void {
		$this->expectException( \InvalidArgumentException::class );

		// '0', '1', 'I', 'L', 'O' nunca fazem parte do alfabeto — um código
		// digitado com eles precisa ser rejeitado, nunca "corrigido" para
		// outro código válido.
		AuthenticityCode::fromString( 'O000-1111-IIII-LLLL' );
	}
}
