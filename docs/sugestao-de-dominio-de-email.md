# Sugestão de correção de domínio de e-mail

> Catálogo da peça promovida do V3REvent (`V3REvent-Code#157`, v1.76.0) para a
> biblioteca em `V3RCore-Code#23`. Duas metades — núcleo PHP e espelho
> JavaScript — exercitadas pelo **mesmo** conjunto de casos. Disponível a
> partir da tag `v0.9.0`.

## 1. O problema, em uma frase

Endereço digitado errado (`fulano@gmail.con`) é aceito pelo formulário,
marcado como enviado e rejeitado depois pelo servidor de e-mail — sem o
plugin saber. Todo produto da casa com campo de e-mail tem o mesmo caso:
inscrição, doação, rifa, proposta, chamado, cadastro.

## 2. A regra inegociável: sugere, nunca bloqueia

`suggest()` devolve uma correção provável ou `null`, e nada mais. Quem decide
seguir com o valor digitado é sempre a pessoa que preenche o formulário, e o
chamador **nunca** troca o valor sozinho.

Validação agressiva rejeita endereço legítimo — domínio próprio, extensão
nova — e impede o cadastro: erro muito pior que o que se corrige. Pela mesma
razão, o **falso positivo é o erro caro** aqui: sugerir correção a quem
digitou certo leva a pessoa a "corrigir" para um endereço errado. É o grupo
de casos mais protegido do conjunto compartilhado.

## 3. As duas metades

| Metade | Arquivo | Papel |
| --- | --- | --- |
| Núcleo PHP | `V3R\Core\Support\EmailSuggestion` | Verificação no servidor; fonte da lista de domínios. |
| Espelho JS | `src/Assets/js/email-suggestion.js` | A sugestão que aparece **enquanto a pessoa digita** — é ela que tem valor para quem preenche. |
| Casos | `src/Assets/data/email-suggestion-cases.json` | Conjunto único, exercitado pelas duas. |

Sem a segunda metade o serviço fica pela metade: um núcleo que só verifica
depois do envio no máximo registra que já era tarde. E é o conjunto
compartilhado que impede as duas de descolarem — uma correção aplicada em só
um dos lados quebra o outro no mesmo commit.

## 4. Como consumir

### 4.1 Servidor

```php
use V3R\Core\Support\EmailSuggestion;

$dominios = apply_filters( 'meuplugin_dominios_email', EmailSuggestion::defaultDomains() );
$sugestao = EmailSuggestion::suggest( $email, $dominios );   // string ou null
```

A biblioteca **não** aplica filtro nenhum: hook é do produto. Ela entrega a
lista embutida e recebe a lista já resolvida — é o que mantém o núcleo puro e
testável sem WordPress.

### 4.2 Navegador

```php
use V3R\Core\Frontend\AssetLocator;

$assets = new AssetLocator();
$assets->enqueueScript( 'meuplugin-email-suggestion', 'js/email-suggestion.js' );

wp_localize_script( 'meuplugin-public', 'meuPluginPublic', [
    'emailSuggestionDomains' => $dominios,   // a MESMA lista já resolvida
] );
```

O script expõe o global `V3RCoreEmailSuggestion`, com `suggest(email, domains)`.
Ele é núcleo puro: **não toca DOM, não depende de jQuery**. Mostrar a
sugestão e aplicá-la no clique é do plugin — normalmente no `blur` do campo,
limpando a sugestão a cada `input`.

⚠️ **A lista tem de ser a mesma nos dois lados.** Se o servidor resolve a
lista por filtro e o navegador recebe outra, os dois passam a discordar em
silêncio — o caso que o conjunto compartilhado não pega, porque é erro de
integração, não de algoritmo.

## 5. O algoritmo, e as duas guardas que não são a mesma coisa

Distância de edição (Levenshtein) entre o domínio digitado e cada domínio
conhecido, com **limiar calibrado pelo comprimento do rótulo** (a parte antes
do primeiro ponto): rótulo de até 4 caracteres admite 1 edição; mais longo
admite 2 — o suficiente para pegar transposição de letras adjacentes
(`gmial`→`gmail`).

Há **duas** guardas contra falso positivo, e elas protegem de coisas
diferentes:

1. **A calibração** impede aceitar duas edições num nome curto — em três ou
   quatro letras, duas edições já são outro nome.
2. **A exclusão dos domínios de rótulo curto da lista padrão** (`uol`, `bol`,
   `aol`) é a única defesa contra vizinhos que distam **uma** edição entre si
   e de domínios próprios legítimos (`sol.com.br`). A calibração **não**
   separa esses — quem os acrescentar à lista faz com que passem a se sugerir
   mutuamente, e há teste que documenta exatamente isso.

Confundir as duas leva a achar que dá para reintroduzir os domínios curtos no
padrão. Não dá.

**Domínio já exato na lista nunca gera sugestão**, por mais perto que esteja
de outro. Com a lista padrão essa guarda é redundante (nenhum par dela dista
≤2); ela passa a valer assim que alguém acrescenta um domínio vizinho de
outro — e é aí que ela impede sugerir `aaac.com` para quem digitou o
`aaab.com` que está na própria lista.

## 6. Conjunto de casos compartilhado

`src/Assets/data/email-suggestion-cases.json` viaja no pacote (ver ADR-014 em
`docs/ARCHITECTURE.md`) e é consumido pelos testes das duas metades. Um
consumidor pode exercitá-lo contra a própria integração.

Formato: `dominiosPadrao` (idêntico a `defaultDomains()`, com teste que prende
a igualdade) e `casos`, cada um com `grupo`, `nome`, `email`, `esperado` e,
opcionalmente, `dominios` (quando o caso precisa de uma lista própria).

Grupos: `sugere`, `ja-correto`, `falso-positivo`, `formato`,
`lista-estendida`. Há teste que falha se um dos grupos encolher — o conjunto
não pode ser esvaziado sem alguém perceber.

## 7. O que fica com o produto

A cola com o formulário: onde a sugestão aparece, como é aplicada no clique,
o texto mostrado, a acessibilidade do aviso. E a decisão de qual filtro
estende a lista de domínios.
