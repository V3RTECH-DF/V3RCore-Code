<?php
declare(strict_types=1);

namespace V3R\Core\Licensing;

/**
 * Contrato de comunicação com o servidor de licenças (v3r-license/v1),
 * conforme docs/api-contract.md. Decide COMO os dados trafegam; não decide
 * política de cache, grace period nem se o site pode atualizar — isso é do
 * LicenseManager e do UpdateGate.
 */
interface ApiClientInterface {

	/**
	 * POST /activate — ativa a licença para este site.
	 *
	 * @param array<string, mixed> $payload license_key, product_slug, site_url,
	 *                                        plugin_version, php_version, wp_version.
	 * @return array<string, mixed> Corpo decodificado da resposta (payload + signature).
	 *
	 * @throws \V3R\Core\Licensing\ApiException Em falha de rede, timeout ou erro do servidor.
	 */
	public function activate( array $payload ): array;

	/**
	 * POST /deactivate — libera a cota de ativação deste domínio.
	 *
	 * @param array<string, mixed> $payload license_key, site_url, instance_id.
	 * @return array<string, mixed>
	 *
	 * @throws \V3R\Core\Licensing\ApiException Em falha de rede, timeout ou erro do servidor.
	 */
	public function deactivate( array $payload ): array;

	/**
	 * POST /validate — checagem periódica do estado da licença.
	 *
	 * @param array<string, mixed> $payload license_key, site_url, instance_id.
	 * @return array<string, mixed>
	 *
	 * @throws \V3R\Core\Licensing\ApiException Em falha de rede, timeout ou erro do servidor.
	 */
	public function validate( array $payload ): array;

	/**
	 * GET /update-check — metadados da versão disponível, se houver.
	 *
	 * @param array<string, mixed> $query product_slug, license_key, site_url, plugin_version.
	 * @return array<string, mixed>
	 *
	 * @throws \V3R\Core\Licensing\ApiException Em falha de rede, timeout ou erro do servidor.
	 */
	public function checkUpdate( array $query ): array;
}
