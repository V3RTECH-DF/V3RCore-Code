# Navegação do painel: contrato de declaração

> Contrato do que um plugin declara para ganhar a navegação da família pronta —
> entrada única no menu do WordPress e navegação inteiramente dentro da tela.
> Issue `V3RCore-Code#35`. Vizinha: `#25` (posição, nome e ícone da entrada), resolvida em `MenuOrder` (§6).
>
> Esta é a **camada de governo**: PHP puro. Ela não desenha nada e **não depende
> da `#26`** (a biblioteca ainda não distribui peça de interface). A barra em si
> vem depois; o que está aqui já entrega a árvore pronta e o bloqueio de acesso.

## 1. O problema que o contrato resolve

Oito plugins da casa montam a navegação cada um do seu jeito. O que quebra na
prática não é a regra de permissão estar errada: é **alguém acrescentar uma tela
nova à navegação e esquecer de proteger o endereço dela**.

Daí a forma do contrato: **o plugin declara cada tela uma vez**, e a mesma
declaração alimenta as duas coisas — o que aparece na navegação e o que a URL
direta permite acessar. Não há como declarar uma e esquecer a outra.

## 2. O que o plugin declara

```php
use V3R\Core\Admin\Nav\Screen;
use V3R\Core\Admin\Nav\Registry;

// Em qualquer módulo do plugin, durante o boot do admin.
$registry->add(
    new Screen(
        slug:       'pessoas-cadastro',
        label:      'Cadastro',
        group:      'pessoas',          // opcional: sem grupo, a navegação é plana
        permission: 'gea_manage_people', // a chave que o motor de permissão entende
        order:      10                  // opcional: ordena entre irmãos, ver §5
    )
);
```

Um grupo é declarado à parte, só com rótulo e ordem:

```php
$registry->addGroup( new Group( key: 'pessoas', label: 'Pessoas', order: 20 ) );
```

**O grupo não tem permissão própria.** Ele aparece se ao menos uma tela dentro
dele aparecer, e some quando nenhuma sobra. Visibilidade de grupo é sempre
derivada dos filhos — ver §5.

### Tela oculta

Uma tela pode ser declarada com `hidden: true`. Ela continua registrada,
guardada e endereçável como qualquer outra — só não entra na árvore que
`tree()` devolve. É a saída para quem roteia no cliente (§4) e precisa
declarar rota transitória ou fora do menu sem abrir mão da guarda.

### Superfície

Um plugin pode desenhar navegação em mais de um lugar a partir das **mesmas**
telas declaradas — o caso que motivou esta opção (adoção do V3RLGPD,
07/09/2026): o painel do wp-admin, com 8 entradas, e uma página do site
público do cliente, renderizada por shortcode, com 6. A página pública não
oferece Configurações, Manual, Onboarding, o assistente inicial nem tipos de
documento — **deliberadamente**, porque essas telas simplesmente não existem
ali, não porque a permissão as recuse.

```php
$registry->add(
    new Screen(
        slug:       'config-geral',
        label:      'Configurações',
        group:      null,
        permission: 'v3rlgpd_manage_settings',
        surfaces:   [ 'painel' ]   // não existe na página pública
    )
);
```

`surfaces` é **opcional**, e os rótulos (`'painel'`, `'publico'`, ou o que o
plugin escolher) são strings livres do produto — a biblioteca não sabe o que
é "painel" ou "público", só compara a string pedida com as declaradas.
**Tela que não declara nenhuma superfície existe em todas** — é o padrão, e
nada do que já foi declarado antes desta opção existir muda de comportamento.

### A declaração é acumulativa

`Registry::add()` é chamado por **qualquer parte do plugin**, quantas vezes for
preciso. Não existe lista central. É o que o V3RHelp já faz hoje, e sem isso ele
perderia a modularidade que é a razão do desenho dele.

Ordem de declaração não importa: a árvore é ordenada por `order` (grupos) e pela
ordem de inserção dentro de cada grupo.

## 3. Quem responde "esta pessoa pode ver esta tela?"

A biblioteca **não** pergunta ao WordPress. Ela pergunta a um respondente que o
plugin fornece a `Navigation`, no construtor.

**A forma recomendada é uma função:**

```php
$navigation = new Navigation(
    $registry,
    static function ( string $permission ): bool {
        return current_user_can( $permission );
    }
);
```

Por quê: `implements ScreenAccess` obrigaria o plugin a declarar a classe
condicionalmente, porque a biblioteca pode estar **presente e ainda não
prefixada** — estado normal logo após um clone, até alguém rodar a
prefixação (`integracao-em-plugin.md` §7). Escrever `implements` sobre uma
interface ausente é fatal error na ativação. `Bootstrap::withCapabilityDecider()`
já resolve o mesmo problema assim; `Navigation` segue o mesmo padrão da casa.

