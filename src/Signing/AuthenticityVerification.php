<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

/**
 * Resultado de AuthenticityRegistry::verifyFile(): existe registro para o
 * código, e — se um arquivo foi apresentado — se ele bate com o que foi
 * emitido. Objeto imutável.
 */
final class AuthenticityVerification {

	/** @var bool */
	private $found;

	/** @var AuthenticityRecord|null */
	private $record;

	/**
	 * Null quando não há o que comparar (código não encontrado). Nos demais
	 * casos, sempre bool: true (arquivo idêntico ao emitido) ou false
	 * (arquivo alterado, ou ilegível/ausente no ponto de verificação).
	 *
	 * @var bool|null
	 */
	private $fileMatches;

	private function __construct( bool $found, ?AuthenticityRecord $record, ?bool $fileMatches ) {
		$this->found       = $found;
		$this->record      = $record;
		$this->fileMatches = $fileMatches;
	}

	public static function notFound(): self {
		return new self( false, null, null );
	}

	public static function found( AuthenticityRecord $record, bool $fileMatches ): self {
		return new self( true, $record, $fileMatches );
	}

	public function wasFound(): bool {
		return $this->found;
	}

	public function record(): ?AuthenticityRecord {
		return $this->record;
	}

	public function fileMatches(): ?bool {
		return $this->fileMatches;
	}

	/**
	 * Verdadeiro só quando o código existe e o arquivo apresentado NÃO bate
	 * com o que foi emitido — o caso que a issue #27 pede para distinguir
	 * de "código desconhecido": um é "isto nunca foi emitido", o outro é
	 * "isto foi emitido, e o que você tem na mão não é mais aquilo".
	 */
	public function wasTampered(): bool {
		return $this->found && false === $this->fileMatches;
	}
}
