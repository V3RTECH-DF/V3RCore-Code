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

## Três divergências achadas na execução real (V3RLGPD, 26/08/2026)

Esta receita foi escrita e testada contra um plugin de teste descartável.
A sessão do V3RLGPD a executou pela primeira vez contra um plugin **real**,
com um `build-zip.sh` de verdade — e achou três divergências que a versão
anterior deste documento não previa. Todas já estão corrigidas no texto
abaixo; ficam resumidas aqui para quem só quer saber o que mudou:

1. **`v3rtech/v3r-core` vai em `require-dev`, não em `require`** (§2.1) — em
   `require`, a lib sobrevive ao `composer install --no-dev` do
   `build-zip.sh` e é reinstalada **sem prefixo** dentro do próprio pacote.
2. **Strauss é `.phar` standalone, não dependência do Composer** (§3) —
   como dependência, `vendor/bin/strauss` falha (`Class "Composer\Factory"
   not found`); e a mudança tira os hooks `post-install-cmd`/
   `post-update-cmd` — a prefixação vira passo explícito.
3. **`delete_vendor_packages` morde no segundo build** (§5) — sem
   reinstalar antes, o segundo `composer prefix` "sucede" sem copiar nada.

Consequência que atravessa as três: um `composer install` limpo já não
deixa `vendor-prefixed/` pronto sozinho (§3, §4) — envolver toda chamada ao
v3r-core em `class_exists()` deixou de ser detalhe de implementação e virou
requisito da receita (§7).

## 1. Pré-requisitos

- PHP ≥ 7.4 e Composer 2.x no ambiente que gera o pacote (dev e CI).
- `GH_TOKEN` da casa (V3RTECH) exportado no ambiente — o repositório
  `V3RTECH-DF/V3RCore-Code` é **privado**. Nunca imprima o token; carregue-o
  via `.envrc` do repositório (`set -a; source .envrc; set +a`).
- Strauss **como binário `.phar` standalone** (0.19.5), não como dependência
  do Composer do plugin hospedeiro — ver §3 para o porquê e como cachear.

## 2. Declaração da dependência

### 2.1 Arranjo escolhido: VCS repository + tag semver

