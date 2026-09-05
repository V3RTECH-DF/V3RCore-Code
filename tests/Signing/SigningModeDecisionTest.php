<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Signing;

use PHPUnit\Framework\TestCase;
use V3R\Core\Signing\SigningMode;
use V3R\Core\Signing\SigningModeDecision;
use V3R\Core\Signing\SigningModeReason;

final class SigningModeDecisionTest extends TestCase {

	public function testRecusaModoDesconhecido(): void {
		$this->expectException( \InvalidArgumentException::class );

		new SigningModeDecision( 'modo_inventado', SigningModeReason::CERTIFICADO_VALIDO );
	}

	public function testRecusaMotivoDesconhecido(): void {
		$this->expectException( \InvalidArgumentException::class );

		new SigningModeDecision( SigningMode::CERTIFICADO_DIGITAL, 'motivo_inventado' );
	}

	public function testIsDegradedSoEhVerdadeiroParaRegistroEletronico(): void {
		$degradada = new SigningModeDecision( SigningMode::REGISTRO_ELETRONICO, SigningModeReason::SEM_CERTIFICADO );
		$plena     = new SigningModeDecision( SigningMode::CERTIFICADO_DIGITAL, SigningModeReason::CERTIFICADO_VALIDO );

		$this->assertTrue( $degradada->isDegraded() );
		$this->assertFalse( $plena->isDegraded() );
	}
}
