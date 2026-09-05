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
				// 'emitted_at' e 'file_hash' ausentes de propósito.
			)
		);
	}
}
