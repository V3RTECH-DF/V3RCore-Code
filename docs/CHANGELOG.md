# Changelog — V3RCore

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/); versionamento [SemVer](https://semver.org/lang/pt-BR/).

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
