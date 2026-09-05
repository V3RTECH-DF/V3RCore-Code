<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Signing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use V3R\Core\Signing\AuthenticityRecord;
use V3R\Core\Signing\SigningMode;

final class AuthenticityRecordTest extends TestCase {

	public function testToArrayEFromArrayFazemRoundTrip(): void {
		$original = new AuthenticityRecord(
			'ABCD-2345-6789-CDEF',
			SigningMode::CERTIFICADO_DIGITAL,
			new DateTimeImmutable( '2026-09-04T12:00:00+00:00' ),
			str_repeat( 'a', 64 )
		);

		$reconstruido = AuthenticityRecord::fromArray( $original->toArray() );

		$this->assertSame( $original->code(), $reconstruido->code() );
		$this->assertSame( $original->mode(), $reconstruido->mode() );
		$this->assertSame( $original->fileHash(), $reconstruido->fileHash() );
		$this->assertSame( $original->emittedAt()->getTimestamp(), $reconstruido->emittedAt()->getTimestamp() );
	}

	public function testConstrutorRecusaModoDesconhecido(): void {
		$this->expectException( \InvalidArgumentException::class );

		new AuthenticityRecord( 'ABCD-2345-6789-CDEF', 'modo_que_nao_existe', new DateTimeImmutable(), 'hash' );
	}

	public function testFromArrayRecusaCampoAusente(): void {
		$this->expectException( \InvalidArgumentException::class );

		AuthenticityRecord::fromArray(
			array(
				'code' => 'ABCD-2345-6789-CDEF',
				'mode' => SigningMode::CERTIFICADO_DIGITAL,
				// 'emitted_at' ausente de propósito ('file_hash' já é opcional, issue #28).
			)
		);
	}

	public function testFromArraySobreRegistroAntigoComResumoContinuaLendoExatamenteComoAntes(): void {
		// issue #28: 'file_hash' passou a ser opcional no formato
		// persistido, mas registro gravado ANTES da #28 sempre o tem — a
		// compatibilidade do que já foi gravado é requisito.
		$antigo = array(
			'code'       => 'ABCD-2345-6789-CDEF',
			'mode'       => SigningMode::CERTIFICADO_DIGITAL,
			'emitted_at' => '2026-09-04T12:00:00+00:00',
			'file_hash'  => str_repeat( 'a', 64 ),
		);

		$record = AuthenticityRecord::fromArray( $antigo );

		$this->assertSame( 'ABCD-2345-6789-CDEF', $record->code() );
		$this->assertTrue( $record->isSealed() );
		$this->assertSame( str_repeat( 'a', 64 ), $record->fileHash() );
	}

	public function testFromArraySobreRegistroNovoSemResumoDevolveRegistroNaoSelado(): void {
		// Registro emitido e ainda não selado (issue #28): 'file_hash'
		// simplesmente não existe no array persistido.
		$novo = array(
			'code'       => 'ABCD-2345-6789-CDEF',
			'mode'       => SigningMode::CERTIFICADO_DIGITAL,
			'emitted_at' => '2026-09-04T12:00:00+00:00',
		);

		$record = AuthenticityRecord::fromArray( $novo );

		$this->assertFalse( $record->isSealed() );
		$this->assertNull( $record->fileHash() );
	}

	public function testToArrayOmiteFileHashQuandoRegistroNaoEstaSelado(): void {
		$record = new AuthenticityRecord(
			'ABCD-2345-6789-CDEF',
			SigningMode::CERTIFICADO_DIGITAL,
			new DateTimeImmutable( '2026-09-04T12:00:00+00:00' )
		);

		$this->assertArrayNotHasKey( 'file_hash', $record->toArray() );
	}

	public function testSealedWithDevolveNovoRegistroComOMesmoCodigoModoEDataDeEmissao(): void {
		$emitido = new AuthenticityRecord(
			'ABCD-2345-6789-CDEF',
			SigningMode::CERTIFICADO_DIGITAL,
			new DateTimeImmutable( '2026-09-04T12:00:00+00:00' )
		);

		$selado = $emitido->sealedWith( str_repeat( 'b', 64 ) );

		$this->assertNotSame( $emitido, $selado );
		$this->assertFalse( $emitido->isSealed() );
		$this->assertTrue( $selado->isSealed() );
		$this->assertSame( $emitido->code(), $selado->code() );
		$this->assertSame( $emitido->mode(), $selado->mode() );
		$this->assertSame( $emitido->emittedAt()->getTimestamp(), $selado->emittedAt()->getTimestamp() );
		$this->assertSame( str_repeat( 'b', 64 ), $selado->fileHash() );
	}
}