**A interface continua existindo, como alternativa:**

```php
interface ScreenAccess {
    public function canView( string $permission ): bool;
}
```

Quem já implementa `ScreenAccess` (objeto) continua funcionando sem alteração
nenhuma — as duas formas são normalizadas internamente, e passar algo que não
é nem função nem `ScreenAccess` falha na construção de `Navigation`, com
mensagem que diz o que se esperava.

| Forma | Para quem |
| --- | --- |
| Função (recomendada) | Todo plugin novo, e qualquer um dos existentes que queira evitar `implements` condicional. |
| `CapabilityAccess` (objeto, padrão anterior) | Quem usa capability nativa: GE Associados, V3REvent, V3RLicense, V3RHelp, V3RProp. Resolve por `current_user_can()`. |
| Objeto próprio | Quem tem matriz de papéis editável pelo cliente: V3RLGPD e RIT360 Premiado. |

⚠️ **Sem uma das duas formas, adotar o componente rebaixaria esses dois
produtos** — é o modo de falha que a `#35` manda evitar.

**Respostas guardadas por requisição.** Quem usa matriz de papéis será consultado
muitas vezes ao desenhar uma tela; sem cache, navegação vira enxurrada de
consultas. O cache vive só durante a requisição e não persiste — vale para
função e para objeto: quem fornece o respondente é responsável por cachear a
própria resposta.

⚠️ **O respondente responde sobre o usuário CORRENTE — nunca sobre um
terceiro.** `current_user_can()`, a função/objeto de exemplo acima, e o
contrato de `canView()` inteiro pressupõem "a pessoa que está navegando
agora". `user_can( $outroUsuario, 'v3r_nav_<slug>' )` é uso normal do
WordPress e não é uma pergunta que esta camada saiba responder — a guarda do
§4 se cala nesse caso (não concede, não nega) em vez de inventar uma
resposta com base no usuário errado.

### ⚠️ Ciclo de vida: quem constrói o respondente é o plugin, e a biblioteca o reusa

`Navigation` não cria o respondente — recebe o que o plugin já construiu, e o
mesmo respondente serve `tree()`, `accessMap()` e a guarda de acesso direto
(§4). Um cache guardado dentro dele **vale por requisição se, e só se**, o
plugin criar **um respondente só e uma `Navigation` só**. Dois `Navigation`
construídos no mesmo ciclo com respondentes diferentes releem tudo duas
vezes em `tree()`/`accessMap()` — sem nada quebrar visivelmente, é só o
custo de não reaproveitar o cache de uma instância na outra.

**Construir `Navigation` mais de uma vez no mesmo ciclo é permitido — a
guarda de acesso direto é única por processo.** `NavCapabilityGate` agrega
as declarações de TODAS as `Navigation` construídas, e pendura um único
filtro `user_has_cap` por processo, qualquer que seja a quantidade de
instâncias. O que se paga por instância adicional é só releitura (parágrafo
acima), nunca concorrência entre respostas.

## 4. A guarda dupla

Sumir com o item de navegação é cosmético: quem digita o endereço entra. São
**três** camadas, e nenhuma substitui a outra:

⚠️ **Regra dura, aprendida com o RIT360 Flow (06/09/2026): rota de cliente sem
tela declarada não tem guarda nenhuma.** Quem roteia no cliente — painel
inteiro num endereço só, roteador do navegador decidindo o que desenhar —
precisa declarar **todas** as rotas, inclusive as transitórias e as que não
aparecem no menu, porque é a declaração que alimenta a guarda, não o menu. A
saída para não poluir a navegação é a tela oculta (§2), nunca deixar de
declarar.

1. **O WordPress barra o acesso direto.** Cada tela é registrada como página
   oculta (`add_submenu_page` com `parent_slug` vazio) com a permissão **dela**.
2. **A tela confere de novo** antes de desenhar qualquer coisa.
3. **Nova conferência depois da navegação dentro da própria tela**, sem recarregar
   a página. Em quem roteia no servidor, isto é reforço — a camada 1 já bloqueou
   o endereço. **Em quem roteia inteiramente no cliente** (painel numa página só,
   `HashRouter` e afins), o que vem depois do `#` **nunca chega ao servidor**: a
   camada 1 simplesmente não alcança essas rotas, e esta conferência do roteador
   é a **única** guarda que elas têm. `Navigation::accessMap()` (§5) é o dado que
   a torna possível — sem ele, o roteador não tem como saber, antes de desenhar,
   o que a pessoa corrente pode abrir.

