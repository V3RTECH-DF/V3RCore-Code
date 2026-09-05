<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Signing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use V3R\Core\Signing\CertificateInspector;
use V3R\Core\Signing\CertificateMaterial;
use V3R\Core\Signing\CertificateSubject;
use V3R\Core\Tests\Signing\Support\CertificateFactory;

/**
 * Chamadas diretas de filesystem (file_put_contents(), unlink(), mkdir(),
 * rmdir()) neste arquivo são chamadas de teste, sem WordPress carregado —
 * mesma justificativa de EphemeralSecretFileTest.
 */
final class CertificateInspectorTest extends TestCase {

	/** @var string */
	private $dir;

	/** @var CertificateInspector */
	private $inspector;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'v3r-core-inspector-test-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $this->dir );

		$this->inspector = new CertificateInspector();
	}

	protected function tearDown(): void {
		$arquivosRestantes = glob( $this->dir . DIRECTORY_SEPARATOR . '*' );

		foreach ( false === $arquivosRestantes ? array() : $arquivosRestantes as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $file );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $this->dir );

		parent::tearDown();
	}

	private function materialFor( string $pkcs12Contents, string $password ): CertificateMaterial {
		$path = $this->dir . DIRECTORY_SEPARATOR . 'cert-' . bin2hex( random_bytes( 4 ) ) . '.p12';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, $pkcs12Contents );

		return new CertificateMaterial( $path, $password );
	}

	public function testSenhaCorretaAbreOCertificado(): void {
		$pkcs12 = CertificateFactory::selfSigned( array( 'commonName' => 'Empresa Teste' ), 'senha-correta' );

		$inspection = $this->inspector->inspect( $this->materialFor( $pkcs12, 'senha-correta' ) );

		$this->assertTrue( $inspection->isOk() );
		$this->assertNull( $inspection->error() );
	}

	public function testSenhaErradaFalha(): void {
		$pkcs12 = CertificateFactory::selfSigned( array( 'commonName' => 'Empresa Teste' ), 'senha-correta' );

		$inspection = $this->inspector->inspect( $this->materialFor( $pkcs12, 'senha-errada' ) );

		$this->assertFalse( $inspection->isOk() );
		$this->assertNull( $inspection->expiresAt() );
		$this->assertNotNull( $inspection->error() );
	}

	public function testSenhaVaziaFalhaSemAbrirNada(): void {
		$pkcs12 = CertificateFactory::selfSigned( array( 'commonName' => 'Empresa Teste' ), 'senha-correta' );

		$inspection = $this->inspector->inspect( $this->materialFor( $pkcs12, '' ) );

		$this->assertFalse( $inspection->isOk() );
	}

	public function testConteudoQueNaoEPkcs12Falha(): void {
		$inspection = $this->inspector->inspect( $this->materialFor( CertificateFactory::garbage(), 'qualquer' ) );

		$this->assertFalse( $inspection->isOk() );
		$this->assertNull( $inspection->expiresAt() );
	}

	public function testArquivoInexistenteFalha(): void {
		$material = new CertificateMaterial( $this->dir . DIRECTORY_SEPARATOR . 'nao-existe.p12', 'qualquer' );

		$inspection = $this->inspector->inspect( $material );

		$this->assertFalse( $inspection->isOk() );
	}

	public function testValidadeFuturaEExtraidaCorretamente(): void {
		$pkcs12 = CertificateFactory::selfSigned( array( 'commonName' => 'Empresa Teste' ), 'senha' );

		$inspection = $this->inspector->inspect( $this->materialFor( $pkcs12, 'senha' ) );

		$this->assertTrue( $inspection->isOk() );
		$this->assertInstanceOf( DateTimeImmutable::class, $inspection->expiresAt() );
		$this->assertGreaterThan( new DateTimeImmutable( '+300 days' ), $inspection->expiresAt() );
	}

	/**
	 * $days = 0: a validade nasce igual ao instante de geração do
	 * certificado — no momento em que o teste compara contra "agora", já
	 * é passado ou, no mínimo, igual (fronteira que SigningModeResolver
	 * trata como vencido).
	 */
	public function testValidadeVencidaEExtraidaComoDataPassada(): void {
		$pkcs12 = CertificateFactory::selfSignedWithZeroDaysValidity( array( 'commonName' => 'Empresa Vencida' ), 'senha' );

		$inspection = $this->inspector->inspect( $this->materialFor( $pkcs12, 'senha' ) );

		$this->assertTrue( $inspection->isOk() );
		$this->assertLessThanOrEqual( new DateTimeImmutable(), $inspection->expiresAt() );
	}

	public function testDocumentoNoNomeComumFormatoNomeDoisPontosDocumento(): void {
		$pkcs12 = CertificateFactory::selfSigned( array( 'commonName' => 'RIT SOLUCOES:12345678000195' ), 'senha' );

		$subject = $this->inspector->inspect( $this->materialFor( $pkcs12, 'senha' ) )->subject();

		$this->assertNotNull( $subject );
		$this->assertSame( 'RIT SOLUCOES', $subject->name() );
		$this->assertSame( CertificateSubject::DOCUMENT_CNPJ, $subject->documentType() );
		$this->assertSame( '12345678000195', $subject->documentDigits() );
	}

	public function testDocumentoDoSerialNumberQuandoNomeComumNaoTraz(): void {
		$pkcs12 = CertificateFactory::selfSigned(
			array(
				'commonName' => 'Maria da Silva',
				'serialNumber' => '52998224725',
			),
			'senha'
		);

		$subject = $this->inspector->inspect( $this->materialFor( $pkcs12, 'senha' ) )->subject();

		$this->assertNotNull( $subject );
		$this->assertSame( CertificateSubject::DOCUMENT_CPF, $subject->documentType() );
		$this->assertSame( '52998224725', $subject->documentDigits() );
	}

	public function testDocumentoDoOrganizationIdentifierQuandoNomeComumNaoTraz(): void {
		$pkcs12 = CertificateFactory::selfSigned(
			array(
				'commonName' => 'RIT Solucoes',
				'organizationIdentifier' => '12345678000195',
			),
			'senha'
		);

		$subject = $this->inspector->inspect( $this->materialFor( $pkcs12, 'senha' ) )->subject();

		$this->assertNotNull( $subject );
		$this->assertSame( CertificateSubject::DOCUMENT_CNPJ, $subject->documentType() );
		$this->assertSame( '12345678000195', $subject->documentDigits() );
	}

	public function testCertificadoSemDocumentoReconhecivelNaoInventaNada(): void {
		$pkcs12 = CertificateFactory::selfSigned( array( 'commonName' => 'Empresa Sem Documento' ), 'senha' );

		$subject = $this->inspector->inspect( $this->materialFor( $pkcs12, 'senha' ) )->subject();

		$this->assertNotNull( $subject );
		$this->assertSame( 'Empresa Sem Documento', $subject->name() );
		$this->assertNull( $subject->documentType() );
		$this->assertNull( $subject->documentDigits() );
		$this->assertNull( $subject->maskedDocument() );
	}

	/**
	 * V3RCore-Code#29, decisão 2: `subjectAltName` NÃO é usada para achar
	 * documento. O certificado traz uma sequência de 11 dígitos ali que
	 * NÃO é o CPF do titular (é, de propósito, um valor bem diferente do
	 * que qualquer outro campo do certificado carrega) — se o inspetor a
	 * lesse, o teste pegaria o document errado; ele deve continuar `null`.
	 */
	public function testSubjectAltNameNuncaEUsadaParaExtrairDocumento(): void {
		$pkcs12 = CertificateFactory::selfSignedWithSubjectAltName(
			array( 'commonName' => 'Empresa Sem Documento No CN' ),
			'99988877766',
			'senha'
		);

		$subject = $this->inspector->inspect( $this->materialFor( $pkcs12, 'senha' ) )->subject();

		$this->assertNotNull( $subject );
		$this->assertNull( $subject->documentType() );
		$this->assertNull( $subject->documentDigits() );
	}

	public function testAutoassinadoNaoEAtestado(): void {
		$pkcs12 = CertificateFactory::selfSigned( array( 'commonName' => 'RIT:12345678000195' ), 'senha' );

		$subject = $this->inspector->inspect( $this->materialFor( $pkcs12, 'senha' ) )->subject();

		$this->assertNotNull( $subject );
		$this->assertFalse( $subject->isAttested() );
	}

	public function testAssinadoPorOutraAcEAtestado(): void {
		$pkcs12 = CertificateFactory::issuedByCa(
			array( 'commonName' => 'RIT:12345678000195' ),
			array( 'commonName' => 'AC de Teste' ),
			'senha'
		);

		$subject = $this->inspector->inspect( $this->materialFor( $pkcs12, 'senha' ) )->subject();

		$this->assertNotNull( $subject );
		$this->assertTrue( $subject->isAttested() );
		$this->assertSame( 'AC de Teste', $subject->issuer() );
	}

	public function testCnpjSaiFormatadoEInteiroNaExibicao(): void {
		$pkcs12 = CertificateFactory::selfSigned( array( 'commonName' => 'RIT:12345678000195' ), 'senha' );

		$subject = $this->inspector->inspect( $this->materialFor( $pkcs12, 'senha' ) )->subject();

		$this->assertNotNull( $subject );
		$this->assertSame( '12.345.678/0001-95', $subject->maskedDocument() );
	}

	public function testCpfSaiMascaradoNaExibicao(): void {
		$pkcs12 = CertificateFactory::selfSigned( array( 'commonName' => 'Maria da Silva:52998224725' ), 'senha' );

		$subject = $this->inspector->inspect( $this->materialFor( $pkcs12, 'senha' ) )->subject();

		$this->assertNotNull( $subject );
		$this->assertSame( '***.982.247-**', $subject->maskedDocument() );
	}
}
