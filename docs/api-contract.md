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
| `checked_at`         | string       | Instante em que o servidor gerou esta resposta. |

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

| Campo             | Tipo   | Obrigatório |
|-------------------|--------|:---:|
| `product_slug`    | string | sim |
| `license_key`     | string | sim |
| `site_url`        | string | sim |
| `plugin_version`  | string | sim |

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
`/download` (ver §2.5), autenticada por licença, não um link público
permanente.

**Erros possíveis:** `invalid_key`, `product_mismatch`, `license_expired`,
`license_revoked`, `rate_limited`. **Importante:** licença expirada/revogada
resulta em erro aqui (não em `update_available: false`) — o cliente precisa
distinguir "sem novidade" de "sem direito a atualizar" para decidir o que
mostrar na UI, mas em ambos os casos o `V3R\Core\Updater\UpdateGate` decide
"não recebe update" e o plugin continua funcionando normalmente.

### 2.5 `GET /download`

Entrega o zip da versão, ou nega com motivo.

**Entrada** (query string):

| Campo          | Tipo   | Obrigatório |
|----------------|--------|:---:|
| `product_slug` | string | sim |
| `license_key`  | string | sim |
| `site_url`     | string | sim |

**Saída de sucesso:** `200 OK`, `Content-Type: application/zip`, stream do
arquivo. Não é JSON.

**Saída de negação:** `403 Forbidden`, corpo no formato de erro padrão
(§1), com `code` explicando o motivo: `license_expired`, `license_revoked`,
`domain_not_activated`, `invalid_key`, `product_mismatch`, `rate_limited`.

## 3. Códigos de erro

| Código                     | HTTP | Significado |
|----------------------------|:---:|-------------|
| `invalid_key`              | 404 | Chave não existe ou está malformada. |
| `activation_limit_reached` | 409 | `activations_max` já atingido para esta licença. |
| `domain_not_activated`     | 404 | O domínio informado não tem ativação registrada para esta licença. |
| `product_mismatch`         | 400 | A chave é válida, mas não é para o `product_slug` informado. |
| `license_expired`          | 403 | Licença encontrada, mas `status = expired`. |
| `license_revoked`          | 403 | Licença encontrada, mas `status = revoked` (reembolso/cancelamento). |
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