### Como a camada 1 funciona com motor de permissão próprio

O WordPress só aceita uma **capability** ao registrar a página, e um motor
orientado a dados não tem capability nenhuma. A saída: a biblioteca registra a
página com uma capability sintética (`v3r_nav_<slug>`) e responde por ela no
filtro `user_has_cap`, delegando ao `ScreenAccess` do plugin.

Efeito: **o gate nativo do WordPress passa a funcionar mesmo para quem não usa
capability nativa** — sem que o plugin precise inventar capabilities de mentira,
e sem que a biblioteca trave em `current_user_can()`.

⚠️ A capability sintética **não** é permissão de verdade: ela só existe para
responder ao WordPress. Nada no plugin deve verificá-la diretamente.

⚠️ **`user_has_cap` dispara para QUALQUER usuário, não só o corrente** —
`user_can( $outro, 'v3r_nav_<slug>' )` é uso normal do WordPress, e é assim
que um plugin hospedeiro (ou outro código do próprio WordPress) pode
perguntar pela permissão de um terceiro. `ScreenAccess::canView()` responde
por contrato (§3) sobre "a pessoa corrente" — não sabe responder sobre
outra. Perguntada sobre alguém que não é o usuário corrente, a guarda **não
responde**: não concede, não nega, deixa `$allcaps` como estava. É o
fail-safe honesto — inventar resposta para quem o respondente não sabe
responder seria pior que se calar, e "conceder ou negar errado para
terceiros" é defeito de autorização, não de conveniência. Em outras
palavras: `user_can( $outro, 'v3r_nav_...' )` **não é** uma pergunta que
esta camada saiba responder.

### ⚠️ As capabilities sintéticas são POR PLUGIN — o Strauss não prefixa o valor de uma constante

Medido em produção em 07/09/2026, num WordPress com oito plugins da casa
instalados juntos (RIT360 Flow + V3RLGPD): a biblioteca é embutida em cada
plugin pelo Strauss, que prefixa **classes e namespaces** — mas **não
prefixa o valor de uma string**. A capability sintética da tela
(`v3r_nav_<slug>`) escapa desse risco porque o `<slug>` vem do plugin e
tende a ser único; a da **entrada raiz do menu**, antes uma constante fixa
da biblioteca, era a MESMA string em todas as cópias prefixadas.

Efeito: a guarda do plugin A respondia `true` para a raiz (ele enxerga
tela), e a guarda do plugin B — outra cópia, outro `add_filter()`, mas
reconhecendo a MESMA string como sua própria raiz — respondia `false` logo
em seguida (B não tinha tela visível para aquela pessoa), sobrescrevendo o
`true` de A no mesmo `$allcaps`. Quem respondia por último vencia: em
qualquer site com dois plugins da casa adotando esta camada, um cancelava a
entrada de menu do outro. Só a raiz falhava — as capabilities por tela já
eram únicas — e a reprodução com um plugin só nunca falhava, porque o
defeito só aparece com dois `add_filter()` concorrendo pelo mesmo
`$allcaps`.

**A correção:** a capability da raiz agora deriva do **slug do menu** que
cada plugin declara em `registerMenu()` (§6) — `v3r_nav_root_<menuSlug>` em
vez de uma string fixa. Como o slug do menu já precisa ser único por plugin
(é o `menu_slug` do próprio `add_menu_page()` do WordPress), a colisão
deixa de existir.

**E perguntada sobre a raiz de um slug que ela não reconhece — de outro
plugin, mesmo prefixo —, a guarda se cala:** não concede, não nega, não
mexe em `$allcaps`. É a mesma disciplina que já valia para a capability de
tela desconhecida (`v3r_nav_<slug>` sem tela correspondente registrada) —
sem essa disciplina, uma cópia da biblioteca continuaria respondendo (e
podendo sobrescrever) por capabilities que não são dela. É o que permite
oito plugins da casa conviverem no mesmo painel sem um apagar a entrada do
outro.

⚠️ `view_admin_dashboard` **não muda com esta correção** — continua
agregando TODAS as declarações do processo (ver abaixo), porque ela é do
ecossistema: várias cópias concedendo é inofensivo e correto, ao contrário
da raiz, que precisa ser exclusiva de um plugin.

### `view_admin_dashboard`: a saída para conviver com WooCommerce e afins

