<?php
declare(strict_types=1);

namespace V3R\Core\Roles\Storage;

/**
 * Abstração mínima sobre onde os papéis de UMA pessoa ficam gravados — só
 * para permitir testar `PermissionEngine` sem WordPress carregado, mesmo
 * papel de `KeyValueStoreInterface` (Licensing\Storage) para a matriz.
 *
 * Não é um `KeyValueStoreInterface` porque a chave real (o meta de usuário)
 * é indexada por `$userId`, não por string livre — reaproveitar a mesma
 * interface obrigaria quem implementa a derivar a chave por fora, e essa
 * derivação é exatamente a parte que muda de produto para produto (nome do
 * meta) e que a configuração de onde se guarda (docs/papeis-orientados-a-dados.md
 * §4) já resolve no construtor da implementação.
 */
interface RoleAssignmentStoreInterface {

	/**
	 * O valor bruto gravado para a pessoa — pode ser `null` (nunca gravado),
	 * uma `string` (formato legado, um papel só) ou uma lista de strings
	 * (formato atual). A normalização é responsabilidade de quem lê
	 * (`PermissionEngine::rolesOf()`), não desta interface.
	 *
	 * @return mixed
	 */
	public function getRoles( int $userId );

	/**
	 * @param int      $userId
	 * @param string[] $slugs  Lista já validada e deduplicada — quem chama
	 *                         (`PermissionEngine::assignRoles()`) garante isso.
	 */
	public function setRoles( int $userId, array $slugs ): void;

	/** Remove o vínculo — equivalente a atribuir a lista vazia. */
	public function deleteRoles( int $userId ): void;
}
