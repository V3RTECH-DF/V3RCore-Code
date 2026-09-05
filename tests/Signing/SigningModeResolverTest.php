<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Signing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use V3R\Core\Signing\SigningMode;
use V3R\Core\Signing\SigningModeReason;
use V3R\Core\Signing\SigningModeResolver;

final class SigningModeResolverTest extends TestCase {

	private function now(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-09-04T12:00:00+00:00' );
	}

	public function testSemArquivoDeCertificadoDegrada(): void {
		$decisao = SigningModeResolver::decide( false, null, $this->now() );

		$this->assertSame( SigningMode::REGISTRO_ELETRONICO, $decisao->mode() );
		$this->assertSame( SigningModeReason::SEM_CERTIFICADO, $decisao->reason() );
		$this->assertTrue( $decisao->isDegraded() );
	}

	public function testComArquivoMasSemValidadeConhecidaDegrada(): void {
		$decisao = SigningModeResolver::decide( true, null, $this->now() );

		$this->assertSame( SigningMode::REGISTRO_ELETRONICO, $decisao->mode() );
		$this->assertSame( SigningModeReason::SEM_VALIDADE_CONHECIDA, $decisao->reason() );
	}

	public function testComArquivoEValidadeJaPassadaDegrada(): void {
		$vencido = $this->now()->modify( '-1 day' );

		$decisao = SigningModeResolver::decide( true, $vencido, $this->now() );

		$this->assertSame( SigningMode::REGISTRO_ELETRONICO, $decisao->mode() );
		$this->assertSame( SigningModeReason::CERTIFICADO_VENCIDO, $decisao->reason() );
	}

	public function testValidadeExatamenteAgoraContaComoVencida(): void {
		// Fronteira: "a validade é futura" — igual a agora não é futuro.
		$decisao = SigningModeResolver::decide( true, $this->now(), $this->now() );

		$this->assertSame( SigningMode::REGISTRO_ELETRONICO, $decisao->mode() );
		$this->assertSame( SigningModeReason::CERTIFICADO_VENCIDO, $decisao->reason() );
	}

	public function testComArquivoEValidadeFuturaUsaCertificadoDigital(): void {
		$futuro = $this->now()->modify( '+1 day' );

		$decisao = SigningModeResolver::decide( true, $futuro, $this->now() );

		$this->assertSame( SigningMode::CERTIFICADO_DIGITAL, $decisao->mode() );
		$this->assertSame( SigningModeReason::CERTIFICADO_VALIDO, $decisao->reason() );
		$this->assertFalse( $decisao->isDegraded() );
	}
}