Medido em produção em 06/09/2026 (RIT360 Flow): num WordPress com WooCommerce
ativo, um usuário com papel próprio do plugin — que a árvore diz que pode ver
certas telas — era **redirecionado para fora do painel** antes de a camada 1
sequer agir. Telas negadas davam 403 corretamente; as **permitidas** davam 302.

Causa: o WooCommerce (`WC_Admin::prevent_admin_access()`) redireciona quem não
tiver nenhuma destas três capabilities: `edit_posts`, `manage_woocommerce`,
`view_admin_dashboard`. Papel próprio de plugin costuma ter só `read` mais as
capabilities do próprio plugin, e cai nesse bloqueio. Plugin de associação ou
área do cliente que restrinja o painel do mesmo jeito produz o mesmo efeito.

A correção: `NavCapabilityGate` também concede `view_admin_dashboard` a quem
enxerga ao menos uma tela (mesmo cálculo e mesmo cache que já respondiam pela
entrada raiz do menu, §6). Ela nunca é **negada** — só acrescentada quando
aplicável — porque não é capability nossa: é a saída que o próprio WooCommerce
desenhou para este caso, e conceder não dá direito a editar conteúdo nem a
mexer na loja.

### Superfície não filtra a guarda

⚠️ **O endereço é um só, e continua guardado pela permissão da tela,
independentemente de superfície.** O registro das páginas ocultas (camada 1)
e a resposta no filtro de capability (`user_has_cap`) não mudam com
`surfaces()` — `NavCapabilityGate` nunca consulta superfície nenhuma.
Superfície decide **onde a tela aparece** (§5) e **onde o roteador pode
abri-la** (`accessMap()` filtrado, camada 3); não desfaz o endereço, nem no
servidor nem no `ScreenAccess` que ele consulta. Uma tela declarada só para
o público continua barrada ou permitida pela mesma `permission()` de sempre,
como antes desta opção existir.

## 5. O que a biblioteca devolve

```php
$tree = $navigation->tree();   // já filtrada para o usuário corrente
```

A árvore sai pronta para o front, com grupos e telas que aquele usuário pode ver.
Regras que a construção garante:

- **grupo vazio não aparece** — some quando nenhuma tela dentro dele sobra;
- **grupo com uma tela só continua sendo grupo** — não é promovido a tela solta,
  para a navegação não mudar de forma conforme a permissão de cada pessoa;
- **sem nenhum grupo declarado, a árvore é plana** — navegação plana é caso de
  primeira classe, não degenerado. É o que V3REvent, V3RLicense e V3RHelp usam.
- **tela oculta não entra na árvore** — nem solta, nem dentro de grupo; grupo
  cujas telas restantes são todas ocultas some pela mesma regra do primeiro
  item. Ela continua guardada e endereçável (§4) e continua contando para
  "enxerga ao menos uma tela" (§6) — só a árvore não a lista.

### `tree()` e `accessMap()` por superfície

```php
$navigation->tree( 'publico' );          // só as telas da superfície 'publico'
$navigation->accessMap( 'publico' );     // idem, no mapa
```

Sem argumento (`null`), o comportamento é **exatamente** o de antes desta
opção existir — nenhum consumidor atual (RIT360 Flow, que nunca informa
superfície) sente diferença. Informada, só as telas que pertencem àquela
superfície entram — nem na árvore, nem no mapa aparecem as outras. Grupo
cujas telas visíveis, **naquela superfície**, ficam todas de fora não
aparece — a regra de grupo vazio (acima) vale por superfície, não só por
permissão.

⚠️ **Tela fora da superfície é OMITIDA do mapa, não incluída com `false`** —
e isso é o oposto da regra "sem permissão entra com `false`, nunca omitida"
(abaixo). A regra de baixo existe para distinguir "existe e você não pode"
de "não existe". Fora da superfície é, ali, o segundo caso: a tela
simplesmente não existe naquela superfície, então omitir é o que dá ao
consumidor a distinção certa — presente com `false` → "sem acesso"; ausente
→ "essa tela não existe aqui". As duas regras não se contradizem: cada uma
responde por um tipo de ausência diferente.

**`order` ordena entre irmãos, em qualquer nível.** Tela solta e grupo usam a
mesma escala no primeiro nível — é o que permite uma tela solta com `order`
declarada cair **entre** dois grupos (o caso do RIT360 Flow: `Painel · Pessoas
· Organizações · Fluxos · Configurações`, com Painel e Fluxos soltos entre
grupos). Dentro de um grupo, as telas se ordenam entre si do mesmo jeito. Sem
`order` declarada em lugar nenhum, o resultado é exatamente a ordem de
declaração — hoje continua assim.