```json
{
    "require-dev": {
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

> **`require-dev`, não `require` — achado da execução real (V3RLGPD,
> 26/08/2026).** A versão anterior desta receita mandava `require`, e isso
> produz um pacote errado. O `build-zip.sh` da casa tem um passo de
> "autoloader de produção" que roda `composer install --no-dev` fresco,
> com `COMPOSER_VENDOR_DIR` apontando para dentro do pacote. `require`
> **sobrevive** a esse `--no-dev`; `require-dev` não. Com `require`, a lib
> é reinstalada **sem prefixo** dentro do próprio pacote, e o zip final sai
> com **as duas** classes — a prefixada e a original —, exatamente o estado
> que colide entre dois plugins da casa no mesmo WordPress.
>
> A biblioteca nunca precisa ser carregada sem prefixo em runtime — ela só
> existe como matéria-prima para o Strauss. O que vai para produção é
> `vendor-prefixed/` (§5); a cópia crua em `vendor/v3rtech/v3r-core` não
> deveria sobreviver ao empacotamento, e com `require-dev` ela não
> sobrevive.
>
> **Foi o guard de duas pontas do §5 que pegou isso.** Na primeira execução
> real, com `require`, ele falhou reportando "o namespace ORIGINAL do
> v3r-core ainda resolve" — a ponta que verifica que `V3R\Core\Bootstrap`
> **não pode** existir sem prefixo. Um guard mais antigo — que só checava
> se a classe *prefixada* resolvia — teria aprovado esse mesmo pacote
> quebrado, porque a classe prefixada também estava lá; só que a original
> estava junto.

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

2. Rode `composer require v3rtech/v3r-core:@dev --dev` (ou o nome do
   branch, `dev-minha-branch` — mantendo a flag `--dev`, ver §2.1) — com
   `symlink: true`, o Composer cria um link
   simbólico para o checkout local; qualquer alteração no `src/` da lib
   aparece no próximo `composer dump-autoload`/`composer prefix` do plugin,
   sem reinstalar nada.
3. **Nunca comite esse bloco `path`** — ele só existe na máquina do
   desenvolvedor. Antes de commitar, restaure o `composer.json` do plugin
   para o estado com só o repositório `vcs`, rode `composer update
   v3rtech/v3r-core` para voltar à versão travada, e confira `git diff`.

## 3. Bloco `extra.strauss` do plugin hospedeiro, e o Strauss como `.phar`

> **Strauss precisa ser `.phar` standalone, não dependência de Composer —
> achado da execução real (V3RLGPD, 26/08/2026).** `vendor/bin/strauss`
> falha com `Class "Composer\Factory" not found`, reproduzível no Composer
> 2.10.2. **Não é bug do Strauss nem configuração errada:** quando
> `composer/composer` entra como dependência (transitiva de
> `brianhenryie/strauss`) de outro projeto, o próprio Composer descarta o
> `autoload` do pacote — `vendor/composer/installed.json` registra
> `"autoload": []`. É proteção do Composer contra conflito com o runtime do
> Composer que está executando o `install`.
>
> O contorno é o `.phar` standalone (0.19.5), cacheado fora do controle de
> versão (o V3RLGPD usou `.tooling/`):
>
> ```bash
> mkdir -p .tooling
> curl -L -o .tooling/strauss.phar \
>   https://github.com/BrianHenryIE/strauss/releases/download/0.19.5/strauss.phar
> echo '.tooling/' >> .gitignore
> ```
>
> Invocado sempre como `php .tooling/strauss.phar compose` — nunca
> `strauss` nem `vendor/bin/strauss`.
>
> **Consequência que muda os `scripts` abaixo: os hooks
> `post-install-cmd`/`post-update-cmd` saem.** O `.phar` é cacheado fora do
> Composer — um clone novo não o tem em disco até alguém baixá-lo. Um hook
> automático chamando `composer run prefix` quebraria o primeiro `composer
> install` de qualquer desenvolvedor que ainda não tenha
> `.tooling/strauss.phar`. A prefixação vira passo **explícito** —
> `composer run prefix`, manual em desenvolvimento e automático dentro do
> `build-zip.sh` (§5, que já garante o `.phar` em cache antes de chamá-lo).
>
> **O resumo rápido do `README.md` da lib também mudou junto com este
> documento** — ele mostrava só `composer require` + `composer require
> brianhenryie/strauss --dev`, dando a entender que a prefixação acontecia
> sozinha (era verdade enquanto existiam os hooks). Agora inclui o
> `composer run prefix` explícito e a ressalva do `.phar`.

```json
{
    "autoload": {
        "classmap": ["vendor-prefixed/"]
    },
    "scripts": {
        "prefix": ["php .tooling/strauss.phar compose", "composer dump-autoload"]
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

**Três peças não-óbvias, todas obrigatórias:**

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
- **Sem os hooks (§3), `vendor-prefixed/` fica vazio logo depois de um
  `composer install` limpo** — a prefixação agora é passo explícito
  (`composer run prefix`), não mais automática. É estado normal e
  esperado num clone recém-baixado, não um defeito. É por isso que todo
  código do plugin que chame o v3r-core precisa estar protegido por
  `class_exists()` — ver §7.

## 4. Verificação após instalar e prefixar

**Rode a prefixação explicitamente antes de checar** — sem os hooks (§3),
um `composer install` sozinho não gera `vendor-prefixed/`:

```bash
composer install
composer run prefix
php -r '
require "vendor-prefixed/autoload.php";
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
`vendor/` (autoloader de produção) e — atenção — **antes do
`dump-autoload` do pacote, quando houver um**.

> **A ordem importa mais do que "antes de zipar".** Plugin que monta um
> pacote com layout achatado (`src/includes/` vira `includes/`) reescreve
> o `composer.json` dentro do diretório temporário e roda
> `composer dump-autoload` **lá dentro**. No instante em que esse
> `composer.json` declarar `autoload.classmap: ["vendor-prefixed/"]`, o
> dump procura a pasta **relativa ao diretório temporário** — e falha com
> o mesmo erro do §3 (`Could not scan for classes inside`), agora no meio
> do build. Nesse caso o `cp -r vendor-prefixed` precisa acontecer
> **antes** do dump. Levantado pela sessão do V3RLGPD ao executar a
> receita, e vale para qualquer plugin da casa que ache o layout.

> **`delete_vendor_packages` morde no segundo build — achado da execução
> real (V3RLGPD, 26/08/2026).** `delete_vendor_packages: true` (bloco
> `extra.strauss`, §3) apaga o pacote-fonte de `vendor/` depois da primeira
> prefixação. Um segundo `composer prefix` **sem reinstalar antes** encontra
> `vendor/v3rtech` já vazio e "sucede" sem copiar nada — `vendor-prefixed/`
> sai vazio **em silêncio**, sem erro nenhum (o guard abaixo ainda pega
> isso, mas só se rodar depois). É falha que só aparece no segundo build
> em diante, então passa despercebida em teste feito uma vez só. Por isso
> o `composer install` roda de novo, logo antes de cada `composer prefix`,
> dentro do próprio `build-zip.sh` — nunca assuma que o `vendor/` de uma
> execução anterior ainda tem a lib.

```bash
echo "=== Strauss: garantindo vendor/ fresco antes de prefixar ==="
composer install --no-interaction

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
    $prefixo = "'"$NAMESPACE_PREFIX"'";
    $erros   = array();

    // As DUAS pontas. Verificar só que a classe prefixada resolve não
    // distingue "bem configurado" de "mal configurado com as duas
    // presentes" — e é justamente esse segundo estado que colide com o
    // próximo plugin da casa que embutir a lib.
    if ( ! class_exists( $prefixo . "\\V3R\\Core\\Bootstrap" ) ) {
        $erros[] = "a classe prefixada do v3r-core não resolve";
    }
    if ( class_exists( "V3R\\Core\\Bootstrap" ) ) {
        $erros[] = "o namespace ORIGINAL do v3r-core ainda resolve";
    }
    // O plugin-update-checker é a dependência que efetivamente colide:
    // ele se autoloada por `files`, então sobra no namespace global se o
    // Strauss não o alcançar.
    if ( ! class_exists( $prefixo . "\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory" ) ) {
        $erros[] = "o plugin-update-checker prefixado não resolve";
    }
    if ( class_exists( "YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory" ) ) {
        $erros[] = "o namespace ORIGINAL do plugin-update-checker ainda resolve";
    }

    if ( $erros ) {
        fwrite( STDERR, "ERRO de prefixação — build incompleto:\n  - " . implode( "\n  - ", $erros ) . "\n" );
        exit( 1 );
    }
' 2>&1 || exit 1
echo "v3r-core e dependências prefixados, namespaces originais ausentes — OK."

cp -r vendor-prefixed "$TEMP_DIR/"

# Autoloader de produção — SEMPRE depois da cópia acima (ver §3.1: é este
# --no-dev que descarta v3rtech/v3r-core do pacote final, porque a lib é
# require-dev). Copiar depois do dump gera classmap sem as classes da
# biblioteca, com o build passando igual — a ordem abaixo é a confirmada
# em execução real (V3RLGPD, 26/08/2026).
(
  cd "$TEMP_DIR"
  composer install --no-dev --no-interaction
  composer dump-autoload --no-interaction
)
```

**O que é genérico:** o `composer install` de garantia, o `composer prefix`,
a checagem de arquivo em `vendor-prefixed/v3rtech/v3r-core/src/Bootstrap.php`
(caminho idêntico em qualquer plugin — é sempre o mesmo pacote), o
`cp -r vendor-prefixed` e o `composer install --no-dev` + `dump-autoload`
finais dentro do `$TEMP_DIR`.

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

> **O exemplo abaixo é defensivo por construção — não por zelo.** Sem os
> hooks `post-install-cmd`/`post-update-cmd` (§3), o estado "lib instalada
> mas ainda não prefixada" é **normal e esperado** logo depois de um clone
> recém-baixado ou de um `composer install` limpo — `vendor-prefixed/`
> fica vazio até alguém rodar `composer run prefix` explicitamente (§4).
> Um `require_once` incondicional de `vendor-prefixed/autoload.php` nesse
> estado é fatal error na ativação do plugin: o arquivo não existe. O
> `class_exists()` em volta de toda chamada ao v3r-core deixou de ser
> detalhe de implementação e virou **requisito** desta receita (achado da
> execução real, V3RLGPD, 26/08/2026) — copie o padrão abaixo tal como
> está, sem tirar as checagens.
>
> No V3RLGPD isso não quebrou por acaso: o arranque do plugin já era uma
> sequência de `class_exists()`, mas **por outro motivo** — lá o Composer
> sempre foi opcional em runtime, decisão anterior e independente desta
> receita. Foi sorte de arquitetura, não previsão; um plugin novo que
> copie só o `require_once` sem as duas checagens abaixo quebra na
> primeira ativação após `composer install`.

```php
<?php
declare(strict_types=1);

use MeuPlugin\Vendor\V3R\Core\Bootstrap;

$v3rCoreAutoload = __DIR__ . '/vendor-prefixed/autoload.php';
if ( file_exists( $v3rCoreAutoload ) ) {
    require_once $v3rCoreAutoload;
}

add_action( 'plugins_loaded', function () {
    // vendor-prefixed/ pode não existir ainda (composer install sem
    // "composer run prefix" depois — §3/§4). Nesse estado o plugin
    // carrega normalmente e o licenciamento simplesmente não liga; nunca
    // fatal error, nunca ativação abortada.
    if ( ! class_exists( Bootstrap::class ) ) {
        return;
    }

    // Resolve o par URL + chave pública a partir das constantes
    // V3R_LICENSE_API_URL / V3R_LICENSE_PUBLIC_KEY — nunca lendo cada uma
    // separadamente em pontos diferentes do código. Ver §8.
    $licenseConfig = meuplugin_resolve_v3r_license_config();
    if ( null === $licenseConfig ) {
        return; // par incoerente ou parcialmente definido — já logado, não ativa
    }

    $v3rCore = new Bootstrap(
        'meuplugin',                                          // product_slug no servidor de licenças
        __FILE__,                                              // arquivo principal do plugin
        $licenseConfig['api_url'],
        $licenseConfig['public_key'],
        '1.0.0',                                                // versão instalada do plugin (semver)
        'meuplugin_settings_view',                              // capability de leitura — ver nota abaixo
        'meuplugin_settings_manage'                             // capability de gestão — ver nota abaixo
    );

    // A biblioteca concede as duas capabilities sozinha via user_has_cap
    // (V3RCore-Code#12) — o plugin NUNCA registra esse filtro. $decider só
    // é chamado quando a pergunta já é sobre uma das duas capabilities
    // acima; dentro dele, chame user_can()/current_user_can() à vontade —
    // não cria recursão, a guarda de saída antecipada é da biblioteca.
    // Ver §7.2.
    $v3rCore->withCapabilityDecider( function ( int $userId, string $capability ): bool {
        return MeuPlugin\Rbac::userCan( $userId, $capability );
    } );

    // Nome de exibição do produto (V3RCore-Code#11) — usado no rótulo do
    // menu e no título da tela padrão ("Licença do MeuPlugin"), para não
    // colidir visualmente com a mesma tela de outro plugin da casa no
    // mesmo site. Opcional: sem chamar, a tela cai para o productSlug
    // ('meuplugin') — identifica pior, mas nunca some. Só tem efeito em
    // quem usa createAdminPage() (§7.2) — plugin com aba própria não
    // precisa chamar.
    $v3rCore->withProductName( 'MeuPlugin' );

    $v3rCore->boot(); // grant das capabilities + updater + 4 endpoints REST internos (docs/api-contract.md §8)
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
**sintéticas** — não capabilities nativas do WordPress, mas pontes para
um nível de permissão que já existe no RBAC (ex.:
`settings.view`/`settings.manage`). Nunca fixe `manage_options` nas duas
nem invente uma capability nova só para o v3r-core; use as que já existem
no plugin para a mesma responsabilidade (consultar licença / gerir
licença). `manage_options` continua sendo aceitável só para o plugin que
não tem RBAC nenhum — e mesmo assim, larga demais é só um dos jeitos de
errar: estreita demais exclui quem administra o plugin sem ser
administrador do site.

## 7.1 Quem concede as duas capabilities é a biblioteca, não o plugin (V3RCore-Code#12)

**Até a v0.3.1**, cada plugin hospedeiro precisava registrar o próprio
filtro `user_has_cap` para conceder as duas capabilities de licença — e
precisava lembrar de fazer esse filtro **sair cedo** quando as
capabilities pedidas na chamada corrente não eram as de licença. Esquecer
essa saída antecipada fecha o ciclo `user_has_cap → user_can →
user_has_cap`: infinito, e derruba **toda requisição de usuário logado**
por memória esgotada — inclusive `wp-login.php`. Foi o que aconteceu de
verdade em produção (`V3RLGPD-Code#74`).

**Desde a v0.4.0, isso não é mais tarefa do plugin.** `Bootstrap` registra
o filtro `user_has_cap` sozinho, com a guarda embutida e inescapável — ela
roda **antes** de chamar `$decider`, não depois. `$decider` só é invocado
quando a capability pedida já é a de leitura ou a de gestão desta licença;
para qualquer outra (`manage_options` incluída — a que o RBAC do plugin
tipicamente consulta por dentro do próprio `$decider`), o filtro devolve
`$allcaps` sem tocar em `$decider`. É essa saída antecipada, e não o
código dentro de `$decider`, que impede a recursão — por isso é seguro
chamar `user_can()`/`current_user_can()` dentro dele.

`Bootstrap::withCapabilityDecider(callable $decider)` — assinatura de
`$decider`: `function( int $userId, string $capability ): bool`. Chame
antes de `boot()`, sempre: `boot()` lança `\LogicException` se
`withCapabilityDecider()` não foi chamado — erro alto e imediato, de
propósito, para nunca cair no caminho silencioso de "a capability
simplesmente não é concedida e a tela de licença só não aparece".

> **Chamar o `LicenseManager` fora do endpoint REST exige `try/catch` —
> achado da execução real (V3RLGPD, 27/08/2026).** No fluxo normal, o
> controller REST captura a `ApiException` e devolve o código do contrato
> (por exemplo `404` para chave inexistente). Quem chamar o
> `LicenseManager` **direto**, fora desse caminho — uma rotina de
> diagnóstico, um comando WP-CLI próprio, uma sonda —, recebe a exceção
> propagada e derruba a requisição com erro crítico do WordPress.
>
> Não é defeito no caminho que os clientes usam; é o contrato da camada.
> Mas a descoberta costuma acontecer do jeito mais caro, com a tela branca
> aparecendo em ambiente de quem estava só investigando.

## 7.2 A tela de licença não é opcional na prática — achado da execução real (V3REvent, 27/08/2026)

**Integração sem tela de licença é integração pela metade.** A receita
acima cobre `boot()` corretamente, mas chamar `boot()` sem registrar
nenhuma tela é um erro fácil de cometer porque nada nele falha: o plugin
ganha o updater e as quatro rotas REST internas, os testes passam, a
ativação funciona. O que falta só aparece depois — o cliente **não tem
onde informar a chave de licença**, e o `UpdateGate` recusa atualização
no estado `INACTIVE` ("nunca houve ativação") **sem período de graça**
(diferente do estado "ativa, mas sem contato recente com o servidor",
que tem os 14 dias do ADR-004/ADR-009 do `docs/ARCHITECTURE.md`). A
versão que introduz a auto-atualização é a mesma que a desliga, sem
saída pela interface.

A integração só está completa quando existe caminho de ativação na
interface — seja a tela padrão da biblioteca (`Licensing\AdminPage`,
opcional por ADR-005 do `docs/ARCHITECTURE.md`), seja uma aba própria do
plugin consumindo os quatro endpoints REST internos (o caminho escolhido
pelo V3REvent, que descartou a tela padrão por causa da issue #11 —
rótulo "Licença" sem identificar o produto, indistinguível num site com
mais de um plugin da casa; **corrigida na v0.5.0** — `Bootstrap::withProductName()`
nomeia o produto no rótulo e no título, ver §7).

## 8. Configuração de produção: URL e chave pública via constantes

> **Decisão de rollout, válida para os sete plugins clientes** (V3REvent,
> V3RHelp, V3RLGPD, V3RProp, GE Associados, RIT360 Solidário, RIT360
> Premiado). Registrada também como ADR-010 em `docs/ARCHITECTURE.md`.

**Um único par de constantes, com os mesmos nomes em todo plugin da casa:**

```php
define( 'V3R_LICENSE_API_URL', 'https://v3rtech.com.br/wp-json/v3r-license/v1' );
define( 'V3R_LICENSE_PUBLIC_KEY', 'CHAVE_PUBLICA_ED25519_BASE64_DO_SERVIDOR' );
```

Não são sete pares com prefixo por plugin (`MEUPLUGIN_LICENSE_API_URL` etc.).
Um mesmo site cliente pode ter vários plugins da casa instalados, e todos
falam com o mesmo servidor e conferem a mesma chave — sete pares seriam sete
cópias do mesmo valor a manter em sincronia, e a primeira que divergisse
falharia só na verificação de assinatura.

**A chave pública não é segredo e não vai por variável de ambiente** — é
constante mesmo, embutida no código do plugin. (Contraste: a chave
**privada**, do lado do servidor V3RLicense, vai por variável de ambiente —
issue V3RLicense-Code#11. As duas chaves têm exigências opostas; não seguem
a mesma regra por simetria de nome.)

**O par de produção é o default embutido no build do plugin.** O cliente
comum não edita `wp-config.php`; se o default fosse o par de
desenvolvimento, todo cliente precisaria configurar algo só para o plugin
funcionar. `wp-config.php` só entra em cena para *sobrescrever* o par —
tipicamente em ambiente de desenvolvimento, apontando para um servidor de
licenças local.

> **Regra do par — achado da execução real (V3RLGPD, 26/08/2026).** URL e
> chave vêm sempre da **mesma fonte**, e mudam **juntas**. Se uma das duas
> constantes estiver definida em `wp-config.php` e a outra não, o plugin
> **recusa e avisa** — nunca combina a constante de uma com o default da
> outra. URL de produção com chave de desenvolvimento (ou vice-versa) é um
> par incoerente que passa por qualquer guard de ambiente e só falha
> depois, na verificação de assinatura — sintoma bem mais difícil de
> diagnosticar do que "não iniciou".

> **O plugin NUNCA define `V3R_LICENSE_API_URL` nem `V3R_LICENSE_PUBLIC_KEY`
> — achado da execução real (V3RLGPD, 27/08/2026).** Esses dois nomes
> pertencem ao `wp-config.php` do site; a existência deles é o único sinal
> de que o dono do site sobrescreveu o par. Um plugin que faça
> `if ( ! defined( 'V3R_LICENSE_API_URL' ) ) define( ... );` no topo do
> arquivo principal — que é o padrão do WordPress, e o que a leitura
> natural dos `define()` acima sugere — faz as duas constantes existirem
> **sempre**, e o guard do par deixa de distinguir wp-config de default:
> a comparação vira `false !== false` e nunca dispara. Os defaults de
> produção moram em constantes **de nome próprio do plugin**, ou como
> literais na resolução — nunca sob os nomes compartilhados.
>
> **E o dano maior não é dentro de um plugin, é entre plugins.** O guard
> inerte é o estrago local. Num site com dois plugins da casa — que é o
> caso que a ADR-010 existe para suportar — o primeiro a carregar (ordem
> alfabética do WordPress, que ninguém controla) define os dois nomes
> compartilhados com o **seu** default; o segundo encontra as duas
> definidas, conclui que o dono do site sobrescreveu o par, e passa a
> falar com a URL do primeiro e a conferir a chave do primeiro. Em versões
> iguais ninguém percebe; em versões diferentes — o normal, porque
> atualizam em ritmos diferentes — a configuração de um plugin vaza para
> o outro e o guard não só deixa de guardar, ele **mente**. É o mesmo
> acoplamento silencioso entre plugins da casa que o Strauss resolve para
> namespaces (§3), aqui em constantes globais.

> **E o default não serve como sinal de "não configurado".** Comparar o
> valor com o default parece equivalente a perguntar de onde ele veio, e
> não é: em produção a URL **é** o default e continua sendo depois de a
> chave real existir. Com esse critério, o caso normal de produção — URL
> vinda do default, chave vinda do build — seria lido como par incoerente,
> e o licenciamento nunca ligaria, em silêncio. O que distingue as fontes
> é a existência da constante compartilhada, nada mais.

Implementação de referência, em duas partes. A decisão é uma **função
pura**, que recebe os valores em vez de ler constantes globais — é o que a
torna testável antes do dia da implantação, inclusive para o estado
*futuro* em que a chave de produção já existe:

```php
/**
 * Decide qual par usar. Função pura: não lê constante, não tem efeito.
 *
 * Regra do par: URL e chave vêm da MESMA fonte. Se o wp-config.php do site
 * trouxer só uma das duas, é configuração incoerente — recusa, em vez de
 * completar com o default de produção da outra.
 *
 * Devolve o motivo da recusa junto, porque os dois motivos merecem
 * tratamento diferente de quem chama (ver o adaptador abaixo).
 *
 * @param string|null $url_do_site   Valor vindo do wp-config.php, ou null.
 * @param string|null $chave_do_site Valor vindo do wp-config.php, ou null.
 * @param string      $url_padrao    Default de produção, embutido no build.
 * @param string      $chave_padrao  Default de produção, embutido no build.
 * @param string      $placeholder   Valor da chave enquanto ela não existir.
 *
 * @return array{status: string, api_url?: string, public_key?: string}
 *         status: 'ok' | 'incoerente' | 'chave_pendente'.
 */
function meuplugin_decide_v3r_license_config(
    ?string $url_do_site,
    ?string $chave_do_site,
    string $url_padrao,
    string $chave_padrao,
    string $placeholder
): array {
    $veio_url   = null !== $url_do_site && '' !== $url_do_site;
    $veio_chave = null !== $chave_do_site && '' !== $chave_do_site;

    if ( $veio_url !== $veio_chave ) {
        return array( 'status' => 'incoerente' );
    }

    if ( $veio_url ) {
        return array( 'status' => 'ok', 'api_url' => $url_do_site, 'public_key' => $chave_do_site );
    }

    if ( $placeholder === $chave_padrao ) {
        return array( 'status' => 'chave_pendente' );
    }

    return array( 'status' => 'ok', 'api_url' => $url_padrao, 'public_key' => $chave_padrao );
}
```

> **A chave pendente é caso da decisão, não do acaso — achado da execução
> real (V3RLGPD, 27/08/2026).** Enquanto a chave pública de produção não
> existir, o default do build é um placeholder, e esse é o caminho de
> **todos** os sete plugins em qualquer site que não configure nada. Sem o
> ramo `chave_pendente`, o plugin inicia e falha na verificação de
> assinatura de toda resposta — o modo de falha caro, em vez de "não
> iniciou". E o aviso no log sai **só** no par incoerente: registrar a
> chave pendente encheria o log de todo site sem configuração por um
> estado que hoje é o esperado.

A leitura das constantes fica **fora** da decisão, num adaptador fino — a
única parte que toca o estado global, e a única que não dá para testar:

```php
function meuplugin_resolve_v3r_license_config(): ?array {
    $decisao = meuplugin_decide_v3r_license_config(
        defined( 'V3R_LICENSE_API_URL' ) ? (string) V3R_LICENSE_API_URL : null,
        defined( 'V3R_LICENSE_PUBLIC_KEY' ) ? (string) V3R_LICENSE_PUBLIC_KEY : null,
        MEUPLUGIN_LICENSE_API_URL_PADRAO,   // default de produção — nome PRÓPRIO do plugin
        MEUPLUGIN_LICENSE_PUBLIC_KEY_PADRAO, // idem; nunca sob o nome compartilhado
        MEUPLUGIN_LICENSE_PUBLIC_KEY_PLACEHOLDER
    );

    if ( 'incoerente' === $decisao['status'] ) {
        error_log(
            'MeuPlugin: V3R_LICENSE_API_URL e V3R_LICENSE_PUBLIC_KEY precisam ' .
            'ser definidas juntas no wp-config.php, nunca só uma — licenciamento desativado.'
        );
    }

    return 'ok' === $decisao['status']
        ? array( 'api_url' => $decisao['api_url'], 'public_key' => $decisao['public_key'] )
        : null;
}
```

> **Trave o invariante com um teste, não só com este documento — achado da
> execução real (V3RLGPD, 27/08/2026).** Uma regra escrita não impede que
> alguém reintroduza `define( 'V3R_LICENSE_...' )` daqui a seis meses; um
> teste que lê o próprio arquivo principal do plugin e procura essa
> expressão impede. É uma expressão regular de uma linha, e serve igual
> nos sete.

Os defaults de produção (`https://v3rtech.com.br/wp-json/v3r-license/v1` e a
chave pública real) são os mesmos nos sete plugins — copie as duas funções,
troque só o prefixo `meuplugin_`/`MEUPLUGIN_`, e mantenha os valores de
produção idênticos entre eles.
