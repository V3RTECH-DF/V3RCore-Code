<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

/**
 * Falha declarada de CertificateSecretVault — nunca degrada em silêncio
 * (issue #27: "sem a chave configurada, o cofre declara que não pode
 * operar, em vez de gravar em texto claro").
 */
class CertificateVaultException extends \RuntimeException {

	/**
	 * A chave de cifragem não está configurada, ou está configurada num
	 * formato que não serve (base64 inválido, comprimento errado). O
	 * cofre se recusa a gravar a senha em texto claro nesse caso — este
	 * código é o motivo.
	 */
	public const CHAVE_DE_CIFRAGEM_INDISPONIVEL = 'chave_de_cifragem_indisponivel';

	/**
	 * Nenhuma senha guardada sob este prefixo.
	 */
	public const SENHA_NAO_ENCONTRADA = 'senha_nao_encontrada';

	/**
	 * A decifragem falhou — chave errada (ex.: trocada depois de guardar)
	 * ou dado corrompido/alterado. sodium_crypto_secretbox_open() é
	 * autenticado (AEAD): esta exceção também cobre adulteração do valor
	 * guardado, não só senha esquecida.
	 */
	public const FALHA_AO_DECIFRAR = 'falha_ao_decifrar';

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
