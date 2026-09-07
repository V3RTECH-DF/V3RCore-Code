# Retomada — V3RCore

_Escrito ao encerrar a sessão de 07/09/2026._

## Onde você está

O V3RCore não é um produto: é a **base compartilhada da família de plugins da casa**.
O container `/mnt/trabalho/Projetos/V3RTECH/V3RCore/` abriga **dois repositórios
independentes** — a raiz não é repositório, e `sync-all.sh` e `CLAUDE.md` na raiz são
atalhos.

- **`Code/`** — a biblioteca PHP `v3rtech/v3r-core` (repo `V3RTECH-DF/V3RCore-Code`).
  **Governa**: declara telas, resolve permissão, entrega a árvore de navegação
  filtrada, bloqueia acesso direto, além de licenciamento, assinatura, documentos,
  notificação e papéis.
- **`Front/`** — o pacote de tela `@v3rtech/v3r-front` (repo `V3RTECH-DF/V3RFront-Code`,
  público). **Desenha**: cabeçalho, barra de navegação, área de avisos, guarda de rota e
  a correção de cascata do wp-admin.

⚠️ **A fronteira entre os dois é dado, não código** — o PHP produz a árvore, o
componente consome uma forma documentada. É o que permite versionarem em ritmos
diferentes.

**O que é permitido:** commitar e publicar, com autorização permanente do Bruno para
trabalho autônomo. **Push é dele** — entregue o comando `./sync-all.sh`, nunca `git push`.
Publicação de tag: `./sync-all.sh -c` (biblioteca) e `./sync-all.sh -f` (pacote de tela,
que publica código **e** tag juntos).

**Não existe pasta `Projeto/`** neste container. Documentação de desenvolvimento mora em
`Code/dev-history/`; catálogos e contratos em `Code/docs/` e `Front/docs/`.

## Estado atual

- **`v3rtech/v3r-core` v0.21.0** — publicada.
- **`@v3rtech/v3r-front` v0.6.0** — publicada.

**Pronto e validado em produção, com dois consumidores:** a camada de navegação do
painel, o cabeçalho/barra/avisos, a guarda de rota e a correção de cascata. Medidos no
**V3RLGPD** e no **RIT360 Flow**, inclusive **convivendo no mesmo WordPress** — cada
Operador abre a própria entrada, o administrador abre as duas.

**Pronto com um consumidor só:** papéis orientados a dados (V3RLGPD), assinatura com
certificado (Flow), documentos CNPJ/CPF (Flow), sugestão de domínio de e-mail e acesso
por link temporário (V3REvent).

**Pela metade:** a issue `#16` (padronizar a geração de PDF e criar o catálogo de
componentes) — o catálogo foi entregue (`Code/docs/componentes-da-familia.md`), a
padronização do PDF não. E a `#25` (posição das entradas no menu) — os ícones de família
foram decididos e salvos, a ordenação não foi implementada.

⚠️ **Cinco dos nove plugins da casa ainda apontam para a `^0.7.0`**, de agosto. Estão em
produção e funcionando, e não alcançam nada do que veio depois. **Reimplementar por não
enxergar é o modo de falha mais provável hoje** — daí o índice.

## O que a última sessão fez

Padronizou a construção do menu e do cabeçalho de toda a família, do zero.

1. **Debate e decisão de projeto** — régua de tamanhos, comportamento com muitas abas
   (quebra em linhas, não barra de rolagem), fonte própria (Exo 2, embarcada, declarada
   para a tela inteira do plugin), dois blocos contíguos no menu do WordPress (família
   RIT e família V3RTECH, sem plugin de terceiro no meio) e um ícone por família (duplo V
   e rosa dos ventos, versões silhueta criadas e salvas).
