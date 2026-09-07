# V3RCore — Código

Repositório da **biblioteca PHP** do V3RCore. Público.

Faz parte do container `V3RTECH/V3RCore`, que abriga **dois** repositórios:
este e o `Front/` — o pacote de tela da família (`@v3rtech/v3r-front`), com
cabeçalho, barra de navegação, área de avisos e a ferramenta de build que
corrige a cascata do CSS.

⚠️ **A divisão entre os dois é de responsabilidade:** esta biblioteca
**governa** (declara telas, resolve permissão, entrega a árvore filtrada,
bloqueia o acesso direto) e o pacote de front **desenha**. A fronteira entre
eles é **dado**, não código — o PHP produz a árvore, o componente consome uma
forma documentada —, e por isso versionam separado.

As ferramentas do container vivem em `Code/bin/`; use `./sync-all.sh` na raiz
do container (`-c` para esta biblioteca, `-f` para o pacote de front).

👉 **`docs/componentes-da-familia.md` é o índice do que os plugins da casa podem
consumir, nos dois repositórios. É a página que se lê antes de construir
qualquer coisa.**

Backlog vivo em issues: <https://github.com/V3RTECH-DF/V3RCore-Code/issues>

---

## v3r-core

Biblioteca cliente compartilhada de licenciamento e auto-atualização para os
plugins WordPress da V3RTECH/RIT. Embutida via Composer + [Strauss](https://github.com/BrianHenryIE/strauss)
em cada plugin distribuído fora do wordpress.org, para que dois plugins com
versões diferentes desta lib no mesmo WordPress nunca colidam.

> **Estado atual: a biblioteca vai muito além do licenciamento.** Ela começou
> nele — comunicação com o servidor, cache local, verificação de assinatura,
> período de graça, integração com o mecanismo de atualização do WordPress e os
> endpoints REST internos, tudo ligado por `Bootstrap::boot()` e seguro mesmo
> sem rede e sem estado salvo. Hoje entrega também documentos (CNPJ/CPF),
> notificação, acesso por link temporário, assinatura com certificado, **a
> camada de navegação do painel** e **papéis orientados a dados**.
>
> 👉 **Não leia esta lista para saber o que existe** — ela envelhece. O índice
> vivo, com quem consome cada capacidade e desde qual versão, é
> [`docs/componentes-da-familia.md`](docs/componentes-da-familia.md).

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
  Bootstrap.php             — ponto de entrada único
  Licensing/                — ativação, validação, cache, assinatura
  Updater/                  — encapsula o Plugin Update Checker + UpdateGate
  Support/                  — SiteIdentity, Logger, mascaramento de chave
  Rest/                     — rotas REST internas de licenciamento
  Access/                   — token de acesso por link temporário e limitador de tentativas
  Documents/                — validação/formatação de CNPJ e CPF (alfanumérico incluído)
  Notification/             — despacho de mensagem multi-canal (hoje: e-mail)
  Frontend/                 — localizador de ativo de front (JS/CSS) empacotado com o Strauss
  Assets/                   — ativos de front e dados compartilhados entre PHP e JS
  Signing/                  — assinatura de documentos com certificado: código de
                              autenticidade (emitir/selar), leitura do PKCS#12
                              (validade e titular) e decisão de modo de assinatura
docs/
  api-contract.md              — spec do protocolo v3r-license/v1
  integracao-em-plugin.md      — receita testada de consumo por um plugin
  assinatura-com-certificado.md — catálogo do namespace Signing\
```

### Documentação técnica

- **`docs/componentes-da-familia.md` — o índice do que a família pode consumir,
  nos dois repositórios. É a página que se lê ANTES de construir qualquer
  coisa.**
- `docs/api-contract.md` — contrato completo cliente↔servidor.
- `docs/integracao-em-plugin.md` — receita testada de integração num plugin
  hospedeiro (declaração da dependência, Strauss, empacotamento, verificação).
- `docs/assinatura-com-certificado.md` — catálogo do namespace `Signing\`:
  código de autenticidade, ordem emitir → imprimir → selar, e leitura de
  validade/titular do certificado.
- Guia de pesquisa que fundamenta as decisões desta lib:
  `V3RLicense/Projeto/dev-history/pesquisa-updater-licenciamento.md`.
