<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Access;

use PHPUnit\Framework\TestCase;
use V3R\Core\Access\AttemptLimiter;
use V3R\Core\Tests\Access\Support\CountingKeyValueStore;
use V3R\Core\Tests\Licensing\Storage\InMemoryKeyValueStore;

final class AttemptLimiterTest extends TestCase {

	/** @var int */
	private $agora = 1000;

	private function store(): InMemoryKeyValueStore {
		return new InMemoryKeyValueStore(
			function (): int {
				return $this->agora;
			}
		);
	}

	public function testPermiteAteOTetoERecusaDepois(): void {
		$limiter = new AttemptLimiter( $this->store(), 'v3r_test', 900, 3 );

		$this->assertTrue( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );
		$this->assertTrue( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );
		$this->assertTrue( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );
		$this->assertFalse( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );
	}

	/**
	 * O detalhe que o componente existe para preservar: a tentativa
	 * RECUSADA também conta. Se o incremento voltasse para dentro do `if`
	 * de checagem, a origem pararia de acumular assim que o e-mail
	 * estourasse — e a diferença de comportamento entre um e-mail que
	 * consome cota e outro que não consome seria o oráculo de existência.
	 */
	public function testTentativaRecusadaTambemIncrementaAOutraChave(): void {
		$limiter = new AttemptLimiter( $this->store(), 'v3r_test', 900, 1 );

		// Esgota a cota do e-mail A vindo da origem X.
		$this->assertTrue( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );

		// A insiste de uma origem NOVA: a tentativa é recusada pelo e-mail,
		// e é a recusa — e só ela — que faz a origem Y contar 1.
		$this->assertFalse( $limiter->registerAttempt( 'a@example.org', '10.0.0.2' ) );

		// Um e-mail nunca visto, vindo da origem Y, precisa vir recusado.
		// Se o incremento estivesse dentro do `if` de checagem, a origem Y
		// ainda estaria zerada e esta tentativa passaria — e a diferença
		// entre consumir e não consumir cota viraria oráculo de existência.
		$this->assertFalse( $limiter->registerAttempt( 'nunca-visto@example.org', '10.0.0.2' ) );
	}

	/**
	 * Mesmo rastro no armazenamento nas duas situações: um `return`
	 * antecipado que pulasse o incremento não mudaria o valor final dos
	 * contadores, mas mudaria o número de escritas — e o tempo de resposta.
	 */
	public function testTentativaPermitidaERecusadaEscrevemAMesmaCoisa(): void {
		$store   = new CountingKeyValueStore( $this->store() );
		$limiter = new AttemptLimiter( $store, 'v3r_test', 900, 1 );

		$store->resetCounters();
		$this->assertTrue( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );
		$escritasPermitida = $store->writes;
		$leiturasPermitida = $store->reads;

		$store->resetCounters();
		$this->assertFalse( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );

		$this->assertSame( $escritasPermitida, $store->writes );
		$this->assertSame( $leiturasPermitida, $store->reads );
		$this->assertCount( 2, array_unique( $store->writtenKeys ) );
	}

	public function testNenhumaChaveCarregaOIdentificadorEmClaro(): void {
		$store   = new CountingKeyValueStore( $this->store() );
		$limiter = new AttemptLimiter( $store, 'v3r_test', 900, 3 );

		$limiter->registerAttempt( 'pessoa@example.org', '10.0.0.1' );

		foreach ( $store->writtenKeys as $key ) {
			$this->assertStringNotContainsString( 'pessoa@example.org', $key );
			$this->assertStringNotContainsString( '10.0.0.1', $key );
		}
	}

	public function testIdentificadorEhNormalizadoAntesDeContar(): void {
		$limiter = new AttemptLimiter( $this->store(), 'v3r_test', 900, 2 );

		$this->assertTrue( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );
		$this->assertTrue( $limiter->registerAttempt( '  A@Example.ORG ', '10.0.0.2' ) );
		// Terceira tentativa do MESMO e-mail, de uma terceira origem: se a
		// caixa/espaço criassem contadores distintos, o teto não seguraria.
		$this->assertFalse( $limiter->registerAttempt( 'A@EXAMPLE.ORG', '10.0.0.3' ) );
	}