⚠️ **Entre irmãos, declare `order` para todos ou para nenhum.** Misturar quem
declara com quem não compara duas escalas diferentes — valor declarado contra
posição de inserção — e o resultado surpreende. A biblioteca não lança exceção
nesse caso (ela roda dentro do `admin_menu` do WordPress, e exceção ali derruba
o painel inteiro do hospedeiro): a disciplina é do plugin que declara.

### `accessMap()`: o mapa que autoriza, ao lado da árvore que desenha

```php
$accessMap = $navigation->accessMap();
// [ 'pessoas-cadastro' => true, 'pessoas-relatorio' => false, 'fluxos-editor-legado' => true, ... ]
```

**Árvore e mapa não são a mesma lista, e servem a perguntas diferentes:**

| | `tree()` | `accessMap()` |
| --- | --- | --- |
| Para que serve | desenhar o menu | autorizar uma rota antes de desenhar |
| Telas ocultas | **de fora** (§2) | **incluídas** |
| Tela sem permissão | de fora (filtrada) | **dentro, com `false`** — nunca omitida |
| Formato | árvore de grupos e telas | mapa plano `slug => bool` |

Quem usa a árvore como fonte de autorização deixa exatamente as rotas ocultas
sem guarda — foi esse o defeito medido no RIT360 Flow (§4) que motivou o mapa.
Tela sem permissão entra com `false` em vez de sumir pelo mesmo motivo: omitida,
ela ficaria indistinguível de um slug que nunca foi declarado, e é exatamente
essa distinção que o roteador de quem roteia no cliente precisa para decidir.

`accessMap()` reaproveita o mesmo `ScreenAccess` e o mesmo cache por permissão
que `tree()` já paga (§3) — nenhuma tela custa uma consulta a mais só por
existir o mapa.

#### Rota ausente do mapa é rota **negada**

O mapa só conhece o que foi **declarado**. Rota que o roteador do consumidor
tenha e ninguém declarou não aparece nele — e a regra, que não é opcional, é
**falhar fechado**: desconhecido é negado.

Não é escolha nova: a biblioteca já decide assim do lado do servidor —
`Navigation::canView()` responde negativo para tela desconhecida. Seria
incoerente o mesmo sistema falhar fechado no servidor e deixar o cliente
decidir.

⚠️ **O custo do erro é assimétrico, e é isso que fecha o argumento:** falhar
fechado custa uma tela que não abre até alguém declará-la — visível na hora,
corrigida em uma linha. Falhar aberto custa uma tela de configuração aberta
para quem não devia, e ninguém percebe.

A peça `canOpen()` do pacote de front (`@v3rtech/v3r-front`) já implementa esta
regra — use-a em vez de reescrever a decisão em cada consumidor.

## 6. A entrada no menu do WordPress

O plugin declara **uma** entrada, e a família resolve o resto:

```php
$navigation->registerMenu(
    new MenuEntry(
        title:  'RIT360 Flow',
        slug:   'v3rflow',
        family: Family::RIT        // define o ícone e o bloco no menu (#25)
    )
);
```

O ícone vem da biblioteca (`src/Assets/brand/familia-rit.svg` e
`familia-v3rtech.svg`), não do plugin. Nenhuma entrada secundária visível é
criada: as telas existem como páginas ocultas, apenas para o gate de acesso.

**A entrada segue a mesma regra de visibilidade derivada dos filhos do §5:**
ela é o nó raiz da árvore, e só aparece para quem enxerga ao menos uma tela
dentro dela — nunca por uma capability nativa larga (`read`), que abriria a
entrada numa tela vazia para quem não pode ver nada.

A **posição na coluna** e a **contiguidade dos dois blocos da casa** são
resolvidas pela própria biblioteca, sem o plugin declarar nada além da
família — ver a seção abaixo.

### Os dois blocos contíguos (`MenuOrder`, #25)

As entradas da casa aparecem juntas na coluna do painel, em dois blocos em
sequência — **família RIT e depois família V3RTECH** —, **sem nenhum plugin
de terceiro entre elas**, e em **ordem alfabética pelo título** dentro de
cada bloco. Nada disso é declarado pelo plugin: `registerMenu()` já
anuncia a entrada e pendura a reordenação.

**Por que a posição pedida no registro não basta.** Ela é só um número, e
qualquer plugin de terceiro pode pedir um número dentro da nossa faixa.
Declarar posições vizinhas reduz a probabilidade de intromissão; não a
elimina. A contiguidade é garantida **depois** que todos os plugins se
registraram, pelo par de filtros `custom_menu_order`/`menu_order` do
próprio WordPress — o único momento em que a coluna inteira já existe.

