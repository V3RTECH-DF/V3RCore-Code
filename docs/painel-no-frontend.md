# Painel no frontend: o invólucro sem estilo

> Contrato de marcação para quando o painel de gestão de um produto é
> renderizado numa página **pública** do site do cliente — por shortcode — em
> vez do wp-admin. Achado e corrigido no **V3REvent** em 16/09/2026
> (V3REvent-Code#171, publicado na `1.89.0`); documentado aqui porque o
> defeito é da **receita**, adotada por cópia entre os plugins da casa, e não
> de um produto isolado (`V3RCore-Code#52`).

## 1. O que é

Alguns plugins da família oferecem o próprio painel de gestão também como uma
página pública do site — não só dentro do wp-admin. A técnica é um shortcode
que imprime o painel (a mesma tela em React, com a navegação e as guardas de
sempre) dentro do conteúdo de uma página comum do WordPress, escolhida pelo
cliente.

Essa superfície já é reconhecida pela navegação do painel (`surfaces`, ver
`navegacao-do-painel.md` §2) — o que faltava era a marcação HTML que o
shortcode deve imprimir para o painel conviver com o tema do hospedeiro.

## 2. A marcação obrigatória: dois nós

O shortcode imprime **dois nós, nunca um**:

```html
<div id="meuproduto-front-wrap">
  <div id="meuproduto-front-root" style="all: initial; display: block; box-sizing: border-box; width: 100%; max-width: 100%; min-width: 0;">
    <!-- painel React monta aqui -->
  </div>
</div>
```

- **O invólucro externo** (`*-front-wrap`) não recebe **nenhum** estilo
  nosso. É simples marcação, e é o tema do hospedeiro quem decide a largura
  dele, exatamente como decidiria para qualquer outro bloco de conteúdo da
  página.
- **A raiz interna** (`*-front-root`) é o isolamento de sempre — `all:
  initial`, `display: block`, `box-sizing: border-box` — e ocupa `width:
  100%` do que o invólucro conceder, com `max-width: 100%` e `min-width: 0`.

No V3REvent a marcação é versionada em código, não solta no shortcode:
`Frontend\Gestao::root_markup()` devolve exatamente
`<div class="v3revent-gestao-host"><div id="v3revent-front-root"></div></div>`.
Um teste puro acompanha a função e prende a garantia central desta receita: a
raiz isolada nunca pode voltar a ser o filho direto dimensionado pelo tema —
o teste reprova contra a marcação antiga (nó único) e passa contra a atual.
Vale reproduzir a mesma ideia — função nomeada que devolve a marcação, com
teste que trava a regressão — em qualquer plugin que adotar a receita.

## 3. Por quê

A raiz isolada é ancorada num **id**, o que dá a ela especificidade CSS
**1-0-0**. Em qualquer tema de layout encaixotado (o padrão do WordPress para
conteúdo simples), quem concede o teto de largura à página é a regra
`.is-layout-constrained > :where(...)`, com especificidade **0-1-0**.

Um-zero-zero vence zero-um-zero. Com nó único — a raiz isolada plantada
direto como filho do conteúdo — ela apaga o teto de largura que o tema
concedia, e o painel passa a ocupar a **janela inteira**, destoando do resto
do site.

**Caso real:** no site da RFCC (tema Blocksy + child), o painel do V3REvent
renderizava em `1440px` — nó único, antes da correção — contra `1290px` do
cabeçalho e do título da própria página, medidos nas duas situações. Depois
da correção (dois nós), o painel passou a sair também em `1290px`, cabeçalho
e título permanecendo em `1290px` como antes — corrigido na `1.89.0`.

A regra, numa frase: **o plugin não sobrescreve o hospedeiro; ele deixa de
disputar com ele.** O invólucro sem estilo devolve ao tema a decisão de
largura que sempre foi dele; a raiz isolada continua brigando só pelo que
está dentro do invólucro, nunca pelo que está fora.

## 4. O que a raiz isolada continua resolvendo

O invólucro **não substitui** a raiz isolada — as duas coisas continuam
necessárias, cada uma com seu papel:

- **Isolar o painel do CSS do tema.** `all: initial` continua impedindo que
  regras do tema vazem para dentro do painel e o desconfigurem.
- **Resistir a hospedeiro dimensionado por conteúdo.** Em layout flex ou
  grid onde a largura do container segue o conteúdo do filho (não o
  contrário), `min-width: 0` e `width: 100%` continuam necessários — sem
  eles, a largura do painel passaria a seguir o conteúdo da aba ativa em vez
  de ocupar o espaço concedido.

## 5. Como conferir numa adoção

Ao portar esta receita para um plugin, meça **dois pontos, dois estados**:

1. **Largura e o atributo `style`** do painel **e** de um vizinho (o
   cabeçalho do site, ou outra página comum) — num tema de layout
   encaixotado. Os dois devem coincidir; o painel não pode destoar da faixa
   central do site.
2. **O mesmo par, num hospedeiro dimensionado por encolhimento** (o caso que
   motivou a correção de largura original da receita). Nada muda: mesma
   largura, e o mesmo `style`, string idêntica antes e depois de qualquer
   alteração.

Prova por comparação, nunca por aparência de tela: medir só o painel, sem o
vizinho, não discrimina o defeito — as duas larguras erradas também "parecem"
uma tela normal.

**Controle negativo obrigatório.** Esta receita nasceu para corrigir um
hospedeiro de layout encaixotado, mas já existe correção de largura para o
caso oposto — hospedeiro que dimensiona por encolhimento (`shrink-to-fit`),
por exemplo um container flex em coluna com `align-items: center`. Reproduza
essa cadeia e confirme que a mudança desta receita **não** a afeta: mesma
largura antes e depois, e o `style` que a correção de encolhimento escreve no
nó — string **idêntica**, caractere por caractere — antes e depois. Nenhum
`style` a mais nem a menos em nenhum ponto do DOM do hospedeiro. Sem esse
controle, a correção do teto de largura pode silenciosamente quebrar o
mecanismo que já resolvia o outro sentido do problema.

## 6. Quem usa hoje

| Produto | Versão | Situação |
| --- | --- | --- |
| **V3REvent** | `1.89.0` | Corrigido — imprime os dois nós. |
| **V3RLGPD** | atual | **Pendente.** `Frontend\Gestao::shortcode()` ainda imprime nó único; mesma raiz ancorada em id que expôs o defeito no V3REvent. Latente até rodar num tema de layout encaixotado. |

Varredura feita em 16/09/2026 (`V3RCore-Code#52`): V3RProp e V3RHelp não têm
painel embutido no frontend por esta receita — nenhum dos dois ancora
`all: initial` em CSS ou PHP para essa finalidade. Os demais plugins da
família não oferecem painel no frontend.
