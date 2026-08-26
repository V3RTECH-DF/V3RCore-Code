<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

/**
 * Implementação do ApiClientInterface via wp_remote_post()/wp_remote_get().
 *
 * TODO(fatia-2): implementar as chamadas de rede reais contra
 * v3r-license/v1 (docs/api-contract.md), incluindo timeout, tratamento de
 * wp_error e mapeamento do corpo de erro REST do WordPress para ApiException.
 */
class HttpApiClient implements ApiClientInterface {

	/** @var string */
	private $baseUrl;

	public function __construct( string $baseUrl ) {
		$this->baseUrl = rtrim( $baseUrl, '/' );
	}

	/**
	 * Exposto para debug/teste; a fatia 2 monta as URLs de cada endpoint
	 * a partir dela (docs/api-contract.md).
	 */
	public function getBaseUrl(): string {
		return $this->baseUrl;
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @throws \LogicException Sempre, nesta fatia — lógica de rede ainda não existe.
	 */
	public function activate( array $payload ): array {
		throw new \LogicException( 'não implementado' );
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @throws \LogicException Sempre, nesta fatia — lógica de rede ainda não existe.
	 */
	public function deactivate( array $payload ): array {
		throw new \LogicException( 'não implementado' );
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @throws \LogicException Sempre, nesta fatia — lógica de rede ainda não existe.
	 */
	public function validate( array $payload ): array {
		throw new \LogicException( 'não implementado' );
	}

	/**
	 * @param array<string, mixed> $query
	 *
	 * @throws \LogicException Sempre, nesta fatia — lógica de rede ainda não existe.
	 */
	public function checkUpdate( array $query ): array {
		throw new \LogicException( 'não implementado' );
	}
}
