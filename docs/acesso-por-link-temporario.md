# Acesso por link temporário verificado por e-mail

> Catálogo do que a v3r-core oferece para a área de autoatendimento em que a
> pessoa entra por um link enviado ao próprio e-mail — e, principalmente, do
> que ela **não** oferece de propósito. Issue `V3RCore-Code#24`. Consumidores:
> RIT360 Solidário (`RIT360-Solidario-Code#66`, migra o que já tem em
> produção) e V3REvent (`V3REvent-Code#151`, nasce consumindo).

## 1. O que sobe para a biblioteca

Duas peças, ambas agnósticas e ambas em `V3R\Core\Access\`:

| Peça | Responsabilidade |
| --- | --- |
| `AccessToken` | Emissão do segredo (32 bytes, base64url), derivação do `sha256` e comparação em tempo constante. |
| `AttemptLimiter` | Limite de tentativas por duas chaves — identificador e origem —, com janela e teto configuráveis, sobre `KeyValueStoreInterface`. |

O critério para estar aqui é duplo: **não tocar identidade nenhuma** e **errar
ter consequência de segurança**. Tudo que não passa nos dois fica no produto.

## 2. O que NÃO sobe, e por quê

Não sobem o serviço de link mágico inteiro, a sessão, a tabela de tokens, o
consumo atômico, nem qualquer conceito genérico de "sujeito" a que o acesso
pertence. O motivo é concreto: **os dois produtos discordam no modelo de
identidade**.

- **Solidário** — doador com e-mail único e id estável; a sessão carrega
  **uma** pessoa e as consultas partem desse id.
- **V3REvent** — não existe equivalente: o mesmo e-mail pode ser responsável
  de N inscrições **e** participante de M inscritos, em eventos diferentes. A
  sessão carrega um **conjunto**, e cada documento valida posse contra ele.

Generalizar a partir de dois casos que divergem exatamente no ponto que a
abstração precisaria fixar produz a abstração errada — a que serve mal aos
dois e é cara de desfazer. As duas peças acima não tocam identidade nenhuma,
e é por isso que elas sobem e o resto não.

## 3. `AccessToken`

```php
use V3R\Core\Access\AccessToken;

// Emissão: o texto puro vai no e-mail; o que se persiste é o hash.
$token = AccessToken::generate();
$this->links->insert( $token->hash(), $expiraEm );      // no seu repositório
$this->email->enviarLink( $destinatario, $token->plaintext() );

// Retorno: reconstrói a partir do que chegou e procura pelo hash.
$recebido = AccessToken::fromPlaintext( $tokenDaUrl );
$registro = $this->links->findByHash( $recebido->hash() );
```

Regras que a classe existe para tornar difíceis de quebrar:

- **O texto puro é efêmero.** Quem guarda o token guarda `hash()`. Vazamento
  da tabela não entrega acesso a ninguém, porque o que está lá não é o que a
  URL leva.
- **Comparação por `matches()`**, que usa `hash_equals()`. Comparar com `===`
  abriria diferença de tempo mensurável entre um hash que erra no primeiro
  byte e outro que erra no último.
- **Texto puro vazio é recusado** — o `sha256` de `""` é um hash válido, e
  procurá-lo na base é consulta que nunca deveria sair.

## 4. `AttemptLimiter`

```php
use V3R\Core\Access\AttemptLimiter;
use V3R\Core\Licensing\Storage\WordPressTransientStore;

$limiter = new AttemptLimiter( new WordPressTransientStore(), 'v3revent_acesso' );

// UMA chamada por tentativa, ANTES de saber se o e-mail existe.
if ( $limiter->registerAttempt( $email, $ip ) ) {
    $this->servicoDeLink->solicitar( $email, $ip, $userAgent );
}

// A resposta ao usuário é a MESMA nos dois ramos.
$this->redirecionarComAvisoGenerico();
```

### 4.1 O detalhe que não pode se perder na próxima refatoração

**O registro da tentativa é incondicional**: `registerAttempt()` incrementa as
duas chaves sempre, e só então devolve se a tentativa estava dentro da cota. A
decisão é lida **antes** do incremento, nunca no lugar dele.

Por isso a classe **não** expõe um `check()` separado. Com dois métodos
públicos, o ponto de chamada pode incrementar dentro do `if` — e aí o próprio
bloqueio vira oráculo de existência: quem enumerasse veria a diferença entre
um e-mail que consome cota e outro que não consome, por comportamento ou por
temporização. Aqui esse erro não tem como ser escrito, e há teste que morde se
alguém tentar (`testTentativaRecusadaTambemIncrementaAOutraChave`).

Pelo mesmo motivo, a leitura dos dois contadores **não** tem curto-circuito: o
número de leituras e escritas no armazenamento é idêntico numa tentativa
permitida e numa recusada.

### 4.2 Comportamento

- **Janela deslizante** — cada tentativa renova a expiração; insistência
  permanece bloqueada em vez de reabrir a cada expiração parcial.
- **Nada identificável na chave** — identificador e origem são normalizados e
  derivados em `sha256`; nome de transient é legível na base.
- **Origem vazia agrupa todo mundo num balde só** — falha fechando.
- **`resetIdentifier()` / `resetOrigin()`** para a tela de suporte, quando
  alguém legítimo esbarra no teto e não pode esperar a janela.
- Padrões: janela de 900s, teto de 3 tentativas, ambos configuráveis.

## 5. O que continua sendo do produto — e como fazer certo

A biblioteca entrega as duas peças acima; o fluxo completo é de cada produto.
O que segue já está resolvido e em produção no Solidário, e vale reproduzir:

1. **Uso único protegido contra corrida** — o consumo é um UPDATE condicional
   ("marque como usado *se* ainda estiver ativo") que devolve "corrida" quando
   afeta 0 linhas. A checagem prévia de validade é conveniência para a
   mensagem; **a garantia é o UPDATE atômico**. Isto não sobe porque depende
   da tabela, que é do produto — um armazenamento chave/valor não dá
   atomicidade equivalente.
2. **Auditoria gravada sempre** — antes de saber se a pessoa existe.
3. **E-mail sintaticamente inválido sai pela mesma tela dos demais** — e, de
   preferência, passando pelo limitador também.
4. **O token não permanece na URL** — chega por query string e é imediatamente
   trocado por cookie `HttpOnly` / `SameSite=Lax`, com a URL limpa. É a
   diferença entre um endereço que vaza em histórico, favoritos e `Referer` e
   um que não vaza.
5. **TTL, teto de links ativos por pessoa e limpeza por cron** — no Solidário,
   24h e 3 links ativos.
6. **Posse validada a cada documento** — a sessão diz quem entrou; ela não diz
   que aquele recibo, certificado ou inscrição é dessa pessoa.

⚠️ **Um defeito do Solidário que NÃO deve ser copiado:** lá o link do e-mail é
montado com o endereço da página **fixo no código**, enquanto o resto do fluxo
usa a fonte única criada justamente para corrigir isso. Está no meio de código
bom, e por isso é fácil copiar junto.

## 6. Versão

Disponível a partir da tag `v0.8.0`. Consumidores fixam a tag — "a main
andou" não é "a versão saiu".