⚠️ **O anúncio é compartilhado entre as cópias prefixadas, e é isso que faz
funcionar.** Cada plugin embute a própria cópia da biblioteca: a cópia de A
não enxerga o `Registry` de B. Uma reordenação que conhecesse só a própria
entrada faria cada cópia empurrar a sua e atropelar a decisão da anterior.
Por isso as entradas são anunciadas numa global de nome fixo
(`MenuOrder::GLOBAL_KEY`), deliberadamente **não** prefixada — e a
reordenação é uma função pura do conjunto anunciado mais a ordem recebida:
**idempotente** (rodar de novo devolve o mesmo resultado) e **convergente**
(todas as cópias calculam a mesma coisa). Quantas cópias pendurem o filtro,
e em que ordem rodem, deixa de importar.

É o **oposto deliberado** do defeito da capability de raiz (§4): lá, o valor
de texto igual em todas as cópias fazia um plugin responder pela pergunta do
outro, e a correção foi separar por plugin. A diferença é a natureza do
dado — capability é uma **resposta sobre uma pessoa**, e responder pelo
alheio é errado; posição no menu é um **fato sobre a coluna do site**, um só
para todo mundo, e a única forma de acertá-lo é todas as cópias partirem do
mesmo conjunto. A chave do anúncio é o slug do menu, que já é único por
plugin no WordPress — duas cópias nunca escrevem a mesma chave com
significados diferentes.

**O que a reordenação não faz:** não move entrada de terceiro para longe,
não esconde nada e não altera a ordem relativa entre as de terceiros. As de
fora ficam na ordem em que estavam; o bloco da casa é inserido inteiro onde
a **primeira** entrada nossa já estava. Com uma entrada nossa só na coluna,
nada é movido — não há bloco a formar, e mexer na posição seria decisão sem
motivo.

**Degradê:** se outro plugin desligar `custom_menu_order` depois de nós, a
reordenação não roda e valem as posições pedidas no registro
(`MenuOrder::POSITION_RIT` e `POSITION_V3RTECH`) — os dois blocos ainda saem
na ordem certa, apenas sem a garantia de contiguidade.

#### Plugin que ainda não adotou esta camada também entra no bloco

Agrupar só quem adotou a navegação inteira faria o benefício esperar a
adoção plugin a plugin — e, medido no WordPress de desenvolvimento com oito
plugins da casa instalados, **um adotante só não move nada**: a coluna
continua com os produtos da casa espalhados em quatro trechos, um deles
abaixo de Configurações.

Um plugin que ainda registra o menu do próprio jeito entra no bloco
anunciando a entrada que ele já registrou — uma linha, sem mudar navegação,
sem `Registry` e sem `Navigation`:

```php
MenuOrder::announce( new MenuEntry( 'V3RProp', 'v3rprop', Family::V3RTECH ) );
```

#### E o plugin que NÃO embute a biblioteca? O anúncio é contrato público

Existe produto da casa que não carrega a `v3r-core` em produção — o servidor
de licenças é um: a biblioteca é dependência de **desenvolvimento**, usada
só por teste de compatibilidade, e não vai no pacote. Para ele, "uma linha"
não seria uma linha: seria passar a biblioteca para produção, prefixar,
empacotar e assumir risco de boot num site institucional, para ganhar
agrupamento de menu.

⚠️ **Por isso o formato do anúncio é CONTRATO PÚBLICO, não detalhe interno** —
ele já é compartilhado entre as cópias prefixadas de todos os adotantes, que
é o que faz a reordenação convergir. Quem não embute a biblioteca escreve
direto, no `admin_menu`, ao lado do `add_menu_page()` que já tem:

```php
$GLOBALS['v3r_nav_family_menu_entries']['v3rlicense'] = array(
    'family' => 'v3rtech',   // 'rit' ou 'v3rtech'
    'title'  => 'V3RLicense',
);
```

**Garantias deste formato, para quem depende dele sem ter a classe:**

- a **chave é o slug do menu** — o mesmo passado a `add_menu_page()`;
- `family` é `'rit'` ou `'v3rtech'`; `title` é o nome exibido na coluna;
- **entrada malformada é ignorada na leitura**, nunca derruba o menu;
- **o nome da global e a forma do registro não mudam sem versão MAIOR.**

⚠️ **A limitação, e ela é real:** quem escreve direto **não pendura os
filtros de reordenação**. Num site onde algum adotante que embute a
biblioteca também anuncia, isso é irrelevante — os filtros já estão lá, e a
reordenação é função do conjunto anunciado, não de quem a pendurou. Num site
com **apenas** anunciantes diretos, nada reordena. É aceitável porque, com um
anunciante só, não há bloco a formar de qualquer maneira; mas dois
não-consumidores sozinhos no mesmo site não se agrupam.

