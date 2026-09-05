<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

/**
 * Falha declarada de AuthenticityRegistry::seal() — nunca em silêncio, e
 * nunca gravando registro novo (issue #28). O código de autenticidade é
 * emitido antes de o arquivo final existir (ele é impresso DENTRO do
 * documento); selar é o segundo passo, que grava o resumo do arquivo já
 * pronto no registro que issue() criou. Um selamento que falha por
 * qualquer um destes motivos deixa o registro exatamente como estava —
 * quem chama pode tentar de novo.
 */
class AuthenticitySealingException extends \RuntimeException {

	/**
	 * O código não corresponde a nenhum registro emitido — nunca existiu,
	 * ou já expirou/foi removido do armazenamento.
	 */
	public const CODIGO_INEXISTENTE = 'codigo_inexistente';

	/**
	 * O arquivo apontado para selar não existe, não é legível, ou o
	 * resumo sha256 não pôde ser calculado.
	 */
	public const ARQUIVO_ILEGIVEL = 'arquivo_ilegivel';

	/**
	 * O registro já está selado, e o resumo do arquivo apresentado agora
	 * é DIFERENTE do que já foi gravado. Selar de novo com o MESMO
	 * resumo é aceito e não lança esta exceção (issue #28, detalhe b) —
	 * é o que permite refazer uma tentativa que falhou entre emitir e
	 * selar sem entijolar o registro para sempre, sem nunca deixar o
	 * registro provar algo diferente do que ele provou da primeira vez.
	 */
	public const RESUMO_DIVERGENTE = 'resumo_divergente';

	/** @var string */
	private $errorCode;

	public function __construct( string $errorCode, string $message ) {
		parent::__construct( $message );
		$this->errorCode = $errorCode;
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}
}
