# bin/ — ferramentas do container V3RCore

Instalado por `utils/setup-new-project.sh` em 25/08/2026 (forma: **single-repo**).
Todos os caminhos e repositórios saem de `config.sh` — nenhum script tem caminho
escrito à mão.

Na raiz do container há o atalho `sync-all.sh`, criado por `prj.sh -r`. É por ele
que se usa tudo isto no dia a dia.

## O que veio

| Script | Para que serve |
|---|---|
| `sync-all.sh` | Orquestrador. Liga só as etapas cujo script existe aqui. |
| `config.sh` | Caminhos, repositórios, token, domínio do manual. Ponto único. |
| `git-safe.sh` | **Atalho** para `v3rtech-scripts/lib/git-safe.sh`, a camada que nunca descarta trabalho. Usado pelos sync-*. |
| `sync-code.sh` | Envia o repositório do código (quando o código é nosso). |
| `pull-code.sh` | Traz o espelho somente-leitura do código (quando é do Lovable). |
| `sync-project.sh` | Envia documentação, decisões e backlog. |
| `publish-manual.sh` | Publica o manual do usuário no GitHub Pages. |

`sync-code.sh` e `pull-code.sh` são excludentes: o código é nosso **ou** é
espelho, nunca os dois. O `sync-all.sh` usa o que encontrar.

⚠️ **Dependência externa assumida:** o `git-safe.sh` daqui só procura a
biblioteca; o conteúdo vive no `v3rtech-scripts`. É de propósito — sete cópias
idênticas já fizeram uma correção ficar só onde nasceu, duas vezes. Se o
`v3rtech-scripts` não estiver na máquina, os sync-* param com o caminho
procurado e o que fazer; aponte `V3R_SCRIPTS` ou traga o repositório com
`prj.sh -d`.

## O que NÃO veio, e por quê

**`deploy.sh`** não é instalado no dia zero. Ele depende de coisas que um projeto
novo ainda não tem: `PLUGIN_SLUG`, um ZIP em `dist/` gerado pelo `build-zip.sh` e
o inventário `deploy-sites.conf` com hosts e chaves de produção. Instalá-lo vazio
faria o `sync-all.sh -a` tentar deployar e falhar em toda execução.

Quando o projeto tiver alvo de produção, copie `deploy.sh`, `build-zip.sh` e
`deploy-sites.conf.example` do projeto mais parecido — e **acrescente ao
`config.sh` daqui** o que eles esperam (`PLUGIN_SLUG`, `PLUGIN_DIR`, `DIST_DIR`).

**`sync-local.sh`** (espelho no WordPress local) pela mesma razão.

## Primeiras coisas a preencher em `config.sh`

1. `MANUAL_CUSTOM_DOMAIN` — só quando o domínio existir e o DNS apontar.
   Gravar CNAME de domínio que não resolve tira o manual do ar.
2. `ISSUES_REPO` — já apontado; confira se é onde o backlog vai viver de fato.
3. A validação em `sync-code.sh` (`VALIDACAO=`) quando houver suíte de testes.
