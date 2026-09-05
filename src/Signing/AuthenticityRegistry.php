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
 * Emitir e selar são dois momentos separados (issue #28), porque o código
 * de autenticidade é impresso DENTRO do documento: no instante em que ele
 * é sorteado, o arquivo final ainda não existe.
 *
 *   1. issue()  — sorteia o código (CSPRNG, nunca derivado do conteúdo) e
 *                 grava um registro sem resumo. É esse código que vai
 *                 impresso no documento.
 *   2. (quem chama gera o arquivo final, já com o código impresso nele)
 *   3. seal()   — recebe o código e o caminho do arquivo já pronto, calcula
 *                 o sha256 dele e grava no registro que issue() criou.
 *
 * Juntar os dois passos num só (como a versão anterior fazia, exigindo o
 * arquivo em issue()) obriga quem chama a calcular o resumo de um arquivo
 * intermediário, sem o código impresso — e o resumo gravado nunca bate com
 * o que a pessoa recebe. seal() é a única forma correta de gravar o
 * resumo.
 *
 * A conferência é CONSULTA, nunca recálculo: find()/verifyFile() sempre
 * leem o que seal() gravou; nada aqui é derivado de campo do documento.
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
	 * Emite um código novo e guarda o registro — sem resumo, porque o
	 * arquivo final ainda não existe neste momento (issue #28: o código
	 * vai impresso DENTRO do documento). O modo é o que SigningModeResolver
	 * decidiu para esta emissão — a biblioteca não deriva o modo aqui, só
	 * o guarda junto do código.
	 *
	 * O registro devolvido não confere nada ainda: `verifyFile()` sobre
	 * ele devolve o terceiro estado (`AuthenticityVerification::awaitingSeal()`)
	 * até `seal()` gravar o resumo do arquivo já pronto.
	 *
	 * @param string $mode Um dos valores de SigningMode.
	 *
	 * @throws \InvalidArgumentException Modo desconhecido.
	 */
	public function issue( string $mode ): AuthenticityRecord {
		if ( ! SigningMode::isValid( $mode ) ) {
			throw new \InvalidArgumentException( "AuthenticityRegistry::issue: modo desconhecido '{$mode}'." );
		}

		$code   = $this->generateUnusedCode();
		$record = new AuthenticityRecord( $code->value(), $mode, new DateTimeImmutable() );

		$this->store->set( $this->storageKey( $code->value() ), $record->toArray() );

		return $record;
	}

	/**
	 * Grava o resumo do arquivo final num registro já emitido — o segundo
	 * passo de issue()/seal() (issue #28). Chame depois de o código já
	 * estar impresso no documento.
	 *
	 * Selar é uma vez só, sem entijolar: selar de novo com o MESMO resumo
	 * é aceito e não faz nada (idempotente — permite refazer uma
	 * tentativa que falhou entre emitir e selar); selar com um resumo
	 * DIFERENTE é recusado, porque aceitar trocaria o que o registro
	 * promete depois de já ter sido selado.
	 *
	 * @param string $code             Código já emitido por issue().
	 * @param string $absoluteFilePath Caminho absoluto do arquivo final, já com o código impresso.
	 *
	 * @throws AuthenticitySealingException Código inexistente; arquivo inexistente, ilegível ou
	 *                                        sem resumo calculável; ou resumo diferente do que já
	 *                                        está gravado num registro já selado.
	 */
	public function seal( string $code, string $absoluteFilePath ): AuthenticityRecord {
		$record = $this->find( $code );

		if ( null === $record ) {
			throw new AuthenticitySealingException(
				AuthenticitySealingException::CODIGO_INEXISTENTE,
				"AuthenticityRegistry::seal: código inexistente '{$code}'."
			);
		}

		if ( ! is_file( $absoluteFilePath ) || ! is_readable( $absoluteFilePath ) ) {
			throw new AuthenticitySealingException(
				AuthenticitySealingException::ARQUIVO_ILEGIVEL,
				"AuthenticityRegistry::seal: arquivo inexistente ou ilegível '{$absoluteFilePath}'."
			);
		}

		$fileHash = hash_file( 'sha256', $absoluteFilePath );

		if ( false === $fileHash ) {
			throw new AuthenticitySealingException(
				AuthenticitySealingException::ARQUIVO_ILEGIVEL,
				"AuthenticityRegistry::seal: falha ao calcular o resumo de '{$absoluteFilePath}'."
			);
		}

		if ( $record->isSealed() ) {
			$storedHash = (string) $record->fileHash();

			if ( hash_equals( $storedHash, $fileHash ) ) {
				// Mesmo resumo: repetir o selamento não muda nada que o
				// registro já não provasse. Idempotente, de propósito.
				return $record;
			}

			throw new AuthenticitySealingException(
				AuthenticitySealingException::RESUMO_DIVERGENTE,
				"AuthenticityRegistry::seal: código '{$code}' já selado com um resumo diferente."
			);
		}

		$sealed = $record->sealedWith( $fileHash );

		$this->store->set( $this->storageKey( $record->code() ), $sealed->toArray() );

		return $sealed;
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
	 * selado, ou se foi alterado depois — comparando o resumo guardado
	 * com o resumo recalculado do arquivo apresentado agora. Nunca compara
	 * o conteúdo em si, só os dois resumos.
	 *
	 * Um registro emitido e ainda não selado (issue #28) não tem resumo
	 * para comparar contra nada: devolve o terceiro estado
	 * (`AuthenticityVerification::awaitingSeal()`), nunca "adulterado" —
	 * a biblioteca ainda não prometeu nada sobre esse arquivo.
	 */
	public function verifyFile( string $code, string $absoluteFilePath ): AuthenticityVerification {
		$record = $this->find( $code );

		if ( null === $record ) {
			return AuthenticityVerification::notFound();
		}

		if ( ! $record->isSealed() ) {
			return AuthenticityVerification::awaitingSeal( $record );
		}

		$storedHash = $record->fileHash();

		if ( null === $storedHash ) {
			// Não deveria acontecer: isSealed() acima garante que há
			// resumo gravado. Defensivo, não um caminho esperado.
			return AuthenticityVerification::awaitingSeal( $record );
		}

		if ( ! is_file( $absoluteFilePath ) || ! is_readable( $absoluteFilePath ) ) {
			return AuthenticityVerification::found( $record, false );
		}

		$currentHash = hash_file( 'sha256', $absoluteFilePath );

		return AuthenticityVerification::found( $record, false !== $currentHash && hash_equals( $storedHash, $currentHash ) );
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