	public function testAsDuasChavesSaoIndependentes(): void {
		$limiter = new AttemptLimiter( $this->store(), 'v3r_test', 900, 2 );

		$limiter->registerAttempt( 'a@example.org', '10.0.0.1' );
		$limiter->registerAttempt( 'a@example.org', '10.0.0.1' );

		// Outro e-mail, outra origem: cota intacta.
		$this->assertTrue( $limiter->registerAttempt( 'b@example.org', '10.0.0.9' ) );
	}

	public function testPrefixoIsolaConsumidoresDistintos(): void {
		$store       = $this->store();
		$umPlugin    = new AttemptLimiter( $store, 'v3r_um', 900, 1 );
		$outroPlugin = new AttemptLimiter( $store, 'v3r_outro', 900, 1 );

		$this->assertTrue( $umPlugin->registerAttempt( 'a@example.org', '10.0.0.1' ) );
		$this->assertFalse( $umPlugin->registerAttempt( 'a@example.org', '10.0.0.1' ) );
		$this->assertTrue( $outroPlugin->registerAttempt( 'a@example.org', '10.0.0.1' ) );
	}

	public function testJanelaExpiraELiberaNovamente(): void {
		$limiter = new AttemptLimiter( $this->store(), 'v3r_test', 900, 1 );

		$this->assertTrue( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );
		$this->assertFalse( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );

		$this->agora += 901;

		$this->assertTrue( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );
	}

	public function testJanelaEhDeslizanteAInsistenciaNaoReabreACota(): void {
		$limiter = new AttemptLimiter( $this->store(), 'v3r_test', 900, 1 );

		$this->assertTrue( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );

		// Insiste perto do fim da janela: a tentativa renova a expiração.
		$this->agora += 890;
		$this->assertFalse( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );

		// Passado o que teria sido o fim da janela original, segue bloqueado.
		$this->agora += 20;
		$this->assertFalse( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );
	}

	public function testResetLiberaCadaChaveSeparadamente(): void {
		$store   = $this->store();
		$limiter = new AttemptLimiter( $store, 'v3r_test', 900, 1 );

		$limiter->registerAttempt( 'a@example.org', '10.0.0.1' );
		$this->assertFalse( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );

		// A liberação do e-mail (aceitando a mesma normalização) não basta:
		// a origem já acumulou as duas tentativas.
		$limiter->resetIdentifier( 'A@Example.org ' );
		$this->assertFalse( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );

		// Liberadas as duas chaves, a próxima tentativa passa.
		$limiter->resetIdentifier( 'a@example.org' );
		$limiter->resetOrigin( '10.0.0.1' );
		$this->assertTrue( $limiter->registerAttempt( 'a@example.org', '10.0.0.1' ) );
	}

	public function testOrigemVaziaAgrupaEmUmBaldeSoEmVezDeIsentar(): void {
		$limiter = new AttemptLimiter( $this->store(), 'v3r_test', 900, 1 );

		$this->assertTrue( $limiter->registerAttempt( 'a@example.org', '' ) );
		$this->assertFalse( $limiter->registerAttempt( 'b@example.org', '' ) );
	}

	/**
	 * @dataProvider construcaoInvalida
	 */
	public function testConstrutorRecusaConfiguracaoQueDesligaOLimitador( string $prefix, int $window, int $max ): void {
		$this->expectException( \InvalidArgumentException::class );

		new AttemptLimiter( $this->store(), $prefix, $window, $max );
	}

	/**
	 * @return array<string, array{0: string, 1: int, 2: int}>
	 */
	public function construcaoInvalida(): array {
		return array(
			'prefixo vazio' => array( '', 900, 3 ),
			'janela zero'   => array( 'v3r_test', 0, 3 ),
			'teto zero'     => array( 'v3r_test', 900, 0 ),
		);
	}
}
