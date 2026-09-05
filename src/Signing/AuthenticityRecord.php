<?php
declare(strict_types=1);

namespace V3R\Core\Signing;

use DateTimeImmutable;

/**
 * O que fica guardado de um documento emitido, associado ao código de
 * autenticidade (issue #27): quando foi emitido, com qual modo de
 * assinatura, e o resumo criptográfico (sha256) do arquivo emitido — é
 * esse resumo que permite a AuthenticityRegistry::verifyFile() dizer se um
 * arquivo apresentado depois é o mesmo que foi emitido, sem recalcular nada
 * a partir dos campos do documento.
 *
 * Objeto imutável.
 */
final class AuthenticityRecord {

	/** @var string */
	private $code;

	/** @var string */
	private $mode;

	/** @var DateTimeImmutable */
	private $emittedAt;

	/** @var string */
	private $fileHash;

	public function __construct( string $code, string $mode, DateTimeImmutable $emittedAt, string $fileHash ) {
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
	 * Sha256 hexadecimal do arquivo tal como emitido.
	 */
	public function fileHash(): string {
		return $this->fileHash;
	}

	/**
	 * Formato de persistência via KeyValueStoreInterface (issue #27: "a
	 * conferência é consulta, não recálculo" — o registro é lido de volta
	 * tal como foi guardado, nunca reconstruído a partir de outra coisa).
	 *
	 * @return array{code: string, mode: string, emitted_at: string, file_hash: string}
	 */
	public function toArray(): array {
		return array(
			'code'       => $this->code,
			'mode'       => $this->mode,
			'emitted_at' => $this->emittedAt->format( DATE_ATOM ),
			'file_hash'  => $this->fileHash,
		);
	}

	/**
	 * @param array<string, mixed> $data Formato produzido por toArray().
	 *
	 * @throws \InvalidArgumentException Formato incompleto ou corrompido — sinal de que o
	 *                                    armazenamento subjacente guardou algo que não veio
	 *                                    daqui, e não deve ser tratado como registro válido.
	 */
	public static function fromArray( array $data ): self {
		foreach ( array( 'code', 'mode', 'emitted_at', 'file_hash' ) as $requiredKey ) {
			if ( ! isset( $data[ $requiredKey ] ) || ! is_string( $data[ $requiredKey ] ) ) {
				throw new \InvalidArgumentException( "AuthenticityRecord::fromArray: campo '{$requiredKey}' ausente ou inválido." );
			}
		}

		$emittedAt = DateTimeImmutable::createFromFormat( DATE_ATOM, $data['emitted_at'] );

		if ( false === $emittedAt ) {
			throw new \InvalidArgumentException( "AuthenticityRecord::fromArray: 'emitted_at' não é uma data ATOM válida." );
		}

		return new self( $data['code'], $data['mode'], $emittedAt, $data['file_hash'] );
	}
}