**Prefira a fachada sempre que a biblioteca já estiver carregada.** A
escrita direta existe para quem não a tem — não é atalho para quem tem.

Ícone e posição continuam sendo os que o plugin declarou no próprio
`add_menu_page()` — o anúncio governa **só** o agrupamento. Trocar o ícone
pelo da família é decisão de quem adota a camada inteira.

⚠️ **Anuncie no ponto por onde TODOS os caminhos passam, nunca dentro de um
ramo condicional do registro de menu.** Plugin que registra menus diferentes
conforme o estado do produto (setup completo ou não, licença ativa ou não)
tem mais de um caminho até `add_menu_page()` — e anunciar dentro de um deles
faz a entrada sumir do bloco **exatamente nos sites que caem no outro ramo**,
que costumam ser os recém-instalados: o cenário que menos se testa e o que
mais se parece com "a peça não funciona". Medido pelo RIT360 Solidário na
adoção de 09/09/2026.

⚠️ **Anunciar dentro do `admin_menu` também vale** — e é onde o plugin que
registra o menu do próprio jeito naturalmente vai chamar. Funciona porque
`menu_order` roda **depois** de o `admin_menu` terminar; quem adota a camada
inteira anuncia mais cedo, no boot, por dentro do `registerMenu()`.

**Consequência para quem mede, e ela já enganou:** o conjunto anunciado só
está **completo ao fim do `admin_menu`**. Inspecionar a global antes disso
mostra os adotantes da camada e **não** mostra os da porta barata — e a
leitura natural do que falta é "aquele plugin não adotou", quando ele apenas
ainda não chegou a anunciar.

### ⚠️ Detectar esta camada: teste uma classe, nunca a interface

O padrão da casa para saber se a biblioteca chegou ao plugin é `class_exists()`
sobre um nome prefixado (ver `integracao-em-plugin.md` §7). Ao aplicá-lo a esta
camada, teste **`Navigation`**, que é classe.

`class_exists()` devolve `false` para **interface** — então
`class_exists( $prefixo . '\\V3R\\Core\\Admin\\Nav\\ScreenAccess' )` responde
"não existe" mesmo com a biblioteca presente e funcionando. O plugin conclui que
a camada não chegou e cai no caminho degradado **em silêncio**: nada quebra,
nada erra visivelmente, e a navegação simplesmente não é a compartilhada.

(Para interface existe `interface_exists()`; mas, para detecção, prefira a
classe — é um teste só, e não depende de quem lê lembrar da diferença.)

⚠️ **A armadilha não é desta camada, é do PHP** — vale para qualquer nome
que a biblioteca exporte, e reaparece a cada peça nova que tiver interface.
Por isso a regra não é "lembre-se do `ScreenAccess`", é: **detecção sempre
sobre classe.**

**Prenda a regra com um teste, que é o que impede a "simplificação" futura**
(contribuído pelo V3RProp na adoção de 09/09/2026): um teste que afirma que
`class_exists()` sobre a interface responde `false`. Ele documenta a
armadilha no lugar onde ela seria reintroduzida, e falha na cara de quem
trocar a detecção de classe por interface achando que dá no mesmo.

## 7. Substituir submenus antigos pela entrada única (`LegacyRedirects`)

Adotar esta camada troca vários submenus por **uma** entrada. Os endereços
antigos deixam de existir, e quem os tem salvos — exatamente quem usa a tela
todo dia — passa a receber **página em branco, sem erro, sem mensagem**.
Medido na adoção do V3RLGPD (07/09/2026), com sete plugins ainda por fazer a
mesma troca.

**O mapa é sempre do plugin**, e não há como derivá-lo automaticamente:
`v3rlgpd-atendimento` vira `/atendimento`, mas `v3rlgpd-docs` vira `/manual`
e a entrada raiz não vira nada. `Admin\Nav\LegacyRedirects` recebe esse mapa
(`slug antigo => destino`) e cuida do resto:

```php
$legacyRedirects = new V3R\Core\Admin\Nav\LegacyRedirects(
    'v3rlgpd',                                    // slug da entrada única
    array(
        'v3rlgpd-atendimento' => '/atendimento',  // rota interna (fragmento)
        'v3rlgpd-docs'        => 'https://ajuda.v3rtech.com.br/v3rlgpd', // URL absoluta
        'gea-charge-old'      => 'gea-charge-detail', // slug de outra página do painel
    )
);
$legacyRedirects->register();
```

