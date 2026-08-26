# Histórico de Desenvolvimento — V3RCore

## 2026-08-26 — Fatias 2a e 2b, v0.2.0 e v0.3.0: o ciclo de auto-atualização fecha

### Contexto
Sequência de três entregas no mesmo dia: a fatia 2 completa (issue #3,
comunicação com o servidor + integração WordPress), a correção da
auto-prefixação que o piloto V3RLGPD expôs, e o fechamento de uma falha de
autorização nos endpoints REST internos.

### Implementado
- **Fatia 2a** — `HttpApiClient` (transporte injetável), `LicenseStorage`
  (`wp_options`/transient), `LicenseManager` com cache de 12h e grace
  period de 14 dias. Assinatura ausente/malformada/inválida nunca vira
  licença válida.
- **Fatia 2b** — `Updater\UpdateChecker`/`PucBridge` ligando ao mecanismo
  de atualização do WordPress; quatro rotas REST internas
  (`Rest\LicenseController`/`LicenseRestRouter`); `AdminPage` padrão
  opcional. `ApiException::SIGNATURE_INVALID` para o protocolo interno
  distinguir `signature_invalid` (502) de `server_unreachable` (503).
- **v0.2.0** — remoção da auto-prefixação interna do
  `plugin-update-checker`; toda a prefixação passa a ser responsabilidade
  de uma única passada do Strauss no plugin hospedeiro (#achado do piloto
  V3RLGPD). `docs/integracao-em-plugin.md` — receita testada ponta a
  ponta.
- **v0.3.0 (#9)** — capability por operação: leitura (`GET`/`refresh`) e
  gestão (`activate`/`deactivate`) separadas, sintéticas, ponte para RBAC
  próprio do hospedeiro.

### Decisões
Ver `docs/ARCHITECTURE.md` — ADR-007 (prefixação é do hospedeiro, a lib
não se protege sozinha), ADR-008 (capability por operação), ADR-009
(§7.1 do contrato: só payload assinado suspende, erro de rede nunca).

### Validado num WordPress real, ciclo completo
Publicar release → WordPress enxergar a atualização → download por token
→ instalar → licença revogada corta a atualização **sem** derrubar o
plugin. 128 testes/242 assertions (fatia 2), depois 133 (v0.3.0), lint e
PHPStan nível 8 limpos durante toda a validação.

### Dois defeitos que a suíte verde não pegou
1. **Auto-prefixação interna não chegava a quem instalava** — a pasta era
   `gitignored` e nunca viajava na tag; o hospedeiro recebia código
   apontando para namespace inexistente, fatal error na ativação.
2. **`checkForUpdate()` enviava a versão instalada como pedido de
   rollback** — o servidor respondia, de forma legítima e assinada, que
   não havia atualização. 126 testes verdes porque o transporte falso
   devolvia o programado sem interpretar o que foi enviado.

Mesmo padrão do V3RLicense nesta semana (ver `docs/DEV-HISTORY.md` de
lá): teste que só reconhece o sucesso — ou só verifica uma ponta — não
distingue "certo" de "errado que também parece certo".

### Armadilhas de ambiente registradas (não são defeito de código)
Servidor e cliente no mesmo WordPress entram em modo de manutenção ao
atualizar e respondem 503 ao próprio download; porta publicada do
container (3080) não existe de dentro dele, a interna é 80;
`wp_http_validate_url()` só aceita as portas 80, 443 e 8080.

### Pendente para o próximo ciclo
- Metadados `requires`/`tested` chegam incompletos ao transiente do
  WordPress (#8).
- CI não roda em branch de feature (#7).
- Prefixar dependências compartilhadas dos plugins da casa (#6).
- Padronizar versão mínima de PHP entre os plugins (#5).
- Rollout nos 6 plugins (#4).

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
