# Papéis orientados a dados (RBAC editável pelo cliente)

> Catálogo do motor de papéis-e-permissões que a biblioteca oferece a partir
> do namespace `V3R\Core\Roles\`. Issue `V3RCore-Code#39`, decisão do Bruno.
> Promovido de duas implementações reais que convergiram sozinhas na mesma
> estrutura: `V3RTECH/V3RLGPD` (`Core\Permissions`, um papel por pessoa) e
> `RIT/RIT360-Premiado` (`Core\Permissions`, vários papéis por pessoa, E13).
> Consumidores: os dois produtos de origem, mais qualquer plugin novo que
> precise de papéis editáveis pelo cliente em vez de capability nativa fixa.

## 1. A forma: "a pessoa tem um CONJUNTO de papéis"

É a generalização que resolve o único eixo em que as duas implementações de
origem divergiam. O V3RLGPD guardava **um** papel por pessoa (`get_user_meta`
com uma string); o RIT360 Premiado evoluiu (E13) para **vários**, com a
permissão efetiva sendo a **união** dos papéis, e já normalizava o formato
legado (string única) para lista antes de resolver.

`V3R\Core\Roles\PermissionEngine::rolesOf()` é essa normalização, generalizada:
aceita `null` (nunca atribuído), `string` (formato legado, um papel) e
`array` (formato atual), sempre devolvendo uma lista. Um produto com um papel
só (V3RLGPD) não muda nada: é só um conjunto de um elemento, na prática. **O
produto nunca converte nada** — a biblioteca lê os dois formatos direto.

## 2. O que sobe para a biblioteca, e o que não sobe

| Peça | Sobe? | Onde vive |
| --- | --- | --- |
| Catálogo de permissões (`<módulo>.view`/`.manage` + avulsas) | Sim | `PermissionCatalog::build()` |
| Papéis como dado (`slug => [label, description, permissions[]]`) e a matriz guardada | Sim | `RoleMatrix` |
| Resolução (`userCan`, `rolesOf`, `hasAnyRole`, permissões efetivas) | Sim | `PermissionEngine` |
| Anti-tranca do administrador | Sim | `PermissionEngine` (bypass injetado) |
| Semeadura idempotente | Sim | `RoleMatrix::seedIfMissing()` |
| Módulo novo aparecendo nos papéis já semeados | Sim | `RoleMatrix::reconcileModule()` |
| Atribuição de papéis a uma pessoa (ler e gravar) | Sim | `PermissionEngine::rolesOf()`/`assignRoles()` |
| Exposição separada das permissões sensíveis | Sim | `PermissionEngine::sensitivePermissions()` |
| Lista de módulos e seus rótulos | **Não** | Vocabulário do produto |
| Os papéis-modelo (seed) | **Não** | Decisão de produto — o produto fornece `$defaultRoles` |
| Onde se guarda (nome da option/meta) | **Não** | Configuração — vem no construtor de `RoleMatrix`/da implementação de `RoleAssignmentStoreInterface` |
| Correções de dado específicas de um produto (`backfill_*` do Premiado) | **Não** | Ficam no produto; a versão genérica é `reconcileModule()` |
| A tela de "usuários e papéis" | **Não** | Sobe depois, com a tela (issue própria) |
| Validação de criação de papel customizado (nome reservado, higienização de rótulo) | **Não** | Idem — é parte da tela, não da resolução |

**O que não subiu, e por quê:** `slugify()`, `clean_label()`, `is_builtin()`,
`sanitize_role_map()`, `save_role()`, `restore_role()`, `delete_role()`,
`is_modified()` e `reassign_users()` (as duas implementações de origem têm
equivalentes) ficam no produto. Essas peças pertencem à **tela** de edição de
papel — criar cargo customizado, saneamento do que a tela recebe, restaurar
padrão — e a promoção desta issue foi só do motor de **resolução**. Subir
essas peças sem a tela que as usa criaria API sem consumidor e arriscaria
travar decisões de UX (ex.: quais slugs são reservados) que ainda não foram
tomadas para o conjunto dos produtos.

## 3. Uso

```php
use V3R\Core\Roles\PermissionCatalog;
use V3R\Core\Roles\PermissionEngine;
use V3R\Core\Roles\RoleMatrix;
use V3R\Core\Roles\Storage\WordPressUserMetaStore;
use V3R\Core\Licensing\Storage\WordPressOptionStore;

// 1. Catálogo — vocabulário do produto.
$modules = array( 'ropa', 'dsar', 'consents' /* ... */ );
$catalog = PermissionCatalog::build( $modules, array( 'dashboard.view' ) );

// 2. Papéis-modelo — decisão de produto (mesma forma de default_roles() nas
//    duas implementações de origem).
$defaultRoles = array(
    'dpo' => array(
        'label'       => __( 'Encarregado', 'meuplugin' ),
        'description' => __( 'Acesso completo de operação.', 'meuplugin' ),
        'permissions' => array( 'dashboard.view', 'ropa.view', 'ropa.manage' ),
    ),
    // ...
);

// 3. Matriz — onde se guarda é configuração do produto.
$matrix = new RoleMatrix( new WordPressOptionStore(), 'meuplugin_roles', $defaultRoles );
$matrix->seedIfMissing();

// 4. Motor — liga matriz + atribuição + anti-tranca de administrador.
$engine = new PermissionEngine(
    $matrix,
    new WordPressUserMetaStore( 'meuplugin_role' ),
    fn ( int $userId ): bool => user_can( $userId, 'manage_options' ),
    array( 'dsar.erase', 'incidents.notify' ) // permissões sensíveis
);

// 5. Resolução.
$engine->userCan( $userId, 'ropa.manage' );
$engine->rolesOf( $userId );
$engine->assignRoles( $userId, array( 'dpo', 'auditor' ) );
```

