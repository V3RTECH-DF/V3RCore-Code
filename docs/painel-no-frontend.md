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
<div class="meuproduto-gestao-host">
  <div id="meuproduto-front-root">
    <!-- painel React monta aqui -->
  </div>
</div>
```

```css
/* folha de estilos do produto — nunca inline */
#meuproduto-front-root {
  all: initial;
  display: block;
  box-sizing: border-box;
  width: 100%;
  max-width: 100%;
  min-width: 0;
}
```

- **O invólucro externo é identificado por classe, nunca por id**, e não
  recebe nenhum estilo nosso. Um `id` vale especificidade **1-0-0** —
  exatamente a que causou o defeito original (§3) — e deixaria à mão, para
  quem mexer depois, a ferramenta de declarar largura ali e reabrir o mesmo
  problema um nível acima; uma classe **empata** com a regra
  `.is-layout-constrained` do WordPress em vez de **vencê-la**. É o tema do
  hospedeiro quem decide a largura do invólucro, exatamente como decidiria
  para qualquer outro bloco de conteúdo da página.
- **A raiz interna mantém o `id`** — ali ele tem função: é a âncora que o
  `cascadeFix` usa para localizar o nó. O **isolamento é declarado na folha
  de estilos do produto, nunca inline** no atributo `style`: um `style`
  inline vale especificidade **1-0-0-0** e venceria até os utilitários do
  próprio painel, podendo bloquear um dia um utilitário legítimo na própria
  raiz — com o sintoma aparecendo longe da causa. O argumento do lampejo de
  conteúdo sem estilo não se aplica aqui: o shortcode imprime a raiz
  **vazia**, e o conteúdo só existe quando o aplicativo monta, depois de a
  folha já ter carregado. `style` inline na raiz continua legítimo para
  **dado da organização** (por exemplo, a fonte escolhida pelo cliente) — o
  que não vai inline é o isolamento.

No V3REvent a marcação é versionada em código, não solta no shortcode:
`Frontend\Gestao::root_markup()` devolve exatamente
`<div class="v3revent-gestao-host"><div id="v3revent-front-root"></div></div>`,
com o isolamento declarado na folha do produto (não inline). Um teste puro
acompanha a função e prende a garantia central desta receita: a raiz isolada
nunca pode voltar a ser o filho direto dimensionado pelo tema — o teste
reprova contra a marcação antiga (nó único) e passa contra a atual. Vale
reproduzir a mesma ideia — função nomeada que devolve a marcação, com teste
que trava a regressão — em qualquer plugin que adotar a receita.

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

## 5. Consequência da marcação de dois nós: a correção por encolhimento passa a começar no invólucro

Medido agora na adoção do V3RLGPD (`V3RLGPD-Code#122`; a do V3RCore é a
`V3RCore-Code#52`).

O mecanismo que o produto tem para hospedeiro que dimensiona por encolhimento
— o que sobe a partir da raiz procurando o ancestral `shrink-to-fit` e escreve
`width: 100%; place-self: stretch` nele (ver §4, segundo ponto, e o controle
negativo do §6) — encontra, agora, o **invólucro** como primeiro nível da
cadeia, porque é ele o pai imediato da raiz.

Em hospedeiro flex com `align-items: center`, o invólucro é item flex e
**recebe esse `style`** — que antes ia para outro nó, ou não era escrito,
porque a raiz sozinha já preenchia o espaço.

**Isso é esperado e aceito.** O critério "nenhum `style` a mais" vale para o
DOM do **hospedeiro**, que é o que não podemos mexer; o invólucro é nó nosso,
e escrever nele é exatamente o mecanismo que já existe para este caso — não
uma regressão a evitar.

A alternativa — fazer a correção **pular** o invólucro e começar no ancestral
seguinte — foi **recusada**: deixaria justamente o nó que virou item flex sem
esticar, e em outro hospedeiro o painel sairia estreito de novo; seria trocar
um defeito latente por outro.

## 6. Como conferir numa adoção

Ao portar esta receita para um plugin, meça **dois pontos, dois estados**:

1. **Largura e o atributo `style`** do painel **e** de um vizinho (o
   cabeçalho do site, ou outra página comum) — num tema de layout
   encaixotado. Os dois devem coincidir; o painel não pode destoar da faixa
   central do site.
2. **O mesmo par, num hospedeiro dimensionado por encolhimento** (o caso que
   motivou a correção de largura original da receita). A largura final tem
   de ser **idêntica** à de antes da adoção — inclusive quando o `style`
   dessa correção passa a ser escrito no invólucro em vez de em outro nó ou
   de não ser escrito (§5): o que se exige é **nenhum `style` a mais em nó
   do hospedeiro**, não "nenhum `style` a mais em nó nenhum".

Prova por comparação, nunca por aparência de tela: medir só o painel, sem o
vizinho, não discrimina o defeito — as duas larguras erradas também "parecem"
uma tela normal.

**Controle negativo obrigatório.** Esta receita nasceu para corrigir um
hospedeiro de layout encaixotado, mas já existe correção de largura para o
caso oposto — hospedeiro que dimensiona por encolhimento (`shrink-to-fit`),
por exemplo um container flex em coluna com `align-items: center`. Reproduza
essa cadeia e confirme que a mudança desta receita **não** a quebra: a
largura final do painel precisa ser **idêntica** à de antes. O que não pode
acontecer é `style` a mais ou a menos em nó do **hospedeiro** — o DOM que não
é nosso e que não podemos alterar. No invólucro (nó nosso), a correção por
encolhimento passar a escrever `style` onde antes não escrevia — ou escrevia
em outro nó — é esperado e não é falha deste controle: é a consequência
descrita no §5. Sem este controle, uma regressão na correção do teto de
largura poderia silenciosamente quebrar o mecanismo que já resolvia o outro
sentido do problema.

## 7. Quem usa hoje

| Produto | Versão | Situação |
| --- | --- | --- |
| **V3REvent** | `1.89.0` | Corrigido — imprime os dois nós. |
| **V3RLGPD** | `1.79.3` (16/09) | Publicado — imprime os dois nós, com a marcação desta receita. |

Varredura feita em 16/09/2026 (`V3RCore-Code#52`): V3RProp e V3RHelp não têm
painel embutido no frontend por esta receita — nenhum dos dois ancora
`all: initial` em CSS ou PHP para essa finalidade. Os demais plugins da
família não oferecem painel no frontend.
