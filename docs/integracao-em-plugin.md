# Integração do v3r-core num plugin hospedeiro

Receita testada — validada executando de ponta a ponta contra um plugin de
teste descartável, com um zip real aberto e inspecionado. Não é suposição.

> **Pré-condição que muda o comportamento descrito aqui:** desde a versão
> `v0.2.0`, o v3r-core **não se auto-prefixa** — nem a si mesmo nem ao
> `plugin-update-checker`. Todo o código desta lib usa o namespace `V3R\Core\`
> puro e referencia o `plugin-update-checker` pelo namespace **original**
> (`YahnisElsts\PluginUpdateChecker\...`), sem prefixo próprio algum. Quem
> prefixa v3r-core — e as dependências transitivas dele — é **sempre** o
> plugin hospedeiro, numa única passada do Strauss. Ver §5 para o porquê:
> versões anteriores (`v0.1.0`) embutiam uma auto-prefixação interna que
> quebra exatamente nesse cenário de reprefixação em dois níveis.

## 1. Pré-requisitos

- PHP ≥ 7.4 e Composer 2.x no ambiente que gera o pacote (dev e CI).
- `GH_TOKEN` da casa (V3RTECH) exportado no ambiente — o repositório
  `V3RTECH-DF/V3RCore-Code` é **privado**. Nunca imprima o token; carregue-o
  via `.envrc` do repositório (`set -a; source .envrc; set +a`).
- `brianhenryie/strauss` como `require-dev` do **plugin hospedeiro** (não do
  v3r-core — ele não usa mais Strauss).

## 2. Declaração da dependência

### 2.1 Arranjo escolhido: VCS repository + tag semver

```json
{
    "require": {
        "v3rtech/v3r-core": "^0.2.0"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/V3RTECH-DF/V3RCore-Code.git"
        }
    ]
}
```

Autenticação (uma vez por máquina/CI, não vai no `composer.json`):

```bash
composer config -g github-oauth.github.com "$GH_TOKEN"
```

**Por quê VCS + tag, e não `path`:** `path` repository só funciona com os
dois repositórios lado a lado no disco — verdadeiro nas máquinas de
desenvolvimento, falso em CI e no ambiente de quem só clona o plugin. VCS com
autenticação por token funciona nos dois. Testado numa "máquina limpa"
simulada (sem cache prévio do pacote, autenticação só pelo token de
ambiente) — `composer install` resolveu, baixou e instalou
`v3rtech/v3r-core` corretamente por HTTPS autenticado.

**Versionamento:** o repositório não tinha nenhuma tag até este trabalho.
Sem tag, `composer require v3rtech/v3r-core` sem versão só resolve
`dev-main`, e o empacotamento de todo plugin passaria a depender do estado
corrente da branch — qualquer commit em `main` (inclusive um em progresso)
afetaria builds de produção de outro plugin. **Criada a tag `v0.2.0`**
(semver; a `main` está funcional e com 128 testes verdes, mas `v0.2.0` e não
`v0.1.0` porque esta tag já inclui a correção da auto-prefixação de §5 —
`v0.1.0` existe no histórico e tem o defeito). Consumidores fixam por
`^0.2.0` (aceita correções de patch/minor, nunca um major que quebre
compatibilidade).

### 2.2 Trabalhando localmente na lib e no plugin ao mesmo tempo

Publicar uma tag a cada alteração da lib, só para testar no plugin, é
inviável no dia a dia. Para desenvolvimento local com os dois repositórios
lado a lado (`V3RTECH/V3RCore/Code` e `V3RTECH/<Plugin>/Code`, convenção do
container):

1. Adicione **temporariamente**, no topo do array `repositories` do plugin
   (antes do `vcs`, pois o Composer respeita a ordem), um repositório `path`:

   ```json
   {
       "type": "path",
       "url": "../../V3RCore/Code",
       "options": { "symlink": true }
   }
   ```

2. Rode `composer require v3rtech/v3r-core:@dev` (ou o nome do branch,
   `dev-minha-branch`) — com `symlink: true`, o Composer cria um link
   simbólico para o checkout local; qualquer alteração no `src/` da lib
   aparece no próximo `composer dump-autoload`/`composer prefix` do plugin,
   sem reinstalar nada.
3. **Nunca comite esse bloco `path`** — ele só existe na máquina do
   desenvolvedor. Antes de commitar, restaure o `composer.json` do plugin
   para o estado com só o repositório `vcs`, rode `composer update
   v3rtech/v3r-core` para voltar à versão travada, e confira `git diff`.

## 3. Bloco `extra.strauss` do plugin hospedeiro

```json
{
    "require-dev": {
        "brianhenryie/strauss": "^0.19"
    },
    "autoload": {
        "classmap": ["vendor-prefixed/"]
    },
    "scripts": {
        "strauss": "strauss",
        "prefix": ["@strauss", "composer dump-autoload"],
        "post-install-cmd": ["@prefix"],
        "post-update-cmd": ["@prefix"]
    },
    "extra": {
        "strauss": {
            "target_directory": "vendor-prefixed/",
            "namespace_prefix": "MeuPlugin\\Vendor\\",
            "classmap_prefix": "MeuPlugin_Vendor_",
            "constant_prefix": "MEUPLUGIN_VENDOR_",
            "packages": [
                "v3rtech/v3r-core"
            ],
            "override_autoload": {
                "yahnis-elsts/plugin-update-checker": {
                    "classmap": ["Puc/"],
                    "files": ["load-v5p7.php"]
                }
            },
            "delete_vendor_packages": true,
            "include_modified_date": false,
            "include_author": false
        }
    }
}
```

Ajuste por plugin: `MeuPlugin\Vendor\`, `MeuPlugin_Vendor_` e
`MEUPLUGIN_VENDOR_` (namespace/prefixos únicos do plugin — é isso que evita
a colisão entre dois plugins com versões diferentes de v3r-core no mesmo
WordPress). O resto do bloco é **genérico e idêntico em qualquer plugin**.

**Duas peças não-óbvias, ambas obrigatórias:**

- **`override_autoload` para `yahnis-elsts/plugin-update-checker` é
  obrigatório**, mesmo você não requerendo esse pacote diretamente (ele
  chega como dependência transitiva de v3r-core). O `plugin-update-checker`
  declara, no próprio `composer.json`, só `"files": ["load-v5p7.php"]` — as
  classes reais (`Puc/v5p7/...`) são carregadas por um autoloader dinâmico
  próprio, invisível para o Composer/Strauss. Sem este override, o Strauss
  copia e prefixa só `load-v5p7.php`, que faz `require __DIR__ .
  '/Puc/v5p7/Autoloader.php'` para um arquivo que nunca foi copiado — fatal
  error na primeira chamada que toque o updater. **Este bloco é o mesmo que
  já existe em `extra.strauss` do próprio `composer.json` do v3r-core** —
  copie-o de lá, não invente um novo.
- **`vendor-prefixed/` precisa existir no disco antes do primeiro `composer
  install`**, com um `.gitkeep` versionado. O `composer.json` do plugin já
  referencia `vendor-prefixed/` no `autoload.classmap` (necessário para o
  Strauss usar o classmap do próprio Composer em vez de gerar o dele); numa
  máquina/CI limpa, sem essa pasta, o `composer install` falha ao gerar o
  autoload **antes mesmo de o Strauss rodar** (`Could not scan for classes
  inside "vendor-prefixed/" which does not appear to be a file nor a
  folder`). Adicione ao `.gitignore` do plugin:
  ```
  vendor-prefixed/*
  !vendor-prefixed/.gitkeep
  ```

## 4. Verificação após `composer install`

```bash
php -r '
require "vendor/autoload.php";
var_dump(class_exists("V3R\\Core\\Bootstrap"));                                    // false
var_dump(class_exists("MeuPlugin\\Vendor\\V3R\\Core\\Bootstrap"));                 // true
var_dump(class_exists("MeuPlugin\\Vendor\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory")); // true
'
```

O namespace original (`V3R\Core\...`, `YahnisElsts\...`) precisa responder
`false`; o prefixado do plugin precisa responder `true`. Inspeção de arquivo
não basta — foi assim que um defeito equivalente passou despercebido antes
(comentário do `Bootstrap.php`/CI antigo).

## 5. O passo de prefixação no empacotamento (`build-zip.sh`)

Snippet a inserir no script de build do plugin, **depois** de gerar o
`vendor/` (autoloader de produção) e **antes** de zipar. Convive com os
guards locais que cada plugin já tem (guard de classmap do V3RLGPD,
regeneração do autoloader contra o layout achatado, bundle de frontend —
nenhum deles precisa mudar).

```bash
echo "=== Strauss: prefixando v3r-core e dependências transitivas ==="
composer prefix --no-interaction

# Falha o build se a lib não estiver de fato no pacote — hoje o build
# passava mesmo com o zip errado; isto é o que evita isso.
if [ ! -f "vendor-prefixed/v3rtech/v3r-core/src/Bootstrap.php" ]; then
  echo "ERRO: v3r-core não encontrado em vendor-prefixed/ — Strauss não rodou ou falhou." >&2
  exit 1
fi
php -r '
    require "vendor-prefixed/autoload.php";
    if (!class_exists("'"$NAMESPACE_PREFIX"'\\V3R\\Core\\Bootstrap")) {
        fwrite(STDERR, "ERRO: classe prefixada do v3r-core não resolve — build incompleto.\n");
        exit(1);
    }
' 2>&1 || exit 1
echo "v3r-core prefixado e carregável — OK."

cp -r vendor-prefixed "$TEMP_DIR/"
```

**O que é genérico:** o `composer prefix`, a checagem de arquivo em
`vendor-prefixed/v3rtech/v3r-core/src/Bootstrap.php` (caminho idêntico em
qualquer plugin — é sempre o mesmo pacote), e o `cp -r vendor-prefixed`.

**O que cada plugin ajusta:** `$NAMESPACE_PREFIX` (o mesmo valor de
`namespace_prefix` do `extra.strauss` do próprio plugin, sem as barras
finais) e o `$TEMP_DIR` (convenção já existente em cada `build-zip.sh`).

Se o plugin ainda **não** usa Strauss (caso do V3RLGPD hoje), este é o
primeiro pacote a introduzi-lo — os passos 1–4 deste documento vêm antes
deste, uma única vez.

## 6. O que quebrou durante o teste, e como se contorna

**Quebrou, e a causa raiz era do v3r-core, não do Strauss.**

Strauss lida bem com dependências transitivas normais (confirmado:
`yahnis-elsts/plugin-update-checker`, como dependência declarada de
v3r-core, é descoberto e reprocessado automaticamente pelo Strauss do
hospedeiro sem precisar listá-lo em `packages`). O que **não** funciona é a
combinação de duas coisas que a `v0.1.0` do v3r-core fazia ao mesmo tempo:

1. O v3r-core rodava seu **próprio** Strauss (`post-install-cmd`), gerando
   `vendor-prefixed/` com o `plugin-update-checker` prefixado sob
   `V3R\Core\Vendor\YahnisElsts\...`.
2. O código-fonte do v3r-core (`PucBridge.php`, `use`) referenciava
   diretamente esse namespace já pré-prefixado.

Isso funciona perfeitamente quando o v3r-core é desenvolvido/testado
sozinho (seu próprio `composer install` gera `vendor-prefixed/` de verdade).
**Mas quebra de duas formas diferentes quando v3r-core vira dependência de
outro projeto:**

- **Sozinho (sem o hospedeiro rodar Strauss):** `vendor-prefixed/` é
  `.gitignore`d — não viaja na tag/zip do pacote Composor. Instalado como
  dependência, essa pasta chega **sempre vazia**. O classmap declarado no
  `composer.json` do v3r-core para `vendor-prefixed/` nunca resolve nada em
  produção; é código morto desde sempre para quem consome via Composer.
- **Com o hospedeiro rodando Strauss (o cenário real de distribuição):** o
  Strauss do hospedeiro processa o `src/` de v3r-core (prefixando `V3R\Core`
  → `MeuPlugin\Vendor\V3R\Core`) **e**, separadamente, a dependência
  transitiva `yahnis-elsts/plugin-update-checker` na sua forma **original**
  (baixada fresca do Packagist, porque a cópia pré-prefixada do v3r-core não
  existe no pacote) — prefixando-a para `MeuPlugin\Vendor\YahnisElsts\...`
  (sem o segmento `V3R\Core\Vendor\` no meio, porque o Strauss só prefixa o
  que encontra literalmente declarado em cada arquivo, e o arquivo real da
  lib de terceiro declara `namespace YahnisElsts\PluginUpdateChecker\...`,
  não o namespace pré-prefixado). O `use` statement de `PucBridge.php`,
  textualmente `V3R\Core\Vendor\YahnisElsts\...`, vira
  `MeuPlugin\Vendor\V3R\Core\Vendor\YahnisElsts\...` — uma classe que **não
  existe em lugar nenhum**. Fatal error na primeira chamada ao updater.

Foi cogitada (e testada) uma alternativa que preservaria a auto-prefixação
interna do v3r-core: versionar o próprio `vendor-prefixed/` gerado pelo
v3r-core (parar de ignorá-lo no git) e excluir
`yahnis-elsts/plugin-update-checker` da cópia do Strauss do hospedeiro
(`exclude_from_copy`), usando só a cópia pré-embutida. **Essa alternativa
FOI DESCARTADA:** o `plugin-update-checker` continua sendo instalado pelo
Composer do hospedeiro (é uma dependência declarada de v3r-core,
independente de o Strauss copiá-lo ou não) na sua forma **original, sem
nenhum prefixo**, autoloaded via `files` (o `autoload.files` do Composer
sempre executa, mesmo sem classmap apontando para lá). Se **dois** plugins
da casa fizerem isso no mesmo WordPress, os dois carregam uma cópia
idêntica, sem prefixo, do `plugin-update-checker` — exatamente a colisão
`Cannot redeclare class` que o Strauss existe para evitar. Pior que o
problema original.

**A correção adotada (aplicada nesta sessão, v0.2.0):** o v3r-core deixou de
se auto-prefixar. `PucBridge.php` e `UpdateChecker.php` agora referenciam o
namespace **original** do `plugin-update-checker`. Toda a prefixação — de
v3r-core e de suas dependências transitivas — acontece numa única passada,
no hospedeiro, exatamente como o pacote `mpdf`/`openspout` já funciona hoje
em `RIT360/Solidario` (nenhuma dessas libs se auto-prefixa; quem prefixa é
sempre o consumidor final). Isso elimina o namespace aninhado por
construção: não existe mais nenhum ponto do código que referencie um
namespace pré-prefixado que o hospedeiro precise "descascar".

Removidos do v3r-core como parte desta correção: `extra.strauss` do próprio
`composer.json`, `tools/strauss.php`, `brianhenryie/strauss` de
`require-dev`, o passo de CI que validava a auto-prefixação (não existe
mais o que validar), e o `classmap: vendor-prefixed/` do autoload (nunca
resolvia nada em produção, como descrito acima).

**Limitação conhecida:** `vendor-prefixed/.gitkeep` continua rastreado no
repositório do v3r-core, vestigial (inofensivo — o diretório simplesmente
fica vazio e sem uso; não há mais nenhuma referência a ele no
`composer.json`). Não removido nesta sessão por não valer o risco de mexer
em histórico de arquivo rastreado fora do escopo estrito da correção.

## 7. Trecho de bootstrap (assinatura real)

```php
<?php
declare(strict_types=1);

use MeuPlugin\Vendor\V3R\Core\Bootstrap;

require_once __DIR__ . '/vendor-prefixed/autoload.php';

add_action( 'plugins_loaded', function () {
    $v3rCore = new Bootstrap(
        'meuplugin',                                          // product_slug no servidor de licenças
        __FILE__,                                              // arquivo principal do plugin
        'https://licencas.v3rtech.com.br/wp-json/v3r-license/v1',
        'CHAVE_PUBLICA_ED25519_BASE64_DO_SERVIDOR',            // não é segredo — docs/api-contract.md §4
        '1.0.0',                                                // versão instalada do plugin (semver)
        'meuplugin_settings_view',                              // capability de leitura — ver nota abaixo
        'meuplugin_settings_manage'                             // capability de gestão — ver nota abaixo
    );

    $v3rCore->boot(); // updater + 4 endpoints REST internos (docs/api-contract.md §8)
} );
```

`Bootstrap::__construct(string $productSlug, string $pluginFile, string
$apiBaseUrl, string $publicKey, string $pluginVersion, string $readCapability
= Bootstrap::DEFAULT_CAPABILITY, ?string $manageCapability = null)` — capability
**por operação** desde a issue #9 (docs/api-contract.md §8.2): o sexto
argumento (`$readCapability`) autoriza `GET .../license` e
`POST .../license/refresh`; o sétimo, opcional (`$manageCapability`),
autoriza `POST .../license/activate` e `POST .../license/deactivate` — a
dupla que mexe no estado da licença e libera a cota do domínio no
servidor. Omitindo o sétimo, ele cai para o valor do sexto (mesmo
comportamento de antes da issue #9, com uma capability só).

**Quando o plugin já tiver um RBAC próprio** (papéis/capabilities
data-driven do próprio produto), as duas capabilities aqui costumam ser
**sintéticas** — pontes criadas pelo próprio plugin via filtro
`user_has_cap`, uma por nível de permissão que já existe no RBAC (ex.:
`settings.view`/`settings.manage`), não capabilities nativas do
WordPress. Nunca fixe `manage_options` nas duas nem invente uma
capability nova só para o v3r-core; use as que já existem no plugin para
a mesma responsabilidade (consultar licença / gerir licença).
`manage_options` continua sendo aceitável só para o plugin que não tem
RBAC nenhum — e mesmo assim, larga demais é só um dos jeitos de errar:
estreita demais exclui quem administra o plugin sem ser administrador do
site.