## 4. Onde se guarda é configuração, não código

`RoleMatrix` recebe o nome da option no construtor; `WordPressUserMetaStore`
recebe o nome do meta. **É isso que faz a adoção não ter migração de dado** —
um produto que já tem `v3rlgpd_roles`/`v3rlgpd_role` continua usando esses
nomes exatos, e a troca para o motor da biblioteca é reversível: nada no
banco muda de formato ou de lugar. Dois produtos no mesmo WordPress usam
nomes diferentes e não interferem entre si (critério de aceite, coberto por
`PermissionEngineTest::testDoisProdutosComOptionKeysDiferentesNaoInterferem()`).

## 5. Rótulo e descrição vêm sempre do código

Regra herdada do V3RLGPD, mantida: `RoleMatrix::all()` sobrescreve `label` e
`description` de cada papel-modelo com o que está em `$defaultRoles`, mesmo
quando a matriz já está guardada — só as **permissões** vêm do guardado.
É o que faz renomear um papel-modelo no código ter efeito em instalação já
semeada, sem migração.

## 6. Módulo novo aparece nos papéis já semeados

`RoleMatrix::reconcileModule( string $module )` generaliza
`Permissions::ensure_module_seeded()` (V3RLGPD, lista à mão de papéis
elegíveis) e `Permissions::backfill_audit_permissions()`/
`backfill_users_permissions()` (RIT360 Premiado, um método por módulo): para
cada papel **já guardado**, acrescenta as permissões daquele módulo que os
**padrões** (`$defaultRoles`) já previam para aquele mesmo papel, e que ainda
não estão presentes. Sem lista de papéis elegíveis à parte — o próprio
`$defaultRoles` já é a lista: papel cujos padrões não incluem o módulo não
ganha nada.

Confirmado contra os dados reais dos dois produtos
(`tests/Roles/RoleMatrixTest.php`, fixtures em
`tests/Roles/Support/DefaultRolesFixtures.php`): a regra geral reproduz
exatamente a exclusão que cada implementação fazia à mão (`atendente` fora no
V3RLGPD, `operador` fora no Premiado) — nos dois casos porque os padrões
daquele papel nunca incluíram o módulo novo, não porque havia uma lista de
exceção.

## 7. Cache por requisição — dois cuidados que não estavam nas duas implementações de origem

**A matriz é lida do armazenamento no máximo uma vez por instância de
`RoleMatrix`.** A primeira chamada a qualquer método público dispara
`$store->get()`; as seguintes reusam o valor em memória.

**O bypass de administrador é resolvido uma vez por pessoa, não por
permissão.** Medido na adoção do V3RLGPD: `user_can()` chamado de dentro do
filtro `user_has_cap` **reentra no filtro** — com uma chamada por permissão
distinta consultada, isso vira N reentradas por requisição, e já causou
incidente em produção (esgotamento de memória, toda requisição autenticada).
`PermissionEngine` guarda o resultado de `$isAdmin` por `$userId` em memória
de instância; instância nova (nova requisição) resolve de novo.

Os dois cuidados são provados por **contagem de chamadas reais** (a um
`KeyValueStoreInterface` decorado e a um verificador de administrador
instrumentado), não por inspeção do desenho — inspecionar o desenho não
distingue um cache que existe de um que só parece existir.

## 8. Nada de estado estático

`RoleMatrix` e `PermissionEngine` são instâncias, injetáveis, testáveis sem
WordPress carregado — como o resto da biblioteca. O bypass de administrador
é uma função `function( int $userId ): bool` injetada no construtor (mesmo
padrão de `Licensing\CapabilityGate`/`Admin\Nav\CallableScreenAccess`), nunca
uma chamada direta a `user_can()` dentro da classe.

## 9. Ligação com a navegação (`Admin\Nav\`)

`PermissionEngine::userCan()` já tem a forma `function( string $permission ):
bool` que `Admin\Nav\Navigation` aceita desde a 0.18.0
(`docs/navegacao-do-painel.md` §3). `PermissionEngine::asScreenAccess( int
$userId )` fecha essa forma em uma linha:

```php
$navigation = new Navigation( $registry, $engine->asScreenAccess( $userId ) );
```

Provado em `tests/Roles/PermissionEngineNavigationTest.php`: monta a
navegação com o motor e confere que a árvore filtrada é a certa para cada
papel — e que o administrador vê tudo mesmo sem papel atribuído.

## 10. Permissões sensíveis

O produto declara, no construtor de `PermissionEngine`, quais permissões são
sensíveis (ex.: `dsar.erase`, `incidents.notify` no V3RLGPD). Elas vivem no
papel como qualquer outra permissão — `userCan()` não trata diferente —, mas
`PermissionEngine::sensitivePermissions()` expõe essa lista separadamente,
para a tela de edição de papel (fora desta biblioteca, §2) não oferecê-las
ao cliente por padrão.
