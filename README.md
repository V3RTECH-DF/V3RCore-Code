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

> **Estado atual: fatias 2a e 2b concluídas.** Comunicação com o servidor,
> cache local, verificação de assinatura e período de graça estão
> implementados e testados (`activate`/`deactivate`/`refresh`/`getState`).
> A integração com o mecanismo de atualização do WordPress
> (`Updater\UpdateChecker`, sobre o Plugin Update Checker), os quatro
> endpoints REST internos (`docs/api-contract.md` §8) e a `AdminPage`
> padrão (opcional) também estão prontos. `Bootstrap::boot()` já liga
> tudo isso sozinho — instanciar e usar `Bootstrap` continua seguro mesmo
> sem rede e sem estado salvo.

### Como um plugin consome esta lib

**Receita completa, testada de ponta a ponta (declaração da dependência,
bloco `extra.strauss`, passo de empacotamento, verificação e o que já
quebrou e foi corrigido): [`docs/integracao-em-plugin.md`](docs/integracao-em-plugin.md).**
Resumo rápido abaixo; para copiar/colar use o documento.

Desde a `v0.2.0`, o v3r-core **não se auto-prefixa** — o código usa sempre
o namespace `V3R\Core\` puro e referencia o `plugin-update-checker` pelo
namespace original. Toda a prefixação (v3r-core + dependências
transitivas) é feita numa única passada do Strauss, no **plugin
hospedeiro**.

**A prefixação não é automática — é passo explícito.** Achado da execução
real (V3RLGPD, 26/08/2026, `docs/integracao-em-plugin.md` §3): Strauss
como dependência do Composer quebra (`Class "Composer\Factory" not
found`), então ele entra como binário `.phar` standalone, fora do
Composer — e isso tira os hooks `post-install-cmd`/`post-update-cmd` que
antes disparavam a prefixação sozinhos. `composer install` sozinho deixa
`vendor-prefixed/` vazio; é preciso rodar `composer run prefix` depois.

**O script `prefix` também normaliza permissão** (`V3RCore-Code#20`): o
Strauss cria `vendor-prefixed/` com diretórios em modo `700`, ilegíveis
pelo servidor web quando o caminho de implantação preserva permissão
(`rsync -a`, `cp -a`) — o plugin passa a se comportar como se a biblioteca
não estivesse no pacote, sem erro. Ver `docs/integracao-em-plugin.md` §3.

```bash
composer require v3rtech/v3r-core:^0.2.0 --dev
# Strauss: .phar standalone, não dependência do Composer — ver
# docs/integracao-em-plugin.md §3 para o download e o bloco extra.strauss.
composer run prefix
```

#### Endpoints REST internos e tela padrão (fatia 2b)

`boot()` já registra, sob `v3r-core/v1/<product_slug>/license`, as quatro
rotas de `docs/api-contract.md` §8 (`GET .../license`,
`POST .../license/activate`, `.../deactivate`, `.../refresh`), autenticadas
por nonce `wp_rest` + a capability configurada — nunca `is_admin()`. É o que
a equipe do V3RLGPD/V3REvent consome para desenhar a própria aba.

Para um plugin sem interface própria, `createAdminPage()->register()` liga
uma tela padrão em **Ajustes → Licença**, em PHP simples (sem build,
estilos nativos do wp-admin). Nunca chamado por `boot()` sozinho — é sempre
uma decisão explícita do plugin hospedeiro.

### Desenvolvimento

```bash
make install   # composer install
make lint      # phpcs
make analyse   # phpstan
make test      # phpunit
make check     # os três, nesta ordem
```

**Esta lib não se auto-prefixa** (nem a si mesma, nem ao
`plugin-update-checker`) — `composer install` aqui é um `composer install`
comum, sem Strauss. Quem prefixa v3r-core, numa única passada junto com o
`plugin-update-checker`, é sempre o **plugin hospedeiro** — ver
`docs/integracao-em-plugin.md` §6 para o porquê desta lib não fazer sua
própria prefixação (uma versão anterior fazia, e quebrava justamente na
reprefixação em dois níveis).

### Estrutura

```
src/
  Bootstrap.php            — ponto de entrada único
  Licensing/                — ativação, validação, cache, assinatura
  Updater/                  — encapsula o Plugin Update Checker + UpdateGate
  Support/                  — SiteIdentity, Logger, mascaramento de chave
docs/
  api-contract.md           — spec do protocolo v3r-license/v1
  integracao-em-plugin.md   — receita testada de consumo por um plugin
```

### Documentação técnica

- `docs/api-contract.md` — contrato completo cliente↔servidor.
- `docs/integracao-em-plugin.md` — receita testada de integração num plugin
  hospedeiro (declaração da dependência, Strauss, empacotamento, verificação).
- Guia de pesquisa que fundamenta as decisões desta lib:
  `V3RLicense/Projeto/dev-history/pesquisa-updater-licenciamento.md`.