2. **Contrato escrito antes do código** — `Code/docs/navegacao-do-painel.md`.
3. **Camada de governo implementada** (`Admin\Nav\`) e refinada pelas duas adoções reais.
4. **Pacote de tela criado do zero** como repositório próprio, publicado, e endurecido
   pelas adoções: geometria das barras, tamanho do logo, cor de destaque, forma de
   entregar o CSS sem quebrar o executor de testes do consumidor.
5. **Correção de cascata promovida a peça instalável** (`#36`), medida em três cenários.
6. **Papéis orientados a dados promovidos** (`#39`, `v0.20.0`), de duas cópias divergentes.
7. **Índice de componentes escrito** (`Code/docs/componentes-da-familia.md`) e a regra de
   mantê-lo gravada nos dois CLAUDE.md — o global manda **consultar antes de implementar
   qualquer coisa**.

Ficou de fora deliberadamente: a ordenação das entradas no menu (`#25`) e a geração de
PDF (`#16`).

## Decisões, com o motivo

- **A peça fixa geometria, comportamento e fonte; o plugin fornece a cor.** Sem isso cada
  produto reinventa espaçamento e a família deixa de parecer família.
- **O pacote de tela NÃO viaja pela biblioteca PHP.** No empacotamento dos plugins a tela
  é compilada **antes** de a biblioteca ser embutida — peça distribuída pelo caminho do
  PHP não existiria ainda na hora do build. E cada plugin embutindo a própria cópia é o
  que evita dois produtos nossos colidirem no mesmo WordPress.
- **O pacote é a raiz do próprio repositório**, porque o gerenciador de pacotes do
  JavaScript instala a raiz de um repositório git, nunca uma subpasta. Não havia escolha.
- **Consumo por tag fixa.** "A principal andou" não é "a versão saiu".
- **A capability sintética que protege a entrada de menu é derivada do slug do menu**, não
  uma constante da biblioteca — ver a premissa derrubada abaixo.
- **A guarda se cala sobre capability que não é dela** (retorna "não opino" em vez de
  "não"), para que a resposta de um plugin não sobrescreva a de outro.
- **`canOpen` nega o desconhecido.** Rota ausente do mapa e rota negada são a mesma coisa
  para quem roteia no cliente.
- **O índice de componentes é obrigação da entrega, não cortesia.** Índice que mente por
  omissão é pior que índice nenhum: quem consulta e não encontra reimplementa.

## Premissas que caíram

- **A mais cara: "o Strauss isola tudo".** Ele prefixa classes e namespaces, mas **não o
  valor de uma constante de string**. A capability sintética era uma constante da
  biblioteca, portanto idêntica nas cópias prefixadas de cada plugin — e a guarda do
  V3RLGPD respondia "não" pela pergunta do Flow. Sintoma: 403 ao abrir a entrada de menu
  do outro produto. **Cinco hipóteses foram refutadas antes desta** (cache do agregado,
  reentrância, cache não indexado por usuário, filtros removidos, duas instâncias da mesma
  guarda).
- **"Aceitei o relato de uma medição como se fosse a medição."** Um dos diagnósticos
  intermediários identificava as guardas por nome de método, não por classe; três correções
  foram construídas sobre isso. Duas eram defeitos reais de qualquer forma.
- **`getComputedStyle` no jsdom não mede cascata** — não implementa especificidade, aplica
  a última regra que casa. Um teste de cascata escrito do jeito óbvio **concorda com a
  realidade pelo motivo errado**, e continua concordando depois da correção, aí já errado.
- **Valor de reserva em variável de CSS protege contra ausência, não contra declaração
  vazia.** Consumidor que declara a variável sem valor apaga o traço da aba ativa.
- **Embutir o import do CSS no pacote trocou uma falha silenciosa por uma barulhenta** —
  quebrou o executor de testes dos consumidores em Node. Resolvido por condições de
  exportação, com o caminho legado apontando para a variante **com** CSS, para quem usa
  ferramenta antiga falhar alto em vez de perder o estilo em silêncio.
- **Adoção validada sozinha não prova convivência.** Cinco defeitos desta camada só
  apareceram quando o **segundo** plugin adotou — um deles fazia um produto cancelar a
  entrada de menu do outro.

## Issues pendentes, por prioridade

| # | Descrição curta | Por que está nesta posição |
|---|---|---|
| 25 | Entradas de menu da família espalhadas pelo painel — falta a convenção de posição | é o que o usuário vê: hoje os produtos da casa aparecem dispersos entre plugins de terceiro. Os ícones já estão decididos e salvos; falta a ordenação |
| 33 | O registro de ativação nunca aprende a versão nova | defeito com efeito em produção — o painel de licenças mostra versão errada de todo mundo |
| 19 | A tela de licença consulta o servidor duas vezes a cada abertura | lentidão que o cliente sente, correção pequena |
| 16 | Padronizar a geração de PDF (a metade do catálogo já saiu) | é a mesma reimplementação em triplicata que fez esta biblioteca existir; grande, e por isso adiada |
| 38 | Identificador de tela vira endereço global do WordPress, sem proteção contra colisão | dois plugins podem escolher o mesmo identificador e um sequestra a tela do outro; ainda não aconteceu |
| 14 + 34 + 13 + 7 | Padronizar a publicação dos plugins, o guard de prefixação em três cópias divergentes, e CI | um bloco só, e é dívida nossa: o cliente não sente, mas já derrubou o checkout de quatro sites |
| 31 | Vocabulário de recusa do V3RSigner | a biblioteca tem o contrato do assinador e nada sobre o que o serviço responde ao recusar |
| 37 | Atributos comuns de bloco | espera deliberada pelo segundo consumidor — promover com um só repete o erro conhecido |

O que mais pesou na ordem: **o que o usuário sente vem antes da dívida interna**, e
capacidade com um consumidor só não é promovida.

## Próximo passo

A próxima sessão tem escopo definido, nesta ordem:

1. **`#25` — posição das entradas de menu da família.** Dois blocos contíguos (família RIT,
   depois família V3RTECH), ordem alfabética dentro de cada bloco, ícone de família já
   salvo. Decisão de projeto fechada; falta implementar e medir num site com mais de um
   plugin da casa instalado.
2. **`#19` — a tela de licença consulta o servidor duas vezes a cada abertura.** Correção
   pequena, lentidão que o cliente sente.
3. **`#14` + `#13` + `#7` — padronizar a publicação dos plugins e fechar o CI.** Um
   trabalho só. ⚠️ **A `#34` (o guard de prefixação em três cópias divergentes) se decide
   junto**: as três cópias existem porque cada fluxo de publicação carregou a sua, então
   unificar a publicação sem unificar o guard deixa a divergência de pé.

⚠️ Ao unificar as três cópias do guard, a regra que vale é **unificação não pode reduzir**:
levantar o que cada cópia já pegava antes de escolher a que fica — a mais restritiva tende
a ganhar sem ninguém decidir.

## Comandos úteis

Da raiz do container (`/mnt/trabalho/Projetos/V3RTECH/V3RCore/`):

    ./sync-all.sh -a          # commita e envia tudo o que existir
                              # (não existe -p neste container: documentação e
                              #  gestão moram em Code/ e vão no -c)
    ./sync-all.sh -c          # só a biblioteca PHP (Code/)
    ./sync-all.sh -f          # só o pacote de tela (Front/) — publica código E tag
    ./sync-all.sh -t          # publica a tag da versão corrente
    ./sync-all.sh -a --dry-run

Validação da biblioteca, dentro de `Code/`:

    composer check            # phpunit + phpstan + phpcs

Validação do pacote de tela, dentro de `Front/`:

    npm run lint && npm run test && npm run build

Issues (a lista viva de trabalho) em `V3RTECH-DF/V3RCore-Code`. Para autenticar, carregue
`Code/bin/config.sh` no **mesmo comando** do `gh`.
