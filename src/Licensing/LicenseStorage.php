<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

/**
 * Decide onde e como o estado de licença persiste localmente (options do
 * WordPress para o estado corrente, transient para cache de validação de
 * 12h descrito em docs/api-contract.md).
 *
 * Esqueleto: a persistência real depende do formato definitivo assinado
 * pelo servidor (fatia 2). Aqui só o contrato de leitura/escrita existe.
 */
class LicenseStorage {

	/** @var string */
	private $optionName;

	/** @var string */
	private $transientName;

	public function __construct( string $productSlug ) {
		$this->optionName    = 'v3r_core_license_' . $productSlug;
		$this->transientName = 'v3r_core_license_cache_' . $productSlug;
	}

	/**
	 * TODO(fatia-2): serializar/desserializar LicenseState de verdade em
	 * wp_options, mascarando a chave em qualquer log de erro do próprio
	 * WordPress (nunca gravar a chave em claro fora da option dedicada).
	 */
	public function load( string $productSlug ): LicenseState {
		return LicenseState::neutral( $productSlug );
	}

	/**
	 * TODO(fatia-2): persistir via update_option().
	 */
	public function save( LicenseState $state ): void {
		// Intencionalmente vazio nesta fatia.
	}

	public function getOptionName(): string {
		return $this->optionName;
	}

	public function getTransientName(): string {
		return $this->transientName;
	}
}
