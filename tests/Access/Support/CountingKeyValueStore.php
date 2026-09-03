<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Access\Support;

use V3R\Core\Licensing\Storage\KeyValueStoreInterface;

/**
 * Decorador que conta leituras e escritas — existe para provar que uma
 * tentativa recusada e uma permitida deixam o MESMO rastro no
 * armazenamento. Um `return` antecipado que pulasse o incremento seria
 * invisível no valor final dos contadores e visível aqui.
 */
final class CountingKeyValueStore implements KeyValueStoreInterface {

	/** @var KeyValueStoreInterface */
	private $inner;

	/** @var int */
	public $writes = 0;

	/** @var int */
	public $reads = 0;

	/** @var string[] */
	public $writtenKeys = array();

	public function __construct( KeyValueStoreInterface $inner ) {
		$this->inner = $inner;
	}

	/**
	 * @return mixed
	 */
	public function get( string $key ) {
		++$this->reads;

		return $this->inner->get( $key );
	}

	/**
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $expirationSeconds
	 */
	public function set( string $key, $value, int $expirationSeconds = 0 ): void {
		++$this->writes;
		$this->writtenKeys[] = $key;
		$this->inner->set( $key, $value, $expirationSeconds );
	}

	public function delete( string $key ): void {
		$this->inner->delete( $key );
	}

	public function resetCounters(): void {
		$this->writes      = 0;
		$this->reads       = 0;
		$this->writtenKeys = array();
	}
}