**Destino em três formas, todas detectadas pelo próprio valor** — e **duas
delas preservam os demais parâmetros da requisição, uma não**:

- começando com `/` ou `#` — composto como fragmento sobre a entrada única
  (`?page=v3rlgpd&...#/atendimento`), **preservando** os demais parâmetros:
  `page=v3rlgpd-ropa&id=5` chega ao destino com o `id`, perder contexto de
  link profundo em silêncio é o mesmo tipo de defeito que esta peça existe
  para fechar;
- com esquema (`://`) — URL absoluta, usada exatamente como está, **sem**
  parâmetro nenhum acrescentado. Ela pode apontar para fora do site, e
  misturar parâmetro do WordPress ali não faz sentido — comportamento
  deliberado, mantido como está;
- qualquer outro valor — slug de **outra página do próprio painel**,
  composto como `?page=<slug>&...`, também **preservando** os demais
  parâmetros: é o caso do GE Associados, que roteia por endereço real em vez
  de fragmento — `?page=gea-charge-old&id=42` precisa chegar como
  `?page=gea-charge-detail&id=42`, não como `?page=gea-charge-detail` sem o
  `id`.

⚠️ **Só a URL absoluta não preserva parâmetro** — ler "preserva os demais
parâmetros" como comportamento da classe inteira, em vez de dois dos três
ramos, é o engano que este parágrafo existe para fechar.

⚠️ **Slug de página é reconhecido, não adivinhado.** Um destino que não é
rota interna nem tem esquema, mas contém `/` ou `.` (cara de caminho ou de
domínio digitado sem `https://`), é **recusado no construtor** —
`InvalidArgumentException` nomeando o destino, mesma política do laço de
redirecionamento logo abaixo. Corrigir o destino, ou antecedê-lo de
`https://` quando a intenção era mesmo uma URL absoluta.

⚠️ **O mapa é recusado na declaração, não no redirecionamento, se contiver o
slug da própria entrada única.** Mapear a entrada única para si mesma cria um
laço de redirecionamento infinito — não só uma tela quebrada, o painel
inteiro trava. `LegacyRedirects` lança `InvalidArgumentException` no
construtor, nomeando a entrada culpada; como a construção acontece no boot
do plugin (nunca dentro de `admin_menu`), falhar ali é seguro, e muito melhor
que travar o painel do hospedeiro em produção.

**Não decide permissão.** Ela só redireciona; quem barra é o destino — a
guarda de rota do cliente ou as camadas 1/2 desta mesma biblioteca (§4), que
já valem para toda rota, inclusive as que nunca tiveram endereço de submenu.
Repetir a decisão aqui criaria uma segunda fonte de verdade sem fechar buraco
nenhum. Consequência aceita: quem não pode ver a tela é levado até ela e
recebe a recusa **dentro do produto**, com mensagem melhor que o erro
genérico do WordPress.

### ⚠️ Preservar parâmetros **não** é a mesma proteção para os dois grupos

Para quem roteia **no servidor**, preservar os demais parâmetros resolve de
verdade: o link profundo salvo continua chegando inteiro ao destino.

**Para quem roteia no cliente, não cobre o caso equivalente.** Ali o estado
profundo vive **depois do `#`** — e o fragmento nunca chega ao servidor, então
nenhum redirecionamento feito aqui pode preservá-lo. Pior: os dois caminhos
possíveis perdem, e nem é escolha entre preservar e não preservar.

- **Destino com fragmento** (o caso normal, `#/tela`): o navegador **descarta** o
  fragmento que vinha no endereço salvo, e é impossível saber que ele existia.
- **Destino sem fragmento:** o navegador **carrega o fragmento antigo**, que
  aponta para uma rota que já não existe. O roteador recebe um endereço morto, o
  `canOpen` nega corretamente, e ninguém entende por quê.

O que a peça faz é o caminho que **perde de forma previsível**. Não é limitação
dela: é do meio.

⚠️ A armadilha de leitura, e é o motivo desta seção existir: quem lê "preserva
os demais parâmetros" e roteia por fragmento supõe que o link profundo dele está
coberto. **Não está.**

## 8. O que esta camada NÃO faz

Não desenha barra, cabeçalho, abas nem o atalho de busca. Não define estilo,
fonte nem espaçamento. Tudo isso é a camada de desenho, que espera a `#26`.

Um plugin pode consumir **só** esta camada e continuar desenhando a própria
barra com a árvore que recebe — e é assim que a adoção começa, plugin a plugin.
