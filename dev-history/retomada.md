# Retomada — V3RCore

_Escrito ao encerrar a sessão de 07/09/2026._

## Onde você está

O V3RCore não é um produto: é a **base compartilhada da família de plugins da casa**.
O container `/mnt/trabalho/Projetos/V3RTECH/V3RCore/` abriga **dois repositórios
independentes** — a raiz não é repositório, e `sync-all.sh` e `CLAUDE.md` na raiz são
atalhos.

- **`Code/`** — a biblioteca PHP `v3rtech/v3r-core` (repo `V3RTECH-DF/V3RCore-Code`).
  **Governa**: declara telas, resolve permissão, entrega a árvore de navegação
  filtrada, posiciona a entrada no menu, bloqueia acesso direto, além de
  licenciamento, assinatura, documentos, notificação e papéis.
- **`Front/`** — o pacote de tela `@v3rtech/v3r-front` (repo `V3RTECH-DF/V3RFront-Code`,
  público). **Desenha**: cabeçalho, barra de navegação, área de avisos, guarda de rota e
  a correção de cascata do wp-admin.

⚠️ **A fronteira entre os dois é dado, não código** — o PHP produz a árvore, o
componente consome uma forma documentada.

**Uma terceira peça nasceu nesta sessão, fora do container:**
`V3RTECH-DF/v3r-release` (clone em `/mnt/trabalho/Projetos/V3RTECH/v3r-release`), a
receita única de empacotar, conferir e publicar os plugins. **Repositório público, e
sem segredo dentro** — ver "Decisões".

**O que é permitido:** commitar e publicar, com autorização permanente do Bruno para
trabalho autônomo. **Push é dele.**

⚠️ **Não existe `./sync-all.sh -p` neste container** (não há `bin/sync-project.sh`):
documentação e gestão moram dentro de `Code/` e viajam no `-c`. Passar `-p` aborta o
comando inteiro antes de enviar qualquer coisa.

## Estado atual

- **`v3rtech/v3r-core` v0.22.0** — publicada.
- **`@v3rtech/v3r-front` v0.6.0** — publicada.
- **`v3r-release`** — primeira fatia no ar (só a conferência; ainda sem tag).

**Pronto e validado em produção, com dois consumidores:** navegação do painel,
cabeçalho/barra/avisos, guarda de rota, correção de cascata — medidos no **V3RLGPD** e
no **RIT360 Flow** convivendo no mesmo WordPress.

**Pronto e medido, esperando adoção:** a **posição das entradas no menu** (`#25`). A
biblioteca agrupa; nenhum site vê o efeito enquanto os produtos não anunciarem — é a
`#40`.

**Pela metade:** a `#16` (o catálogo saiu, a padronização do PDF não) e a receita única
(`#14`/`#34`: confere, ainda não empacota).

⚠️ **Cinco dos nove plugins ainda apontam para a `^0.7.0`**, de agosto.

## O que a última sessão fez

1. **`#25` — entradas da família contíguas no menu** (`Admin\Nav\MenuOrder`, v0.22.0).
   Medida num WordPress com oito plugins da casa: antes, produtos espalhados em quatro
   trechos, um deles abaixo de Configurações; depois, um bloco só.
2. **`#19` — apurada e devolvida**: não era da biblioteca. Ver "Premissas que caíram".
   Corrigida no RIT360 Solidário (2.26.5, publicada).
3. **Frente da publicação (`#14`/`#34`/`#13`/`#7`)**: levantamento dos nove produtos,
   duas decisões tomadas, repositório `v3r-release` criado, contrato escrito e a
   **primeira fatia entregue** — a conferência do pacote.
4. **Consertos de rota que apareceram no caminho**: a CI desta biblioteca estava
   vermelha há 33 execuções; o `prj.sh` não via repositório nunca enviado; o manifesto
   não registrava o `Front/`.

## Decisões, com o motivo

- **O anúncio das entradas de menu é uma global compartilhada, NÃO prefixada** — cada
  plugin embute a própria cópia da biblioteca, e uma reordenação que conhecesse só a
  própria entrada faria as cópias brigarem. É o **oposto deliberado** do defeito da
  v0.21.0: capability é *resposta sobre uma pessoa*, e responder pelo alheio é errado;
  posição no menu é *fato sobre a coluna do site*, um só para todo mundo.
- **Quem não adotou a camada de navegação entra no bloco com uma linha** — sem essa
  porta, o benefício esperaria a adoção plugin a plugin.
- **A receita de publicação mora em repositório PÚBLICO.** A alternativa privada faria
  publicar depender de uma chave cadastrada em nove produtos — nove lugares para
  expirar. ⚠️ Consequência: ali não entra segredo, endereço de site nem inventário.
- **Régua única no que quebra o site; convenção declarada por produto**, com padrão de
  fábrica "exige" — para não cumprir ser declaração visível com dono, não ausência que
  ninguém enxerga.
- **A conferência abre o pacote de verdade**, nunca o diretório que ia virar pacote —
  quatro produtos param nessa meia prova.
