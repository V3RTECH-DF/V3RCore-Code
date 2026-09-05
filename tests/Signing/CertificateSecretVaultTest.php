<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Signing;

use PHPUnit\Framework\TestCase;
use V3R\Core\Signing\CertificateSecretVault;
use V3R\Core\Signing\CertificateVaultException;
use V3R\Core\Tests\Licensing\Storage\InMemoryKeyValueStore;

final class CertificateSecretVaultTest extends TestCase {

	private function validKeyBase64(): string {
		return base64_encode( str_repeat( 'k', 32 ) );
	}

	protected function setUp(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'sodium indisponível neste ambiente de teste.' );
		}
	}

	public function testSemChaveConfiguradaOCofreDeclaraIndisponivelENuncaGravaEmClaro(): void {
		$store = new InMemoryKeyValueStore();
		$vault = new CertificateSecretVault( $store, 'produto', null );

		$this->assertFalse( $vault->isAvailable() );

		$this->expectException( CertificateVaultException::class );

		try {
			$vault->storePassword( 'segredo-do-certificado' );
		} finally {
			// Controle negativo do "nunca grava em texto claro": nada foi
			// persistido no armazenamento subjacente.
			$this->assertNull( $store->get( 'produto_signing_cert_password' ) );
		}
	}

	public function testChaveConfiguradaComFormatoInvalidoTambemDeixaOCofreIndisponivel(): void {
		$vault = new CertificateSecretVault( new InMemoryKeyValueStore(), 'produto', 'isto-nao-e-base64-de-32-bytes' );

		$this->assertFalse( $vault->isAvailable() );
	}

	public function testDecodeCipherKeyEhPuraERecusaComprimentoErrado(): void {
		$this->assertNull( CertificateSecretVault::decodeCipherKey( null ) );
		$this->assertNull( CertificateSecretVault::decodeCipherKey( '' ) );
		$this->assertNull( CertificateSecretVault::decodeCipherKey( base64_encode( 'curto-demais' ) ) );

		$chaveValida = base64_encode( str_repeat( 'a', 32 ) );
		$this->assertSame( str_repeat( 'a', 32 ), CertificateSecretVault::decodeCipherKey( $chaveValida ) );
	}

	public function testComChaveValidaGravaEDecifraDeVolta(): void {
		$vault = new CertificateSecretVault( new InMemoryKeyValueStore(), 'produto', $this->validKeyBase64() );

		$this->assertTrue( $vault->isAvailable() );

		$vault->storePassword( 'senha-secreta-do-certificado' );

		$this->assertSame( 'senha-secreta-do-certificado', $vault->retrievePassword() );
	}

	public function testASenhaNuncaFicaEmTextoClaroNoArmazenamentoSubjacente(): void {
		$store = new InMemoryKeyValueStore();
		$vault = new CertificateSecretVault( $store, 'produto', $this->validKeyBase64() );

		$vault->storePassword( 'senha-em-claro-nunca-deveria-aparecer-crua' );

		$gravado = $store->get( 'produto_signing_cert_password' );

		$this->assertIsArray( $gravado );
		$serializado = json_encode( $gravado );
		$this->assertStringNotContainsString( 'senha-em-claro-nunca-deveria-aparecer-crua', (string) $serializado );
	}

	public function testRetrieveSemSenhaGuardadaLancaSenhaNaoEncontrada(): void {
		$vault = new CertificateSecretVault( new InMemoryKeyValueStore(), 'produto', $this->validKeyBase64() );

		$this->expectException( CertificateVaultException::class );
		$this->expectExceptionMessageMatches( '/nenhuma senha/i' );

		$vault->retrievePassword();
	}

	public function testChaveDeCifragemErradaFalhaAoDecifrarEmVezDeDevolverLixo(): void {
		$store = new InMemoryKeyValueStore();

		$vaultOriginal = new CertificateSecretVault( $store, 'produto', $this->validKeyBase64() );
		$vaultOriginal->storePassword( 'senha-original' );

		$outraChave         = base64_encode( str_repeat( 'z', 32 ) );
		$vaultComOutraChave = new CertificateSecretVault( $store, 'produto', $outraChave );

		$this->expectException( CertificateVaultException::class );

		try {
			$vaultComOutraChave->retrievePassword();
		} catch ( CertificateVaultException $e ) {
			$this->assertSame( CertificateVaultException::FALHA_AO_DECIFRAR, $e->getErrorCode() );
			throw $e;
		}
	}

	public function testClearRemoveASenhaGuardada(): void {
		$vault = new CertificateSecretVault( new InMemoryKeyValueStore(), 'produto', $this->validKeyBase64() );

		$vault->storePassword( 'senha' );
		$vault->clear();

		$this->expectException( CertificateVaultException::class );
		$vault->retrievePassword();
	}

	public function testFromConstantSemConstanteDefinidaDeixaOCofreIndisponivel(): void {
		$vault = CertificateSecretVault::fromConstant(
			new InMemoryKeyValueStore(),
			'produto',
			'V3R_CORE_TEST_CONSTANTE_QUE_NAO_EXISTE'
		);

		$this->assertFalse( $vault->isAvailable() );
	}

	public function testFromConstantComConstanteDefinidaFicaDisponivel(): void {
		if ( ! defined( 'V3R_CORE_TEST_SIGNING_KEY' ) ) {
			define( 'V3R_CORE_TEST_SIGNING_KEY', $this->validKeyBase64() );
		}

		$vault = CertificateSecretVault::fromConstant(
			new InMemoryKeyValueStore(),
			'produto',
			'V3R_CORE_TEST_SIGNING_KEY'
		);

		$this->assertTrue( $vault->isAvailable() );
	}
}
