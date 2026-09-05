<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

use DateTimeImmutable;

/**
 * O que fica guardado de um documento, associado ao código de autenticidade
 * (issue #27, #28): quando foi emitido, com qual modo de assinatura e —
 * só depois de selado — o resumo criptográfico (sha256) do arquivo final.
 *
 * O código é emitido ANTES de o arquivo final existir, porque ele é
 * impresso DENTRO do documento: `fileHash()` nasce nulo em `issue()` e só
 * passa a existir quando `AuthenticityRegistry::seal()` grava o resumo do
 * arquivo já pronto. `isSealed()` é como quem consome distingue os dois
 * momentos sem inspecionar `null` por conta própria. É esse resumo —
 * gravado depois, nunca no instante da emissão — que permite a
 * `AuthenticityRegistry::verifyFile()` dizer se um arquivo apresentado
 * depois é o mesmo que foi selado, sem recalcular nada a partir dos campos
 * do documento.
 *
 * Objeto imutável: seal() nunca muda esta instância, produz outra
 * (`sealedWith()`) para persistir.
 */
final class AuthenticityRecord {

	/** @var string */
	private $code;

	/** @var string */
	private $mode;

	/** @var DateTimeImmutable */
	private $emittedAt;

	/**
	 * Null antes de selado — deliberado, não um valor "ainda inválido": é
	 * o que representa o intervalo real entre emitir o código e ter o
	 * arquivo final para calcular o resumo dele.
	 *
	 * @var string|null
	 */
	private $fileHash;

	public function __construct( string $code, string $mode, DateTimeImmutable $emittedAt, ?string $fileHash = null ) {
		if ( ! SigningMode::isValid( $mode ) ) {
			throw new \InvalidArgumentException( "AuthenticityRecord: modo desconhecido '{$mode}'." );
		}

		$this->code      = $code;
		$this->mode      = $mode;
		$this->emittedAt = $emittedAt;
		$this->fileHash  = $fileHash;
	}

	public function code(): string {
		return $this->code;
	}

	public function mode(): string {
		return $this->mode;
	}

	public function emittedAt(): DateTimeImmutable {
		return $this->emittedAt;
	}

	/**
	 * Sha256 hexadecimal do arquivo tal como selado, ou null antes de
	 * `AuthenticityRegistry::seal()` gravá-lo — ver `isSealed()`.
	 */
	public function fileHash(): ?string {
		return $this->fileHash;
	}

	/**
	 * Falso entre `issue()` e `seal()`: o código já existe, mas ainda não
	 * há resumo para conferir nada contra ele. `AuthenticityVerification`
	 * trata esse intervalo como um terceiro estado, nunca como
	 * adulteração (issue #28).
	 */
	public function isSealed(): bool {
		return null !== $this->fileHash;
	}

	/**
	 * Novo registro, com o resumo do arquivo final gravado — mesma
	 * identidade (código, modo, data de emissão), só o resumo muda.
	 * Não decide sozinho se pode substituir um registro já selado; quem
	 * decide isso é `AuthenticityRegistry::seal()`, que é quem conhece a
	 * regra de "mesmo resumo é idempotente, resumo diferente é recusado".
	 */
	public function sealedWith( string $fileHash ): self {
		return new self( $this->code, $this->mode, $this->emittedAt, $fileHash );
	}

	/**
	 * Formato de persistência via KeyValueStoreInterface (issue #27: "a
	 * conferência é consulta, não recálculo" — o registro é lido de volta
	 * tal como foi guardado, nunca reconstruído a partir de outra coisa).
	 * `file_hash` só aparece depois de selado — antes disso, o campo
	 * simplesmente não existe no array persistido (issue #28).
	 *
	 * @return array{code: string, mode: string, emitted_at: string, file_hash?: string}
	 */
	public function toArray(): array {
		$data = array(
			'code'       => $this->code,
			'mode'       => $this->mode,
			'emitted_at' => $this->emittedAt->format( DATE_ATOM ),
		);

		if ( null !== $this->fileHash ) {
			$data['file_hash'] = $this->fileHash;
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $data Formato produzido por toArray(). 'file_hash' é
	 *                                    opcional (issue #28) — registro gravado antes da
	 *                                    #28 sempre o tem e continua lendo exatamente como
	 *                                    antes; registro emitido e ainda não selado não tem.
	 *
	 * @throws \InvalidArgumentException Formato incompleto ou corrompido — sinal de que o
	 *                                    armazenamento subjacente guardou algo que não veio
	 *                                    daqui, e não deve ser tratado como registro válido.
	 */
	public static function fromArray( array $data ): self {
		foreach ( array( 'code', 'mode', 'emitted_at' ) as $requiredKey ) {
			if ( ! isset( $data[ $requiredKey ] ) || ! is_string( $data[ $requiredKey ] ) ) {
				throw new \InvalidArgumentException( "AuthenticityRecord::fromArray: campo '{$requiredKey}' ausente ou inválido." );
			}
		}

		$emittedAt = DateTimeImmutable::createFromFormat( DATE_ATOM, $data['emitted_at'] );

		if ( false === $emittedAt ) {
			throw new \InvalidArgumentException( "AuthenticityRecord::fromArray: 'emitted_at' não é uma data ATOM válida." );
		}

		$fileHash = null;

		if ( array_key_exists( 'file_hash', $data ) ) {
			if ( ! is_string( $data['file_hash'] ) ) {
				throw new \InvalidArgumentException( "AuthenticityRecord::fromArray: campo 'file_hash' inválido." );
			}

			$fileHash = $data['file_hash'];
		}

		return new self( $data['code'], $data['mode'], $emittedAt, $fileHash );
	}
}
