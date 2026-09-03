<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Access;

use PHPUnit\Framework\TestCase;
use V3R\Core\Access\AccessToken;

final class AccessTokenTest extends TestCase {

	public function testGenerateProduzTextoPuroBase64UrlDe32Bytes(): void {
		$token = AccessToken::generate();

		// 32 bytes em base64 sem padding = 43 caracteres.
		$this->assertSame( 43, strlen( $token->plaintext() ) );
		$this->assertMatchesRegularExpression( '/\A[A-Za-z0-9_-]+\z/', $token->plaintext() );
	}

	public function testHashEhSha256DoTextoPuroENuncaOProprioTextoPuro(): void {
		$token = AccessToken::generate();

		$this->assertSame( hash( 'sha256', $token->plaintext() ), $token->hash() );
		$this->assertNotSame( $token->plaintext(), $token->hash() );
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $token->hash() );
	}

	public function testDoisTokensGeradosNaoColidem(): void {
		$primeiro = AccessToken::generate();
		$segundo  = AccessToken::generate();

		$this->assertNotSame( $primeiro->plaintext(), $segundo->plaintext() );
		$this->assertNotSame( $primeiro->hash(), $segundo->hash() );
	}

	public function testFromPlaintextReconstroiOMesmoHash(): void {
		$emitido      = AccessToken::generate();
		$reconstruido = AccessToken::fromPlaintext( $emitido->plaintext() );

		$this->assertSame( $emitido->hash(), $reconstruido->hash() );
		$this->assertTrue( $reconstruido->matches( $emitido->hash() ) );
	}

	public function testMatchesRecusaHashDeOutroToken(): void {
		$token = AccessToken::fromPlaintext( 'abc' );

		$this->assertFalse( $token->matches( AccessToken::generate()->hash() ) );
		$this->assertFalse( $token->matches( '' ) );
		$this->assertFalse( $token->matches( 'qualquer coisa' ) );
	}

	public function testTextoPuroVazioEhRecusado(): void {
		$this->expectException( \InvalidArgumentException::class );

		AccessToken::fromPlaintext( '' );
	}
}
