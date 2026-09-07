# Navegação do painel: contrato de declaração

> Contrato do que um plugin declara para ganhar a navegação da família pronta —
> entrada única no menu do WordPress e navegação inteiramente dentro da tela.
> Issue `V3RCore-Code#35`. Vizinha: `#25` (posição, nome e ícone da entrada).
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

### A declaração é acumulativa

`Registry::add()` é chamado por **qualquer parte do plugin**, quantas vezes for
preciso. Não existe lista central. É o que o V3RHelp já faz hoje, e sem isso ele
perderia a modularidade que é a razão do desenho dele.

Ordem de declaração não importa: a árvore é ordenada por `order` (grupos) e pela
ordem de inserção dentro de cada grupo.

## 3. Quem responde "esta pessoa pode ver esta tela?"

A biblioteca **não** pergunta ao WordPress. Ela pergunta a um respondente que o
plugin fornece:

```php
interface ScreenAccess {
    public function canView( string $permission ): bool;
}
```

Duas implementações, e o plugin escolhe uma:

| Implementação | Para quem |
| --- | --- |
| `CapabilityAccess` (padrão) | Quem usa capability nativa: GE Associados, V3REvent, V3RLicense, V3RHelp, V3RProp. Resolve por `current_user_can()`. |
| A do próprio plugin | Quem tem matriz de papéis editável pelo cliente: V3RLGPD e RIT360 Premiado. |

⚠️ **Sem isto, adotar o componente rebaixaria esses dois produtos** — é o modo de
falha que a `#35` manda evitar.

**Respostas guardadas por requisição.** Quem usa matriz de papéis será consultado
muitas vezes ao desenhar uma tela; sem cache, navegação vira enxurrada de
consultas. O cache vive só durante a requisição e não persiste.

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

## 6. A entrada no menu do WordPress

O plugin declara **uma** entrada, e a família resolve o resto:

```php
$navigation->registerMenu(
    new MenuEntry(
        title:  'RIT360 Flow',
        slug:   'v3rflow',
        family: Family::RIT        // define o ícone; ver docs de #25
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

⚠️ **Fora do escopo desta camada:** a posição na coluna do painel e a
reordenação que mantém os dois blocos contíguos. É a `#25`, e vem depois.

## 7. O que esta camada NÃO faz

Não desenha barra, cabeçalho, abas nem o atalho de busca. Não define estilo,
fonte nem espaçamento. Tudo isso é a camada de desenho, que espera a `#26`.

Um plugin pode consumir **só** esta camada e continuar desenhando a própria
barra com a árvore que recebe — e é assim que a adoção começa, plugin a plugin.
