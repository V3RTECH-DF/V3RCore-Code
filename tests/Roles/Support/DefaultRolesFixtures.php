<?php
declare(strict_types=1);

namespace V3R\Core\Tests\Roles\Support;

/**
 * Recortes dos papéis-modelo REAIS dos dois consumidores de origem
 * (V3RLGPD e RIT360 Premiado) — não inventados —, usados para provar que a
 * generalização de `RoleMatrix::reconcileModule()` produz o mesmo resultado
 * que `Permissions::ensure_module_seeded()` (V3RLGPD) e
 * `Permissions::backfill_audit_permissions()`/`backfill_users_permissions()`
 * (RIT360 Premiado) produziam com listas de papéis à mão.
 */
final class DefaultRolesFixtures {

	/**
	 * V3RLGPD: `ensure_module_seeded()` só concede o módulo novo a `dpo` e
	 * `auditor` (lista à mão) — `atendente` fica de fora porque os padrões
	 * dele nunca incluíram o módulo `detector`, então a regra geral também
	 * o exclui sem precisar da lista.
	 *
	 * @return array<string, array{label: string, description: string, permissions: string[]}>
	 */
	public static function v3rlgpdComModuloDetector(): array {
		return array(
			'dpo'       => array(
				'label'       => 'Encarregado',
				'description' => 'Acesso completo de operação da conformidade.',
				'permissions' => array( 'dashboard.view', 'ropa.view', 'ropa.manage', 'detector.view', 'detector.manage' ),
			),
			'auditor'   => array(
				'label'       => 'Auditor',
				'description' => 'Somente leitura em todos os módulos.',
				'permissions' => array( 'dashboard.view', 'ropa.view', 'detector.view' ),
			),
			'atendente' => array(
				'label'       => 'Atendente',
				'description' => 'Dia a dia com titulares.',
				'permissions' => array( 'dashboard.view', 'dsar.view', 'dsar.manage' ),
			),
		);
	}

	/**
	 * RIT360 Premiado: `backfill_audit_permissions()` concede `audit.view` ao
	 * auditor, `audit.view`+`audit.manage` ao admin_campanha, e nada ao
	 * operador — cujos padrões nunca incluíram `audit.*` (BP-113: "operador
	 * NÃO acessa a trilha de auditoria").
	 *
	 * @return array<string, array{label: string, description: string, permissions: string[]}>
	 */
	public static function premiadoComModuloAudit(): array {
		return array(
			'admin_campanha' => array(
				'label'       => 'Administrador da campanha',
				'description' => 'Acesso completo.',
				'permissions' => array( 'dashboard.view', 'campaigns.view', 'campaigns.manage', 'audit.view', 'audit.manage' ),
			),
			'auditor'        => array(
				'label'       => 'Auditor',
				'description' => 'Somente leitura.',
				'permissions' => array( 'dashboard.view', 'campaigns.view', 'audit.view' ),
			),
			'operador'       => array(
				'label'       => 'Operador',
				'description' => 'Vendas e prestação de contas.',
				'permissions' => array( 'dashboard.view', 'orders.view', 'orders.manage' ),
			),
		);
	}
}
