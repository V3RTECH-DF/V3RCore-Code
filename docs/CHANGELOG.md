# Changelog — V3RCore

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/); versionamento [SemVer](https://semver.org/lang/pt-BR/).

## [0.2.0]

### Corrigido
- **Auto-prefixação interna do `plugin-update-checker` removida.** Quebrava
  quando o plugin hospedeiro prefixava v3r-core e o `plugin-update-checker`
  juntos numa mesma passada do Strauss (o arranjo real de distribuição):
  produzia um namespace aninhado (`Host\Vendor\V3R\Core\Vendor\YahnisElsts\...`)
  que não batia com o das classes reais do pacote transitivo processado pelo
  hospedeiro. `PucBridge.php`/`UpdateChecker.php` agora referenciam o
  namespace original do `plugin-update-checker`; toda a prefixação passa a
  ser responsabilidade de uma única passada do Strauss no hospedeiro. Ver
  `docs/integracao-em-plugin.md` §6.

### Removido
- Auto-prefixação via `post-install-cmd`/`post-update-cmd`,
  `tools/strauss.php`, `extra.strauss` do `composer.json`,
  `brianhenryie/strauss` de `require-dev`, `classmap: vendor-prefixed/` do
  autoload (nunca resolvia nada em produção — a pasta nunca viajava no
  pacote Composer).

### Adicionado
- `docs/integracao-em-plugin.md` — receita testada de ponta a ponta de como
  um plugin hospedeiro embute v3r-core via Strauss.

## [0.1.0]

Primeira tag consumível — fatias 1, 2a e 2b concluídas (ver seções
"Adicionado" abaixo). Continha o defeito de auto-prefixação descrito em
`[0.2.0]`; não usar para integração nova.

## [Não lançado]

### Adicionado
- **Fatia 1 — esqueleto da biblioteca (#1)** — estrutura PSR-4 `V3R\Core\`,
  Composer + Strauss para prefixar a lib e suas dependências transitivas em
  cada plugin hospedeiro, CI em matriz PHP 7.4–8.0–8.1–8.2–8.3–8.4.
- `Support\SiteIdentity` — normalização de domínio e detecção de ambiente de
  teste/desenvolvimento (não consome cota de ativação).
- `Support\LicenseKeyMasker` e `Support\Logger`.
- `Licensing\LicenseState`, `LicenseStatus`, `SignatureVerifier` (verificação
  ed25519 da resposta do servidor de licenças).
- `Licensing\ApiClientInterface` / `ApiException` — contrato de rede para a
  fatia 2.
- `Updater\UpdateGate` — decide liberação de atualização a partir do
  `LicenseState`.
- `Bootstrap` — ponto de entrada para o plugin hospedeiro.
- `docs/api-contract.md` — contrato completo da API `v3r-license/v1` entre
  esta lib e o servidor V3RLicense, para as duas pontas implementarem
  independentemente.

### Corrigido
- Autoloader carregava o `plugin-update-checker` original em vez do
  prefixado pelo Strauss (#2).

### Decisões de arquitetura
- Ver `docs/ARCHITECTURE.md` (ADR-001 a ADR-006): updater e licenciamento na
  mesma lib, ed25519 em vez de HMAC, ativações com padrão no produto e valor
  efetivo na licença, licença expirada nunca derruba o plugin, a lib entrega
  capacidade e não tela imposta, bootstrap tolerante à ausência de Composer
  em runtime.

### Pendente (fatia 2, issue #3)
- `HttpApiClient`, `LicenseManager`, `LicenseStorage`, `AdminPage`,
  `UpdateChecker` — hoje contrato (`TODO(fatia-2)`), sem lógica de rede nem
  persistência real.
