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

- `Code/` — código do app — repositório do código (nosso, editável) (repo `V3RTECH-DF/V3RCore-Code`)
- `Code/bin/` — ferramentas do container. Config central em `bin/config.sh`
  (caminhos derivados, repositórios, `ISSUES_REPO`, `MANUAL_CUSTOM_DOMAIN`).
  Ver `bin/README.md` para o que veio e o que se acrescenta depois.
- `sync-all.sh` e `CLAUDE.md` na raiz são **atalhos**, recriados por `prj.sh -r`.
  Não vêm no clone: não pertencem a repositório nenhum.

> Nomes de pasta e arquivo em **ASCII** (sem acento nem cedilha); o nome de
> exibição do produto pode ser estilizado.

## Como sincronizar

    ./sync-all.sh -a          # tudo o que existir neste projeto
    ./sync-all.sh -p          # só a documentação e a gestão
    ./sync-all.sh -a --dry-run

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

- [ ] Stack e convenções do código
- [ ] Ambiente de desenvolvimento e como validar
- [ ] Alvo de produção e como se deploya (ver `bin/README.md`)
- [ ] Domínio do manual, quando existir (`MANUAL_CUSTOM_DOMAIN`)
