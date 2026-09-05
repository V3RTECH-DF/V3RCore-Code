<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

use DateTimeImmutable;
use V3R\Core\Licensing\Storage\KeyValueStoreInterface;

/**
 * Emite e confere códigos de autenticidade (issue #27, defeitos 2 e 3 do
 * V3RProp). Persiste pela mesma abstração de armazenamento que o resto da
 * biblioteca já usa (Licensing\Storage\KeyValueStoreInterface) — nunca
 * acesso direto a opções do WordPress.
 *
 * A conferência é CONSULTA, nunca recálculo: find()/verifyFile() sempre
 * leem o que issue() gravou; nada aqui é derivado de campo do documento.
 * É essa propriedade — não o alfabeto do código — que torna o código
 * verificável de verdade.
 */
final class AuthenticityRegistry {

	/** @var KeyValueStoreInterface */
	private $store;

	/** @var string */
	private $keyPrefix;

	/**
	 * $keyPrefix separa o registro de dois consumidores (dois plugins da
	 * casa) no mesmo WordPress — mesmo padrão de Access\AttemptLimiter.
	 *
	 * @throws \InvalidArgumentException Prefixo vazio.
	 */
	public function __construct( KeyValueStoreInterface $store, string $keyPrefix ) {
		if ( '' === $keyPrefix ) {
			throw new \InvalidArgumentException( 'AuthenticityRegistry: keyPrefix não pode ser vazio.' );
		}

		$this->store     = $store;
		$this->keyPrefix = $keyPrefix;
	}

	/**
	 * Emite um código novo para o arquivo já gerado, e guarda o registro.
	 * O modo é o que SigningModeResolver decidiu para esta emissão — a
	 * biblioteca não deriva o modo aqui, só o guarda junto do código.
	 *
	 * @param string $absoluteFilePath Caminho absoluto do arquivo já emitido (ex.: o PDF assinado).
	 * @param string $mode             Um dos valores de SigningMode.
	 *
	 * @throws \InvalidArgumentException Modo desconhecido, ou arquivo inexistente/ilegível.
	 */
	public function issue( string $absoluteFilePath, string $mode ): AuthenticityRecord {
		if ( ! SigningMode::isValid( $mode ) ) {
			throw new \InvalidArgumentException( "AuthenticityRegistry::issue: modo desconhecido '{$mode}'." );
		}

		if ( ! is_file( $absoluteFilePath ) || ! is_readable( $absoluteFilePath ) ) {
			throw new \InvalidArgumentException( "AuthenticityRegistry::issue: arquivo inexistente ou ilegível '{$absoluteFilePath}'." );
		}

		$fileHash = hash_file( 'sha256', $absoluteFilePath );

		if ( false === $fileHash ) {
			throw new \InvalidArgumentException( "AuthenticityRegistry::issue: falha ao calcular o resumo de '{$absoluteFilePath}'." );
		}

		$code   = $this->generateUnusedCode();
		$record = new AuthenticityRecord( $code->value(), $mode, new DateTimeImmutable(), $fileHash );

		$this->store->set( $this->storageKey( $code->value() ), $record->toArray() );

		return $record;
	}

	/**
	 * Dado um código, devolve o que aquele documento é, quando foi emitido
	 * e como foi assinado — ou null se o código não corresponde a nada
	 * emitido (inclusive quando o texto digitado nem chega a ter o formato
	 * de um código: erro de transcrição e "nunca existiu" são a mesma
	 * resposta aqui, de propósito — quem confere não precisa saber a
	 * diferença).
	 */
	public function find( string $code ): ?AuthenticityRecord {
		try {
			$normalized = AuthenticityCode::fromString( $code )->value();
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}

		$data = $this->store->get( $this->storageKey( $normalized ) );

		if ( ! is_array( $data ) ) {
			return null;
		}

		try {
			return AuthenticityRecord::fromArray( $data );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * Dado um código e um arquivo, diz se o arquivo é o mesmo que foi
	 * emitido, ou se foi alterado depois — comparando o resumo guardado
	 * com o resumo recalculado do arquivo apresentado agora. Nunca compara
	 * o conteúdo em si, só os dois resumos.
	 */
	public function verifyFile( string $code, string $absoluteFilePath ): AuthenticityVerification {
		$record = $this->find( $code );

		if ( null === $record ) {
			return AuthenticityVerification::notFound();
		}

		if ( ! is_file( $absoluteFilePath ) || ! is_readable( $absoluteFilePath ) ) {
			return AuthenticityVerification::found( $record, false );
		}

		$currentHash = hash_file( 'sha256', $absoluteFilePath );

		return AuthenticityVerification::found( $record, false !== $currentHash && hash_equals( $record->fileHash(), $currentHash ) );
	}

	/**
	 * Gera até não colidir com um código já emitido. Colisão é
	 * astronomicamente improvável (31^16 combinações) — o laço existe para
	 * nunca sobrescrever um registro existente, não porque colisão seja
	 * esperada.
	 */
	private function generateUnusedCode(): AuthenticityCode {
		do {
			$code = AuthenticityCode::generate();
		} while ( null !== $this->store->get( $this->storageKey( $code->value() ) ) );

		return $code;
	}

	private function storageKey( string $code ): string {
		return $this->keyPrefix . '_signing_auth_' . hash( 'sha256', $code );
	}
}
