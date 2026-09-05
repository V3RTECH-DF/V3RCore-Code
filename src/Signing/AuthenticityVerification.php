<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

/**
 * Resultado de AuthenticityRegistry::verifyFile(): existe registro para o
 * código e, se existe, em qual dos três estados ele está — nunca existiu,
 * emitido mas ainda não selado (issue #28: o código é impresso DENTRO do
 * documento, então no instante da emissão o arquivo final ainda não
 * existe — esse intervalo é real, não um erro), ou selado e comparado
 * contra o arquivo apresentado agora. Objeto imutável.
 */
final class AuthenticityVerification {

	/** @var bool */
	private $found;

	/** @var AuthenticityRecord|null */
	private $record;

	/**
	 * Null quando não há o que comparar (código não encontrado, ou
	 * registro ainda não selado — ver awaitingSeal()). Nos demais casos,
	 * sempre bool: true (arquivo idêntico ao que foi selado) ou false
	 * (arquivo alterado, ou ilegível/ausente no ponto de verificação).
	 *
	 * @var bool|null
	 */
	private $fileMatches;

	/**
	 * True só no terceiro estado: o código existe, mas o registro ainda
	 * não foi selado. Existe como método próprio — e não como "leia
	 * fileMatches() e veja se é null" — porque notFound() também produz
	 * fileMatches() null, e as duas coisas não podem ser confundidas por
	 * quem consome (issue #28, detalhe a): "não confere" e "não há como
	 * conferir ainda" são respostas diferentes.
	 *
	 * @var bool
	 */
	private $awaitingSeal;

	private function __construct( bool $found, ?AuthenticityRecord $record, ?bool $fileMatches, bool $awaitingSeal ) {
		$this->found        = $found;
		$this->record       = $record;
		$this->fileMatches  = $fileMatches;
		$this->awaitingSeal = $awaitingSeal;
	}

	public static function notFound(): self {
		return new self( false, null, null, false );
	}

	/**
	 * O código existe, mas o registro ainda não foi selado — não há
	 * resumo gravado para comparar contra nada. wasTampered() é sempre
	 * false aqui: um documento não selado não pode ser acusado de
	 * adulterado, porque a biblioteca ainda não prometeu nada sobre o
	 * arquivo (issue #28, detalhe a).
	 */
	public static function awaitingSeal( AuthenticityRecord $record ): self {
		return new self( true, $record, null, true );
	}

	public static function found( AuthenticityRecord $record, bool $fileMatches ): self {
		return new self( true, $record, $fileMatches, false );
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

	public function isAwaitingSeal(): bool {
		return $this->awaitingSeal;
	}

	/**
	 * Verdadeiro só quando o código existe, o registro está selado e o
	 * arquivo apresentado NÃO bate com o que foi selado — o caso que a
	 * issue #27 pede para distinguir de "código desconhecido", e que a
	 * issue #28 pede para nunca confundir com "ainda não selado": um
	 * registro não selado nunca produz wasTampered() === true, mesmo que
	 * $fileMatches internamente também seja null nesse caso.
	 */
	public function wasTampered(): bool {
		return $this->found && ! $this->awaitingSeal && false === $this->fileMatches;
	}
}
