# CLAUDE.md — V3RCore (container)

Regras de **nível de container** (`/mnt/trabalho/Projetos/V3RTECH/V3RCore`). Carrega quando a sessão
abre aqui. Complementa o global (`~/.claude/CLAUDE.md`). Precedência em conflito:
global > container > repositório.

> Criado por `utils/setup-new-project.sh` em 25/08/2026, forma **single-repo**.
> Este arquivo nasce como esqueleto: preencha o que for do projeto e apague o
> que não se aplicar. O que ele descreve é a estrutura, não o produto — produto,
> arquitetura e histórias são da skill `project-bootstrap`.

## Estrutura

A raiz **não é** repositório: é uma pasta que abriga repositórios independentes.

- `Code/` — a biblioteca PHP — repositório do código (nosso, editável) (repo `V3RTECH-DF/V3RCore-Code`)
- `Front/` — o pacote de front da família (npm), repositório **próprio**
  (repo `V3RTECH-DF/V3RFront-Code`, público). Enviado por `./sync-all.sh -f`.
- `Code/bin/` — ferramentas do container. Config central em `bin/config.sh`
  (caminhos derivados, repositórios, `ISSUES_REPO`, `MANUAL_CUSTOM_DOMAIN`).
  Ver `bin/README.md` para o que veio e o que se acrescenta depois.
- `sync-all.sh` e `CLAUDE.md` na raiz são **atalhos**, recriados por `prj.sh -r`.
  Não vêm no clone: não pertencem a repositório nenhum.

> Nomes de pasta e arquivo em **ASCII** (sem acento nem cedilha); o nome de
> exibição do produto pode ser estilizado.

## Como sincronizar

    ./sync-all.sh -a          # tudo o que existir neste projeto
    ./sync-all.sh -c          # só a biblioteca PHP (Code/) — leva docs e gestão junto
    ./sync-all.sh -f          # só o pacote de tela (Front/) — publica código E tag
    ./sync-all.sh -t          # publica a tag da versão corrente
    ./sync-all.sh -a --dry-run

⚠️ **Não existe `-p` aqui.** `bin/sync-project.sh` não faz parte deste
container: documentação e gestão moram **dentro** de `Code/`, então viajam no
`-c`. Passar `-p` aborta o comando inteiro antes de enviar qualquer coisa.

Trazer e enviar entre as quatro máquinas é do `prj.sh` (`prj.sh -s`), que lê o
manifesto em `v3rtech-scripts/configs/projetos.manifesto`. A linha deste projeto
já está lá — **ela é o que faz o projeto existir nas outras máquinas.**

## Issues e backlog

- **Issues são a lista viva de trabalho**, em `V3RTECH-DF/V3RCore-Code`.
  Levantamento, apuração e evidência vão para a issue — nunca para arquivo solto.
- Labels canônicas (16) já criadas: `tipo:*`, `P0`–`P3`, `bloqueado`,
  `aguardando-validação`, e as três extras.
- Prefixo de backlog no documento-índice: **BK-NNN**.

## Segredos

`.envrc` de cada repositório é talão sem segredo (gerado por `prj.sh -r`). Os
segredos vivem em `~/.config/v3rtech/secrets.env`, um por máquina, fora de
qualquer repositório. **Nunca imprimir `.envrc` nem `.credentials*`.**

## A preencher

- [x] Stack e convenções do código — `Code/`: PHP >=8.2, biblioteca Composer
      embutida por Strauss, validação por `composer check` (phpunit, phpstan,
      phpcs). `Front/`: React 19 + TypeScript estrito + Vite em modo biblioteca,
      CSS próprio sem Tailwind.
- [ ] Ambiente de desenvolvimento e como validar
- [ ] Alvo de produção e como se deploya (ver `bin/README.md`)
- [ ] Domínio do manual, quando existir (`MANUAL_CUSTOM_DOMAIN`)

## Dois repositórios, uma família (a partir de 06/09/2026)

O container abriga **dois** repositórios, e a divisão entre eles não é
organizacional, é de responsabilidade:

- **`Code/` governa** — declara telas, resolve permissão, entrega a árvore de
  navegação filtrada e bloqueia o acesso direto (`docs/navegacao-do-painel.md`).
- **`Front/` desenha** — cabeçalho, barra de navegação e área de avisos do
  painel, consumidos pelos plugins como dependência do build deles.

⚠️ **A fronteira entre os dois é dado, não código:** o PHP produz a árvore, o
componente consome uma forma documentada. É o que permite versionarem em ritmos
diferentes sem um arrastar o outro.

**Por que o front NÃO viaja pela biblioteca PHP** (issue `#26`): no empacotamento
dos plugins a tela em React é compilada **antes** de a biblioteca ser embutida —
peça distribuída pelo caminho do PHP não existiria ainda na hora do build. E
plugin instalando o pacote pelo gerenciador do JavaScript embute a própria cópia,
então dois produtos nossos com versões diferentes no mesmo WordPress não colidem
— o mesmo isolamento que a prefixação dá ao PHP.

⚠️ **O pacote precisa ser a RAIZ do repositório**, porque o gerenciador de
pacotes do JavaScript instala a raiz de um repositório git, nunca uma subpasta.
É por isso que ele não mora dentro do `Code/`.

## O índice de componentes é obrigação, não cortesia

`Code/docs/componentes-da-familia.md` é a lista do que os plugins da casa podem
consumir, nos **dois** repositórios. É a página que um plugin lê antes de
escrever qualquer coisa.

⚠️ **Toda capacidade nova, promovida ou publicada, entra no índice na mesma
entrega** — não depois, não "quando der". Índice que mente por omissão é pior
que índice nenhum: quem consulta e não encontra conclui que não existe, e
reimplementa. Foi assim que o envio de documento nasceu três vezes.

Vale também para **versão nova de peça já listada** quando ela muda o que a peça
resolve, e para **consumidor novo** — a coluna "quem consome" é o que revela
capacidade com um usuário só, e capacidade sem nenhum.

## Proteção da biblioteca (a partir da v0.2.0)

A `v3r-core` **não se auto-prefixa mais** — até a `v0.1.0` rodava Strauss internamente,
mas a pasta prefixada é gitignored e não viajava na tag, então o hospedeiro recebia
namespace inexistente (fatal error na ativação). Isolar o `plugin-update-checker` agora
é responsabilidade **de cada plugin hospedeiro**, numa passada só de Strauss. Ver
`docs/integracao-em-plugin.md`. Remover essa responsabilidade do hospedeiro exigiria
repensar a forma de distribuição da lib — não é mudança pontual.
