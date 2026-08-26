<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Licensing\Transport;

use V3R\Core\Licensing\Transport\HttpTransportInterface;
use V3R\Core\Licensing\Transport\HttpTransportResult;

/**
 * Transporte falso para teste: devolve resultados pré-programados numa
 * fila, um por chamada (post ou get, indistintamente — a ordem de chamada é
 * o que importa). Registra cada chamada feita, para os testes conferirem
 * URL/timeout/corpo quando for relevante.
 */
final class FakeHttpTransport implements HttpTransportInterface {

	/** @var HttpTransportResult[] */
	private $queue = array();

	/**
	 * @var array<int, array{method: string, url: string, body: array<string, mixed>|null, timeout: int}>
	 */
	private $calls = array();

	public function enqueue( HttpTransportResult $result ): void {
		$this->queue[] = $result;
	}

	/**
	 * @param string               $url
	 * @param array<string, mixed> $body
	 * @param int                  $timeout
	 */
	public function post( string $url, array $body, int $timeout ): HttpTransportResult {
		$this->calls[] = array(
			'method'  => 'POST',
			'url'     => $url,
			'body'    => $body,
			'timeout' => $timeout,
		);

		return $this->dequeue();
	}

	public function get( string $url, int $timeout ): HttpTransportResult {
		$this->calls[] = array(
			'method'  => 'GET',
			'url'     => $url,
			'body'    => null,
			'timeout' => $timeout,
		);

		return $this->dequeue();
	}

	/**
	 * @return array<int, array{method: string, url: string, body: array<string, mixed>|null, timeout: int}>
	 */
	public function getCalls(): array {
		return $this->calls;
	}

	public function getCallCount(): int {
		return count( $this->calls );
	}

	private function dequeue(): HttpTransportResult {
		if ( array() === $this->queue ) {
			throw new \RuntimeException( 'FakeHttpTransport: nenhum resultado programado para esta chamada.' );
		}

		return array_shift( $this->queue );
	}
}
