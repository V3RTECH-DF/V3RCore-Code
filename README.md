# V3RCore — Código

Repositório do código do V3RCore. Privado.

Faz parte do container `V3RTECH/V3RCore`, que reúne também `Projeto/`
(documentação e gestão). As ferramentas do container vivem em
`Projeto/bin/`; use `./sync-all.sh` na raiz do container.

Backlog vivo em issues: <https://github.com/V3RTECH-DF/V3RCore-Code/issues>

---

## v3r-core

Biblioteca cliente compartilhada de licenciamento e auto-atualização para os
plugins WordPress da V3RTECH/RIT. Embutida via Composer + [Strauss](https://github.com/BrianHenryIE/strauss)
em cada plugin distribuído fora do wordpress.org, para que dois plugins com
versões diferentes desta lib no mesmo WordPress nunca colidam.

> **Estado atual: fatia 2a concluída.** Comunicação com o servidor, cache
> local, verificação de assinatura e período de graça estão implementados e
> testados (`activate`/`deactivate`/`refresh`/`getState`). A integração com
> o mecanismo de atualização do WordPress (`Updater\UpdateChecker`), os
> endpoints REST internos (`docs/api-contract.md` §8) e a `AdminPage` ainda
> são TODO da fatia 2b. Instanciar e usar `Bootstrap` já é seguro: nada aqui
> derruba o plugin hospedeiro, mesmo sem rede e sem estado salvo.

### Como um plugin consome esta lib

#### 1. Instalar

```bash
composer require v3rtech/v3r-core
composer require brianhenryie/strauss --dev
```

Configure o Strauss no `composer.json` do **plugin** (não desta lib) para
prefixar `v3r-core` e suas próprias dependências transitivas — espelhando a
config deste próprio repositório (`extra.strauss` em `composer.json`), com
namespace específico do plugin (ex.: `MeuPlugin\Vendor\`).

#### 2. Chamar o bootstrap

No arquivo principal do plugin:

```php
<?php
declare(strict_types=1);

use V3R\Core\Bootstrap;

require_once __DIR__ . '/vendor-prefixed/autoload.php'; // ou vendor/autoload.php em dev

add_action( 'plugins_loaded', function () {
    $v3rCore = new Bootstrap(
        'v3rlgpd',                                          // product_slug no servidor de licenças
        __FILE__,                                            // arquivo principal do plugin
        'https://licencas.v3rtech.com.br/wp-json/v3r-license/v1',
        'CHAVE_PUBLICA_ED25519_BASE64_DO_SERVIDOR',           // não é segredo — ver docs/api-contract.md §4
        '2.3.0',                                              // versão instalada do plugin (semver)
        'manage_v3rlgpd_licenses'                             // opcional — default manage_options
    );

    $v3rCore->boot();

    // Consultar o estado a qualquer momento (nunca bate na rede aqui):
    $licenseState = $v3rCore->getLicenseManager()->getState();

    if ( $licenseState->isValid() ) {
        // Feature opcional que depende de licença ativa, se houver.
    }
    // A funcionalidade principal do plugin NUNCA deve ser condicionada a
    // isValid() — o produto vendido é atualização, não funcionalidade.
} );
```

#### 3. O que o plugin precisa configurar

- `product_slug` — o mesmo cadastrado no servidor de licenças.
- URL base do servidor de licenças (`v3r-license/v1`).
- Chave pública ed25519 do servidor, embutida como constante do plugin, para
  a verificação de assinatura (`V3R\Core\Licensing\SignatureVerifier`) — ver
  `docs/api-contract.md` §4.
- Versão instalada do plugin (semver) — vai em toda chamada ao servidor
  (`plugin_version`, `docs/api-contract.md` §2.1).
- Capability do WordPress para a tela/endpoints internos da fatia 2b
  (opcional — default `manage_options`, ver `docs/api-contract.md` §8.2).

### Desenvolvimento

```bash
make install   # composer install (já roda o prefixo do Strauss sozinho, ver abaixo)
make lint      # phpcs
make analyse   # phpstan
make test      # phpunit
make check     # os três, nesta ordem
make prefix    # reexecuta só o Strauss, sem reinstalar dependências
```

**O prefixo do Strauss não é um passo opcional nem manual.** `composer.json`
amarra `composer prefix` a `post-install-cmd`/`post-update-cmd`: todo
`composer install`/`composer update` já deixa `vendor-prefixed/` gerado e
registrado no classmap do próprio Composer (`autoload.classmap` aponta para
`vendor-prefixed/`), e o pacote original (`vendor/yahnis-elsts/`) é apagado
pelo Strauss (`delete_vendor_packages: true`) — sem isso, o autoloader do
Composer carregaria a cópia original do `plugin-update-checker` via
`autoload.files` (a própria lib declara isso no `composer.json` dela), e a
cópia prefixada em `vendor-prefixed/` ficaria como código morto, sem
resolver o problema de colisão entre plugins que o Strauss existe para
evitar. Se você rodar `composer install` sem os hooks (ex.: com
`--no-scripts`), rode `composer prefix` manualmente antes de usar a lib.

### Estrutura

```
src/
  Bootstrap.php            — ponto de entrada único
  Licensing/                — ativação, validação, cache, assinatura
  Updater/                  — encapsula o Plugin Update Checker + UpdateGate
  Support/                  — SiteIdentity, Logger, mascaramento de chave
docs/
  api-contract.md           — spec do protocolo v3r-license/v1
```

### Documentação técnica

- `docs/api-contract.md` — contrato completo cliente↔servidor.
- Guia de pesquisa que fundamenta as decisões desta lib:
  `V3RLicense/Projeto/dev-history/pesquisa-updater-licenciamento.md`.
