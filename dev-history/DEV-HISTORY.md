# Histórico de Desenvolvimento — V3RCore

## 2026-08-25 — Criação do projeto e fatia 1 (esqueleto)

### Contexto
Projeto criado do zero para resolver licenciamento (gratuito para filiadas
à RIT, pago para empresas) e auto-atualização (o `deploy.sh` por SSH atual
só funciona em servidores sob controle nosso) dos plugins WordPress da casa.
V3RCore é a biblioteca cliente, embutida em cada plugin; V3RLicense é o
servidor (repositório e histórico próprios).

### Implementado
- Esqueleto PSR-4 `V3R\Core\`, Composer + Strauss, CI em matriz PHP
  7.4–8.4 (#1).
- `SiteIdentity`, `LicenseState`, `UpdateGate`, `SignatureVerifier`
  completos; `HttpApiClient`, `LicenseManager`, `LicenseStorage`,
  `AdminPage`, `UpdateChecker` como contrato (`TODO(fatia-2)`), aguardando a
  issue #3.
- `docs/api-contract.md` — contrato completo do protocolo `v3r-license/v1`,
  escrito para que cliente (esta lib) e servidor (V3RLicense) sejam
  implementados de forma independente.
- Fix do autoload carregando o PUC não-prefixado (#2).

### Decisões
- Ver `docs/ARCHITECTURE.md` — ADR-001 a ADR-006. Destaque: **ADR-005**
  (biblioteca entrega capacidade, não tela imposta) nasceu do levantamento
  do piloto V3RLGPD feito nesta mesma sessão, e mudou o escopo da fatia 2
  (precisa expor endpoints REST no site do cliente, não só métodos PHP).

### Levantamento do piloto (V3RLGPD)
Registrado no comentário da issue #3: V3RLGPD já é SPA React própria, sem
dependência de runtime do Composer, autoload próprio cobrindo dev e zip
achatado, versão do plugin declarada em 4 lugares sincronizados à mão (achado
que alimenta a issue #5 — padronizar versão mínima de PHP entre os plugins —
e a #4 — rollout).

### Pendente para o próximo ciclo
- Fatia 2 completa (issue #3): rede real, persistência, tela PHP opcional,
  endpoints REST no cliente.
- Rollout nos 6 plugins (issue #4), piloto V3RLGPD.
- Prefixar dependências compartilhadas dos plugins da casa (issue #6).
- Padronizar versão mínima de PHP entre os plugins (issue #5).
