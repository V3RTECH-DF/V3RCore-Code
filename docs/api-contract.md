# Contrato da API — v3r-license/v1

Spec do protocolo entre o cliente (esta biblioteca, embutida em cada plugin
distribuído) e o servidor de licenças V3RLicense. Escrito para que as duas
pontas possam ser implementadas de forma independente — servidor e cliente
(fatia 2 desta lib) — sem ambiguidade.

Namespace REST do WordPress: `v3r-license/v1` (o servidor de licenças é,
ele mesmo, um WordPress).

## 1. Convenções gerais

- Todas as respostas são JSON, `Content-Type: application/json`.
- Corpo de erro sempre no formato de erro da REST API do WordPress:

  ```json
  {
    "code": "invalid_key",
    "message": "Chave de licença não encontrada.",
    "data": { "status": 404 }
  }
  ```

- `code` é sempre um dos [códigos de erro](#3-códigos-de-erro) desta spec —
  nunca uma mensagem livre no lugar do código.
- Datas em ISO 8601 UTC (`2026-08-25T00:00:00+00:00`).
- `site_url` chega como o cliente enviou; o servidor normaliza (remove
  protocolo, `www.`, porta, barra final, caixa) antes de comparar ou gravar —
  mesma normalização de `V3R\Core\Support\SiteIdentity::normalizeDomain()`.
  Domínios de teste/desenvolvimento (ver `SiteIdentity::isTestEnvironment()`)
  são ativados normalmente, mas **não consomem cota** de `activations_max`.
- Toda resposta de `/activate` e `/validate` vem assinada (ver
  [§4 — Assinatura](#4-assinatura-da-resposta)); `/update-check` também.
  `/deactivate` não precisa de assinatura (não expressa "licença válida").

## 2. Endpoints

### 2.1 `POST /activate`

Ativa uma licença para um site.

**Entrada** (JSON body):

| Campo             | Tipo   | Obrigatório | Descrição |
|-------------------|--------|:---:|-----------|
| `license_key`     | string | sim | Chave de licença. |
| `product_slug`    | string | sim | Slug do produto (ex.: `v3rlgpd`). |
| `site_url`        | string | sim | URL do site, sem normalização prévia. |
| `plugin_version`  | string | sim | Versão instalada do plugin (semver). |
| `php_version`     | string | sim | `PHP_VERSION` do site cliente. |
| `wp_version`      | string | sim | Versão do WordPress do site cliente. |

**Saída** — `200 OK`:

```json
{
  "payload": {
    "status": "active",
    "expires_at": "2027-08-25T00:00:00+00:00",
    "activations_used": 2,
    "activations_max": 5,
    "product_slug": "v3rlgpd",
    "already_activated": false,
    "checked_at": "2026-08-25T12:00:00+00:00"
  },
  "signature": "base64..."
}
```

| Campo               | Tipo         | Descrição |
|---------------------|--------------|-----------|
| `status`             | string       | Um de: `active`, `expired`, `revoked`, `invalid`. |
| `expires_at`         | string\|null | ISO 8601, ou `null` para licença sem expiração. |
| `activations_used`   | int          | Cota consumida (não inclui domínios de teste). |
| `activations_max`    | int\|null    | `null` = ilimitado. |
| `product_slug`       | string       | Eco do produto ativado. |
| `already_activated`  | bool         | `true` quando o domínio informado já estava ativado para esta licença e produto — nenhuma cota nova foi consumida. `false` quando esta chamada ativou o domínio agora. |
| `checked_at`         | string       | Instante em que o servidor gerou esta resposta. |

**Idempotência:** se o domínio normalizado já está ativado para a mesma
licença e produto, `/activate` responde `200 OK` com o estado atual e
`already_activated: true`, sem consumir cota nova e sem erro — mesmo que
`activations_max` já esteja no limite. Reativar um site que já era seu não
pode falhar por cota cheia. `activation_limit_reached` só se aplica a um
domínio **novo** quando a cota já está esgotada.

**Erros possíveis:** `invalid_key`, `product_mismatch`, `activation_limit_reached`,
`license_expired`, `license_revoked`, `rate_limited`.

### 2.2 `POST /deactivate`

Libera a cota de ativação de um domínio.

**Entrada:**

| Campo          | Tipo   | Obrigatório |
|----------------|--------|:---:|
| `license_key`  | string | sim |
| `product_slug` | string | sim |
| `site_url`     | string | sim |

**Saída** — `200 OK`:

```json
{ "deactivated": true }
```

**Erros possíveis:** `invalid_key`, `domain_not_activated`, `rate_limited`.

### 2.3 `POST /validate`

Checagem periódica do estado da licença (política de cache no cliente:
ver [§5](#5-política-de-cache-no-cliente)).

**Entrada:**

| Campo          | Tipo   | Obrigatório |
|----------------|--------|:---:|
| `license_key`  | string | sim |
| `product_slug` | string | sim |
| `site_url`     | string | sim |

**Saída** — mesmo formato de `/activate` (`payload` + `signature`), com o
estado corrente da licença — inclusive quando `status` for `expired` ou
`revoked` (não é erro HTTP; é um payload assinado dizendo "isto é o que é
verdade agora").

**Erros possíveis:** `invalid_key`, `domain_not_activated`, `product_mismatch`,
`rate_limited`.

### 2.4 `GET /update-check`

Metadados da versão disponível, ou "nenhuma atualização".

**Entrada** (query string):

| Campo             | Tipo   | Obrigatório | Descrição |
|-------------------|--------|:---:|-----------|
| `product_slug`    | string | sim | |
| `license_key`     | string | sim | |
| `site_url`        | string | sim | |
| `plugin_version`  | string | sim | |
| `version`         | string | não | Versão específica a consultar/baixar (rollback). Ausente = a mais recente disponível para o produto. Presente = o token de download (ver `package_url` abaixo) é emitido para exatamente essa versão. |

**Saída quando há atualização** — `200 OK`:

```json
{
  "payload": {
    "update_available": true,
    "version": "2.3.0",
    "requires": "6.0",
    "requires_php": "8.0",
    "tested": "6.7",
    "changelog_url": "https://v3rtech.com.br/plugins/v3rlgpd/changelog",
    "package_url": "https://.../download?token=...",
    "checked_at": "2026-08-25T12:00:00+00:00"
  },
  "signature": "base64..."
}
```

**Saída quando não há atualização** — `200 OK`:

```json
{
  "payload": {
    "update_available": false,
    "checked_at": "2026-08-25T12:00:00+00:00"
  },
  "signature": "base64..."
}
```

`package_url` não é o zip em si — é a URL que o cliente chama em
`/download` (ver §2.5), no formato `.../download?token=<token>` e **só**
esse parâmetro. O token é opaco (aleatório, ≥32 bytes, base64url), gerado
por este `/update-check` e amarrado no servidor a: licença, produto,
domínio normalizado e a versão específica resolvida (a pedida em
`version`, ou a mais recente quando ausente) — `/download` não recebe mais
`license_key`, `product_slug` nem `site_url` (ver §2.5). Validade: **30
minutos**, com múltiplos usos permitidos dentro da janela (uso único
quebraria a retentativa do próprio WordPress num download interrompido).
Ao revogar uma licença, os tokens dela são invalidados junto.

**Erros possíveis:** `invalid_key`, `product_mismatch`, `license_expired`,
`license_revoked`, `rate_limited`. **Importante:** licença expirada/revogada
resulta em erro aqui (não em `update_available: false`) — o cliente precisa
distinguir "sem novidade" de "sem direito a atualizar" para decidir o que
mostrar na UI, mas em ambos os casos o `V3R\Core\Updater\UpdateGate` decide
"não recebe update" e o plugin continua funcionando normalmente.

### 2.5 `GET /download`

Entrega o zip da versão, ou nega com motivo. Recebe **só** o token
efêmero emitido por `/update-check` — nenhum outro parâmetro. Chave de
licença em query string vazaria em log de acesso, log de proxy e cabeçalho
`Referer`; com o token, tudo (licença, produto, domínio, versão) já está
amarrado no servidor.

**Entrada** (query string):

| Campo    | Tipo   | Obrigatório |
|----------|--------|:---:|
| `token`  | string | sim |

**Saída de sucesso:** `200 OK`, `Content-Type: application/zip`, stream do
arquivo. Não é JSON.

**Saída de negação:** `403 Forbidden`, corpo no formato de erro padrão
(§1), com `code` explicando o motivo: `invalid_token` (token inexistente,
expirado, ou de licença já revogada), `license_expired`, `license_revoked`,
`rate_limited`. Uma negação distinta, com HTTP diferente, é
`release_unavailable` (`503 Service Unavailable`): o token é válido e a
licença está em dia, mas o arquivo da versão não está disponível no
servidor no momento (falha operacional nossa — release cadastrado sem o
zip gravado — nunca um problema do token ou da licença do cliente).

## 3. Códigos de erro

| Código                     | HTTP | Significado |
|----------------------------|:---:|-------------|
| `invalid_key`              | 404 | Chave não existe ou está malformada. |
| `activation_limit_reached` | 409 | `activations_max` já atingido para esta licença (domínio novo; reativar domínio já ativado nunca cai aqui — ver §2.1). |
| `domain_not_activated`     | 404 | O domínio informado não tem ativação registrada para esta licença. |
| `product_mismatch`         | 400 | A chave é válida, mas não é para o `product_slug` informado. |
| `license_expired`          | 403 | Licença encontrada, mas `status = expired`. |
| `license_revoked`          | 403 | Licença encontrada, mas `status = revoked` (reembolso/cancelamento). |
| `invalid_token`            | 403 | Token de `/download` inexistente, expirado, ou pertencente a licença já revogada. |
| `release_unavailable`      | 503 | Token e licença válidos, mas o arquivo da versão não está disponível no servidor (falha operacional, não do cliente). |
| `rate_limited`             | 429 | Excesso de tentativas para este IP ou esta chave (ver §4.6 da pesquisa). |

Todos seguem `{ code, message, data: { status } }` — `data.status` sempre
igual ao código HTTP da resposta, redundante de propósito para clientes que
só leem o corpo.

## 4. Assinatura da resposta

**Algoritmo:** ed25519 (`sodium_crypto_sign_detached` no servidor,
`sodium_crypto_sign_verify_detached` no cliente). O WordPress inclui
`sodium_compat` desde a versão 5.2, então a função de verificação está
sempre disponível no plugin cliente mesmo sem a extensão nativa libsodium —
ainda assim, o cliente faz verificação defensiva de disponibilidade
(`function_exists('sodium_crypto_sign_verify_detached')`) antes de chamar,
e trata a ausência como impedimento de ambiente, não como assinatura
inválida (ver `V3R\Core\Licensing\SignatureVerifier::isAvailable()`).

### 4.1 O que é assinado

Só o objeto `payload` de cada resposta — nunca o envelope inteiro
(`{ payload, signature }`), porque a assinatura não pode assinar a si
mesma. `/deactivate` não tem `payload` assinado (ver §1).

### 4.2 Serialização canônica (obrigatória, byte a byte)

Esta é a parte onde implementações divergentes fazem a verificação falhar
em produção — servidor e cliente têm que produzir exatamente a mesma
string de bytes a partir do mesmo `payload`.

Passos, nesta ordem:

1. **Ordenar chaves recursivamente**, alfabeticamente (`SORT_STRING`), em
   todo objeto associativo, em qualquer profundidade. Arrays de lista
   (índices sequenciais 0..n-1) **mantêm a ordem original** — não são
   reordenados.
2. **Serializar em JSON compacto**, sem espaços (`json_encode` padrão, sem
   `JSON_PRETTY_PRINT`), com as flags `JSON_UNESCAPED_SLASHES |
   JSON_UNESCAPED_UNICODE`.
3. **Codificação UTF-8**, sem BOM.
4. **Assinar exatamente essa string** com `sodium_crypto_sign_detached()`, e
   codificar o resultado em **base64 variante original** (com padding —
   `SODIUM_BASE64_VARIANT_ORIGINAL` / `base64_encode()` padrão do PHP, não
   base64url).

Implementação de referência da serialização canônica:
`V3R\Core\Licensing\SignatureVerifier::canonicalize()`.

```php
// Servidor (chave privada fora do repositório, em segredo de ambiente):
$canonical = json_encode( ordenar_recursivamente( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$signature = base64_encode( sodium_crypto_sign_detached( $canonical, $private_key ) );
// resposta: { "payload": $payload, "signature": $signature }
```

```php
// Cliente:
$valid = sodium_crypto_sign_verify_detached(
    base64_decode( $signature ),
    json_encode( ordenar_recursivamente( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
    base64_decode( $public_key_embutida_no_plugin )
);
```

### 4.3 Verificação no cliente

- Assinatura ausente, malformada (base64 inválido) ou que falha a
  verificação **nunca** é tratada como "licença válida" — o cliente trata
  como falha de comunicação, entrando no mesmo caminho de erro de um
  timeout/5xx (mantém último estado conhecido, entra em grace period se
  aplicável — ver §5).
- A chave pública embutida no plugin **não é segredo** (é o par público do
  ed25519 do servidor); a chave privada nunca sai do servidor de licenças.

## 5. Política de cache no cliente

- `/validate` é chamado no máximo **1x a cada 12h** por site, via transient
  do WordPress (nunca a cada carregamento de página admin).
- Refresh forçado (ignora o cache de 12h) acontece quando: o usuário clica
  em "verificar agora" na tela de licença, imediatamente após `/activate`,
  ou imediatamente após qualquer mudança manual de chave.
- O último estado assinado e válido fica persistido localmente
  (`V3R\Core\Licensing\LicenseStorage`), para sobreviver a reinícios do
  cron e a falhas de rede.

## 6. Período de graça (grace period)

- **14 dias**, contados a partir do último contato **bem-sucedido e
  assinado corretamente** com o servidor.
- Enquanto dentro do período de graça, `V3R\Core\Updater\UpdateGate`
  mantém o último estado conhecido — se ele já era `active` e dentro da
  validade, o site continua recebendo atualização.
- No dia 15 sem contato bem-sucedido, a atualização para de ser entregue —
  mas o plugin **nunca** é desativado nem tem funcionalidade própria
  degradada por isso (ver regra de produto: só se vende atualização, nunca
  funcionalidade — pesquisa §4.7).
- Uma resposta que **confirma** `expired` ou `revoked` (não uma ausência
  de resposta) suspende a atualização imediatamente, sem grace period —
  a diferença central é "não sei se ainda está válida" (grace se aplica)
  vs. "sei que não está mais válida" (grace não se aplica).

## 7. Modos de falha e o que o cliente faz em cada um

| Falha                                          | Comportamento do cliente |
|--------------------------------------------------|---------------------------|
| Timeout de rede                                   | Mantém último estado conhecido; entra/permanece em grace period. |
| 5xx do servidor                                    | Idem timeout. |
| 4xx com corpo de erro reconhecido (§3)             | Aplica o significado do código (ex.: `license_expired` suspende update imediatamente, sem grace). |
| Resposta 200 com JSON malformado                   | Tratado como falha de comunicação (idem timeout) — nunca como "sem update". |
| **Assinatura inválida ou ausente**                 | **Nunca** tratado como "licença válida". Tratado como falha de comunicação (idem timeout) — mantém último estado conhecido e entra em grace period; nunca promove o estado da resposta suspeita. |
| `sodium_crypto_sign_verify_detached` indisponível  | Impedimento de ambiente (`\RuntimeException`), distinto de assinatura inválida — não deve ocorrer em WordPress ≥ 5.2, mas o cliente confere antes de chamar. |

## 8. Protocolo interno: tela administrativa ↔ biblioteca, dentro do mesmo WordPress

**Isto é um protocolo diferente do descrito em §§1–7.** Tudo acima é
cliente↔servidor: o site do cliente conversando pela internet com o
V3RLicense, autenticado pela própria chave de licença e assinado em
ed25519. A partir daqui é **local**: a tela administrativa de um plugin
hospedeiro (ex.: V3RLGPD) conversando com a biblioteca `v3r-core`
embutida nele, dentro do mesmo processo WordPress. Autenticação é
**nonce + capability do WordPress** — não há chave de licença no
cabeçalho e **não há assinatura ed25519 aqui**: exigi-la seria assinar
uma mensagem que nunca sai da própria máquina. Não aplique nada de §§1–4
(formato de assinatura, `SignatureVerifier`, base64 de `signature`) a
partir daqui; e não aplique a autorização por nonce/capability desta
seção aos endpoints de §§1–7 — lá o servidor de licenças continua com
`permission_callback => __return_true`, porque quem autentica é a chave.

### 8.1 Namespace, rota e por que o slug do produto está no caminho

```
/wp-json/v3r-core/v1/<product_slug>/license
/wp-json/v3r-core/v1/<product_slug>/license/activate
/wp-json/v3r-core/v1/<product_slug>/license/deactivate
/wp-json/v3r-core/v1/<product_slug>/license/refresh
```

O namespace é `v3r-core/v1` — o mesmo para qualquer plugin hospedeiro,
porque quem registra a rota é sempre a mesma biblioteca. `<product_slug>`
é o valor passado ao `Bootstrap` (ex.: `v3rlgpd`, `rit360-premiado`).

**Por que o slug entra no caminho, e não fica implícito:** dois plugins
da casa instalados no mesmo WordPress carregam, cada um, sua própria
cópia da biblioteca (via Strauss/prefixação de namespace — ver
`README.md`), e cada cópia registra suas próprias rotas REST em
`rest_api_init`. O **namespace** REST do WordPress é uma string global
por instalação: se as duas cópias registrassem `v3r-core/v1/license` sem
diferenciação, a segunda a rodar sobrescreveria o registro da primeira
(o WordPress não detecta nem impede a colisão — simplesmente uma rota
vence). Colocar o slug do produto no caminho torna cada rota única por
plugin, independentemente de quantas cópias da biblioteca coexistam no
mesmo site. **Não simplifique isso removendo o slug "porque só há um
plugin instalado hoje"** — o próximo cliente que instalar um segundo
plugin da casa reintroduz a colisão em produção, sem aviso, silenciosamente.

### 8.2 Autorização

- Cabeçalho obrigatório em toda chamada: `X-WP-Nonce`, com o valor de
  `wp_create_nonce( 'wp_rest' )` que a tela recebe via
  `wp_localize_script()`/`wp_add_inline_script()` no enqueue da própria
  SPA. Nonce ausente ou expirado responde `403 rest_cookie_invalid_nonce`
  (erro padrão do próprio WordPress, antes mesmo de chegar ao
  `permission_callback`).
- `permission_callback` de cada rota checa uma **capability
  configurável**, informada pelo plugin hospedeiro ao instanciar o
  `Bootstrap` (parâmetro novo, a acrescentar nesta fatia — hoje o
  construtor tem `productSlug`, `pluginFile`, `apiBaseUrl`), com padrão
  `manage_options` quando o plugin hospedeiro não informar nada.
  **Não fixe `manage_options` no código da biblioteca.** Cada plugin da
  casa tem sua própria capability de RBAC (`manage_v3r_licenses`,
  `manage_rit360_premiado` etc.); impor `manage_options` obrigaria quem
  já construiu papéis próprios a abrir mão deles só para usar esta tela.
- `is_admin() `**não é autorização** — é só um teste de contexto de tela
  (estamos dentro do wp-admin?), verdadeiro inclusive para qualquer
  usuário logado navegando o admin, incluindo AJAX de outros plugins. A
  checagem de permissão é sempre `current_user_can( $capability )`, nunca
  `is_admin()`.
- Requisição sem a capability responde `403` com `code: rest_forbidden`
  (o erro padrão que o WordPress já produz quando `permission_callback`
  devolve `false`) — não é um código próprio desta spec.

### 8.3 `GET /v3r-core/v1/<product_slug>/license`

Estado atual, **lido só do cache local** (`LicenseStorage`) — nunca
contata o servidor de licenças. É o que a tela chama ao abrir e sempre
que precisar redesenhar o painel; barato, sem limite de frequência.

**Entrada:** nenhuma (rota sem parâmetros).

**Saída** — `200 OK`:

```json
{
  "license_key_masked": "V3RL-XXXX-...-2D5C",
  "status": "active",
  "expires_at": "2027-08-25T00:00:00+00:00",
  "activations_used": 2,
  "activations_max": 5,
  "last_checked_at": "2026-08-25T12:00:00+00:00",
  "in_grace_period": false,
  "grace_until": null,
  "receives_updates": true,
  "status_message": "Licença ativa. Você recebe atualizações normalmente."
}
```

| Campo                | Tipo         | Descrição |
|----------------------|--------------|-----------|
| `license_key_masked` | string       | Sempre mascarada (§8.5) — `""` quando `status = inactive`. Formato: `V3R\Core\Support\LicenseKeyMasker::mask()`. |
| `status`              | string       | Um de: `active`, `expired`, `revoked`, `invalid`, `inactive` (`V3R\Core\Licensing\LicenseStatus`). `inactive` = nenhuma ativação feita neste site ainda. |
| `expires_at`          | string\|null | ISO 8601, ou `null` (licença sem expiração, ou `inactive`). |
| `activations_used`    | int          | `LicenseState::getActivationsUsed()`. |
| `activations_max`     | int\|null    | `LicenseState::getActivationsMax()` — `null` = ilimitado. |
| `last_checked_at`     | string\|null | `LicenseState::getLastCheckedAt()` — instante do último contato bem-sucedido com o servidor. `null` se nunca contatou. |
| `in_grace_period`     | bool         | `LicenseState::isInGracePeriod()` — dentro do período de tolerância de rede (§6 do protocolo externo). |
| `grace_until`         | string\|null | `LicenseState::getGraceUntil()` — até quando o grace period vale, se houver um em curso. |
| `receives_updates`    | bool         | Resposta de `V3R\Core\Updater\UpdateGate::canUpdate()` para o estado atual — a pergunta que mais interessa à tela: "este site recebe atualização agora?". |
| `status_message`      | string       | Texto pronto para exibição — ver §8.4. **A tela nunca deriva esta frase a partir de `status` por transformação de string**; ela exibe o que veio aqui. |

### 8.4 `status_message` — texto pronto, para os plugins da casa não improvisarem cada um o seu

A biblioteca gera este texto centralizadamente (mesmo componente que
calcula `receives_updates`), a partir da combinação `status` +
`receives_updates` + `in_grace_period`. Mapeamento de referência —
qualquer plugin hospedeiro que renderize texto próprio em vez deste
campo está reintroduzindo a mesma inconsistência que este campo existe
para evitar:

| Situação                                              | `status_message` sugerido |
|--------------------------------------------------------|----------------------------|
| `active`, dentro da validade, sem grace em curso         | "Licença ativa. Você recebe atualizações normalmente." |
| `active`, dentro da validade, em grace period            | "Não conseguimos confirmar sua licença nos últimos dias, mas você continua recebendo atualizações durante o período de tolerância." |
| `active`, grace period estourado (`receives_updates = false`) | "Não conseguimos confirmar sua licença há mais de 14 dias. As atualizações foram pausadas até a próxima verificação bem-sucedida." |
| `expired`                                                | "Sua licença expirou. O plugin continua funcionando normalmente, mas você não recebe mais atualizações. Renove para voltar a recebê-las." |
| `revoked`                                                | "Esta licença foi revogada. O plugin continua funcionando normalmente, mas não recebe atualizações." |
| `invalid`                                                | "Não foi possível validar esta licença. Verifique a chave informada." |
| `inactive`                                               | "Nenhuma licença ativada neste site." |

**Nenhuma dessas frases usa a palavra "bloqueado", "desativado" ou
"suspenso" referindo-se ao plugin** — ver §8.7.

### 8.5 A chave de licença nunca volta inteira

Toda resposta desta seção que inclua a chave devolve **mascarada**
(`V3R\Core\Support\LicenseKeyMasker::mask()`, já existente — mesmo
formato usado em log/debug). A chave em texto pleno entra **só** pelo
corpo de `POST .../license/activate` e nunca mais sai — nem para a tela
do próprio administrador que acabou de digitá-la, nem em nenhum dos
outros três endpoints.

**Por quê:** reduz a superfície de vazamento em log de navegador
(devtools, extensões), captura de tela (print de suporte, gravação de
tela para abrir chamado) e cola acidental em canal errado. Quem tem a
chave em mãos para reativar em outro lugar é o administrador que a
digitou da primeira vez — a tela não precisa devolvê-la para isso.

### 8.6 `POST /v3r-core/v1/<product_slug>/license/activate`

Ativa a licença para este site: chama o `/activate` de §2.1 do
protocolo externo e persiste o resultado localmente.

**Entrada** (JSON body):

| Campo          | Tipo   | Obrigatório | Descrição |
|----------------|--------|:---:|-----------|
| `license_key`  | string | sim | Chave completa, em texto pleno — único ponto desta seção em que ela trafega assim. |

`product_slug` e `site_url` **não são enviados pela tela** — a
biblioteca já conhece o primeiro (é o segmento da própria rota, o mesmo
passado ao `Bootstrap`) e deriva o segundo de `site_url()` do próprio
WordPress no momento da chamada, nunca de um valor que a tela poderia
(mesmo sem intenção) enviar divergente do site real.

**Saída de sucesso** — `200 OK`: mesmo schema de §8.3 (o estado
resultante da ativação).

**Erros possíveis:** ver tabela de §8.8. Em especial: `missing_license_key`
(400, campo vazio ou ausente), e qualquer erro do protocolo externo
repassado (§8.8) quando o servidor de licenças recusa a chave.

### 8.7 `POST /v3r-core/v1/<product_slug>/license/deactivate`

Libera a cota deste domínio no servidor (chama `/deactivate` de §2.2 do
protocolo externo) e limpa o estado local — depois desta chamada,
`GET .../license` volta a responder o schema de `inactive`.

**Entrada:** nenhuma (`product_slug` e `site_url` resolvidos do mesmo
jeito que em §8.6).

**Saída de sucesso** — `200 OK`:

```json
{ "deactivated": true }
```

**Erros possíveis:** ver §8.8. Em especial: `domain_not_activated`
(nada para desativar), e os erros de comunicação (`server_unreachable`).

### 8.8 `POST /v3r-core/v1/<product_slug>/license/refresh`

Força verificação contra o servidor de licenças **ignorando** o cache
de 12h descrito em §5 do protocolo externo (chama `/validate` de §2.3).
É o botão "verificar agora" da tela, e também o gatilho automático
imediatamente após `activate` e após qualquer troca manual de chave —
mesma regra de §5.

**Entrada:** nenhuma.

**Saída de sucesso** — `200 OK`: mesmo schema de §8.3, já refletindo o
resultado da verificação forçada (inclusive `last_checked_at`
atualizado, e `status` podendo virar `expired`/`revoked` se foi isso que
o servidor confirmou).

**A distinção `GET .../license` (barato, do cache) × `refresh` (bate no
servidor) é deliberada:** abrir a tela não pode disparar uma chamada
externa a cada carregamento — isso multiplicaria requisições ao
V3RLicense por cada admin que navega até a aba, sem necessidade, e
tornaria o tempo de resposta da tela dependente da rede do servidor de
licenças a cada visita.

**Erros possíveis:** os mesmos de `activate`, exceto os de ativação
propriamente dita (`activation_limit_reached` não se aplica aqui — este
endpoint não ativa nada novo).

#### 8.8.1 Throttle local — 1 `refresh` por minuto por produto

`refresh` é o único destes quatro endpoints que força uma chamada
externa ao servidor de licenças por definição — é exatamente o que o
diferencia do `GET`. O servidor de licenças aplica rate limiting de
**20 requisições por minuto por chave de licença** (§3, `rate_limited`).
Um administrador clicando "verificar agora" repetidamente — reação
natural quando algo parece travado — consome essa cota em segundos.

**O modo de falha é perverso: o site do cliente se auto-bloqueia.**
Passa a receber `429 rate_limited` do próprio servidor, a tela mostra
falha, e a reação do usuário é clicar mais — de fora, isso é
indistinguível de um problema de licença ou de servidor fora do ar,
quando na verdade foi o próprio cliente que esgotou sua cota.

Por isso a biblioteca aplica, **antes de a chamada externa sair**, um
throttle local independente do rate limit do servidor:

- **No máximo um `refresh` por minuto, por produto, por instalação.**
  Uma chamada dentro da janela de 1 minuto desde o último `refresh`
  (bem-sucedido ou não) **não** contata o servidor de licenças.
- Nesse caso a resposta **não é erro**: é `200 OK`, mesmo schema de
  §8.3 (o estado corrente do cache, exatamente como um `GET`), acrescido
  de dois campos:

  | Campo          | Tipo | Descrição |
  |----------------|------|-----------|
  | `throttled`    | bool | `true` quando esta chamada foi adiada pelo throttle local (não chegou a contatar o servidor). Ausente ou `false` nas demais respostas de `refresh`. |
  | `retry_after`  | int  | Segundos até o throttle liberar um novo `refresh` de verdade. Só presente quando `throttled = true`. |

  ```json
  {
    "license_key_masked": "V3RL-XXXX-...-2D5C",
    "status": "active",
    "expires_at": "2027-08-25T00:00:00+00:00",
    "activations_used": 2,
    "activations_max": 5,
    "last_checked_at": "2026-08-25T12:00:00+00:00",
    "in_grace_period": false,
    "grace_until": null,
    "receives_updates": true,
    "status_message": "Licença ativa. Você recebe atualizações normalmente.",
    "throttled": true,
    "retry_after": 43
  }
  ```

  A tela usa `throttled`/`retry_after` para desabilitar o botão "verificar
  agora" e mostrar algo como "verificado há instantes" — nunca uma
  mensagem de falha.

- **Este throttle é cortesia com o servidor e proteção do próprio
  cliente, não controle de acesso.** Ele existe para o site do cliente
  não gastar a própria cota do rate limit externo com cliques repetidos
  na mesma tela — não é uma trava de segurança nem de autorização, e não
  produz nenhum dos códigos de erro de §8.9.
- **`activate` não entra neste throttle.** É ação deliberada e rara (o
  administrador digitando ou corrigindo uma chave); throttlá-la
  impediria alguém de corrigir na hora uma chave digitada errada.
- **Interação com o cache de 12h (§5 do protocolo externo):** são duas
  coisas diferentes, e `refresh` se relaciona com as duas ao mesmo
  tempo — ele **ignora** o cache de 12h (é o que permite forçar a
  verificação fora da janela rotineira), mas **respeita** este throttle
  de 1 minuto (é o que evita a rajada de cliques). O cache de 12h evita
  chamada rotineira desnecessária a cada carregamento de tela; o
  throttle de 1 minuto evita rajada quando alguém insiste no botão.

### 8.9 Erros deste protocolo

Mesmo formato de erro da REST API do WordPress usado em §1/§3
(`code`, `message`, `data.status`). Os erros abaixo são **específicos
deste protocolo interno** — não confundir com a tabela de §3, que é do
protocolo externo (embora vários sejam o mesmo código repassado, quando
a causa vem do servidor de licenças).

| Código                     | HTTP | Origem | Significado |
|----------------------------|:---:|--------|-------------|
| `rest_cookie_invalid_nonce`| 403 | WordPress | Nonce ausente, expirado ou inválido — antes do `permission_callback`. |
| `rest_forbidden`           | 403 | WordPress | Nonce válido, mas o usuário não tem a capability configurada. |
| `missing_license_key`      | 400 | Biblioteca | `activate` chamado sem `license_key` (ou vazio). |
| `invalid_key`              | 404 | Repassado do servidor | Chave não existe ou está malformada (ver §3). |
| `product_mismatch`         | 400 | Repassado do servidor | A chave é válida, mas para outro produto. |
| `activation_limit_reached` | 409 | Repassado do servidor | Cota esgotada para um domínio novo (§2.1). |
| `domain_not_activated`     | 404 | Repassado do servidor | `deactivate`/`refresh` para um domínio sem ativação registrada. |
| `license_expired`          | 403 | Repassado do servidor | Servidor confirmou `status = expired`. |
| `license_revoked`          | 403 | Repassado do servidor | Servidor confirmou `status = revoked`. |
| `rate_limited`             | 429 | Repassado do servidor | Excesso de tentativas — mesmo limite do protocolo externo, refletido aqui. |
| `server_unreachable`       | 503 | Biblioteca | Timeout, 5xx, ou qualquer falha de rede ao contatar o V3RLicense (§7 do protocolo externo — "modos de falha"). |
| `signature_invalid`        | 502 | Biblioteca | Ver §8.10 — parágrafo próprio. |

Todos seguem `{ code, message, data: { status } }`, com `data.status`
igual ao HTTP da resposta.

### 8.10 Assinatura inválida — nunca "licença ok" para a tela

Quando a biblioteca contata o servidor de licenças (`activate`,
`refresh`, e o `deactivate` cai fora por não ter payload assinado — ver
§1) e a assinatura ed25519 da resposta falha a verificação, isso
**nunca** é repassado à tela como estado válido. A biblioteca já trata
isso, no protocolo externo, como falha de comunicação (§4.3/§7): mantém
o último estado local conhecido e entra em grace period se aplicável.

Para este protocolo interno, a chamada que originou a checagem
(`activate` ou `refresh`) responde com o erro `signature_invalid` (502)
em vez de devolver um estado — a tela mostra "não foi possível
confirmar sua licença agora" (mesma família visual de `server_unreachable`),
**nunca** "licença ativa". Um `GET .../license` **subsequente** continua
funcionando normalmente e devolve o último estado local válido (que já
reflete o grace period, se for o caso) — a falha de assinatura não
"contamina" o cache, só impede que a resposta suspeita seja promovida a
estado corrente.

### 8.11 O que a tela precisa saber para não mentir ao usuário

**Licença expirada, revogada ou inválida não desativa o plugin, nem
degrada nenhuma funcionalidade dele.** A única coisa que se perde é a
atualização automática (`V3R\Core\Updater\UpdateGate`, regra de produto
já documentada em §6 do protocolo externo). A tela precisa comunicar
**"você deixou de receber atualizações"**, e nunca **"o plugin foi
bloqueado"**, "desativado" ou "suspenso" — mesmo quando `status` for
`expired` ou `revoked`.

Use o texto pronto de `status_message` (§8.4) em vez de escrever este
texto de novo em cada plugin hospedeiro — é exatamente o vocabulário que
esta regra de produto exige, e escrevê-lo uma vez só evita que um
plugin da casa diga "bloqueado" enquanto outro diz "sem atualização" para
o mesmo estado.