- **A versão esperada é argumento obrigatório.** Deduzi-la do nome do arquivo seria
  circular, e a convenção nem existe entre os produtos.

## Premissas que caíram

- **`#19` culpava a biblioteca, e o custo descrito não existia.** O endereço de licença
  lê só o cache local — não há ida ao servidor nem risco de dois tempos limite. O
  defeito era do consumidor e maior: **a SPA inteira do Solidário montava duas vezes**,
  porque o módulo de entrada era baixado por dois endereços (um com `?ver=`, outro sem,
  pelo grafo de imports) e módulo ES é deduplicado por URL. Toda tela pedia tudo em
  dobro. E **não era o modo estrito do React** — os dois renderizadores da página
  reportam build de produção.
- **`#13` e `#7` descreviam um estado que não existe mais.** Os dez repositórios já
  validam commit em push e PR; a `#7` estava corrigida aqui desde 27/08. O que sobrou é
  desigualdade de *conteúdo*: cinco produtos têm estilo e análise configurados e **não
  os rodam**.
- **"A cópia mais completa do guard" não existe.** Nenhuma contém as outras — cinco
  produtos têm, cada um, uma verificação exclusiva. Unificar é **somar**.
- **Fixture escrito por quem escreveu a verificação concorda com ela.** A conferência
  de nome de classe em texto passou em 25 testes e **reprovava todo pacote corretamente
  prefixado** — o nome prefixado contém o original como sufixo. Só o pacote real pegou.
- **CI verde no dia em que se escreveu não é rede de proteção.** A desta biblioteca
  ficou vermelha 33 execuções seguidas, por um detalhe do teste do espelho JS, e
  ninguém viu.
- **Ferramenta que compara com o erro descartado responde "tudo em ordem".** O `prj.sh`
  dizia isso para um repositório que nunca foi enviado.

## Issues pendentes, por prioridade

| # | Descrição curta | Por que está nesta posição |
|---|---|---|
| 33 | O registro de ativação nunca aprende a versão nova | defeito com efeito em produção — o painel de licenças mostra versão errada de todo mundo |
| 40 | Adoção da posição de menu nos oito plugins | a biblioteca já agrupa; sem isso nenhum cliente vê diferença nenhuma |
| 14 + 34 + 13 | Receita única de publicação, guard e conteúdo do CI | em andamento; já derrubou o checkout de quatro sites uma vez |
| 16 | Padronizar a geração de PDF (o catálogo já saiu) | é a mesma reimplementação em triplicata que fez esta biblioteca existir; grande, e por isso adiada |
| 38 | Identificador de tela vira endereço global do WordPress, sem proteção contra colisão | dois plugins podem sequestrar a tela um do outro; ainda não aconteceu |
| 31 | Vocabulário de recusa do V3RSigner | a biblioteca tem o contrato do assinador e nada sobre o que o serviço responde ao recusar |
| 37 | Atributos comuns de bloco | espera deliberada pelo segundo consumidor — promover com um só repete o erro conhecido |

O que mais pesou na ordem: **o que o usuário sente vem antes da dívida interna**, e
capacidade com um consumidor só não é promovida. A `#40` subiu porque é o que
transforma trabalho já feito em algo visível.

## Próximo passo

**Segunda fatia do `v3r-release`: o empacotamento e a ação do robô, com o V3REvent
migrado como prova** — medido antes de encostar nos outros oito. O V3REvent porque a
`#14` já elege o fluxo dele como modelo, ele tem o script dentro do repositório do
código, e é o único que confere o cache-busting dos artefatos de front, que é uma das
verificações a preservar na soma.

## Comandos úteis

Da raiz do container (`/mnt/trabalho/Projetos/V3RTECH/V3RCore/`):

    ./sync-all.sh -a          # commita e envia tudo o que existir
    ./sync-all.sh -c          # só a biblioteca PHP (Code/) — leva docs e gestão junto
    ./sync-all.sh -f          # só o pacote de tela (Front/) — publica código E tag
    ./sync-all.sh -t          # publica a tag da versão corrente
    ./sync-all.sh -a --dry-run

Validação da biblioteca, dentro de `Code/`:

    composer check            # phpunit + phpstan + phpcs

Validação do pacote de tela, dentro de `Front/`:

    npm run lint && npm run test && npm run build

A conferência de pacote, em `/mnt/trabalho/Projetos/V3RTECH/v3r-release`:

    bash tests/run-tests.sh          # a suíte
    bash tests/run-tests-reais.sh    # contra os .zip publicados dos produtos

Alinhar as máquinas (lê o manifesto em `v3rtech-scripts/configs/projetos.manifesto`):

    utils/prj.sh              # situação
    utils/prj.sh -s           # traz e envia

⚠️ Mexeu no `v3rtech-scripts`? Rode `utils/publicar.sh` — quem está no PATH é a cópia
publicada, não a árvore de desenvolvimento.

Issues (a lista viva de trabalho) em `V3RTECH-DF/V3RCore-Code`. Para autenticar, carregue
`Code/bin/config.sh` no **mesmo comando** do `gh`.
