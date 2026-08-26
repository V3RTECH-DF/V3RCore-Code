# Arquitetura — V3RCore

> Biblioteca Composer (não é plugin), namespace `V3R\Core\`, PHP 7.4+, embutida em
> cada plugin da família via [Strauss](https://github.com/BrianHenryIE/strauss)
> para prefixar código e dependências transitivas e evitar colisão entre plugins
> com versões diferentes da lib no mesmo WordPress. Encapsula o
> [`plugin-update-checker`](https://github.com/YahnisElsts/plugin-update-checker).
> Cliente do servidor de licenças **V3RLicense** — contrato completo em
> `docs/api-contract.md`. Backlog em GitHub Issues (`V3RTECH-DF/V3RCore-Code`).

---

## 1. Contexto

Os plugins WordPress da casa (V3REvent, V3RHelp, V3RLGPD, GE Associados,
RIT360 Solidário, RIT360 Premiado) vão passar a ser distribuídos para
organizações externas à RIT. Isso cria duas necessidades que nenhum deles
resolve hoje:

1. **Licenciamento** — gratuito para organizações filiadas à RIT, pago para
   empresas.
2. **Auto-atualização** — o `deploy.sh` por SSH atual só alcança servidores
   sob nosso controle e não escala para terceiros.

O V3RCore é a peça compartilhada que resolve as duas, para não reimplementar
em cada plugin. Ver issue #4 (rollout nos 6 plugins) para o plano de adoção.

---

## 2. Decisões (ADRs)

### ADR-001 — Updater e licenciamento moram na mesma biblioteca

Quem decide entregar (ou não) a atualização é quem sabe o estado da licença.
Separar as duas peças em bibliotecas distintas criaria uma superfície de
sincronização entre elas sem necessidade — o `UpdateGate` consulta o
`LicenseState` diretamente, sem camada intermediária.

### ADR-002 — Assinatura ed25519, não HMAC

HMAC exigiria um segredo simétrico embutido no plugin distribuído — código
que sai da nossa mão, logo não é segredo. Com ed25519, a chave privada nunca
sai do servidor V3RLicense; a chave pública embutida no plugin cliente só
verifica, nunca assina. WordPress inclui `sodium_compat` desde a 5.2, então
`sodium_crypto_sign_verify_detached` está sempre disponível — a verificação
de disponibilidade em `SignatureVerifier` é defensiva mesmo assim.

### ADR-003 — Ativações: o produto define o padrão, a licença carrega o valor efetivo

Decisão espelhada no modelo de dados do V3RLicense (ver ADR lá). Do lado do
cliente, `LicenseState` sempre reflete o valor efetivo que o servidor mandou
— nunca recalcula nem assume um padrão localmente.

### ADR-004 — Licença expirada nunca derruba o plugin

Consequência da GPL, não só do modelo comercial: o código PHP que roda no
WordPress é derivado de GPL, então bloquear funcionalidade local seria
contratual e tecnicamente frágil. **O que se vende é atualização e correção,
nunca funcionalidade.** `UpdateGate` só nega a atualização; nunca desativa
nem degrada o plugin hospedeiro. Regras de graça (14 dias sem contato com o
servidor mantém o último estado conhecido; graça estourada para o update mas
nunca desativa) ficam registradas na issue #3 e valem como contrato para a
fatia 2.

### ADR-005 — A biblioteca entrega capacidade, não tela imposta

Levantamento do piloto V3RLGPD (25/08/2026, comentário da issue #3) mostrou
que plugins com SPA React própria (V3RLGPD, V3REvent, e os demais que vierem
a ter) desenhariam duas UIs distintas e divergentes se a lib impusesse sua
própria tela de licença. Decisão:

1. `LicenseManager` expõe a API de ativar/desativar/validar/consultar estado
   — o que todo hospedeiro consome, seja qual for a UI.
2. `AdminPage` continua existindo como tela **padrão em PHP**, para plugin
   sem SPA (ex.: V3RProp) — mas é **opcional**: o hospedeiro decide se a
   registra.
3. Plugin com SPA desenha a própria aba de licença, consumindo a API da lib
   por **endpoints REST próprios no site do cliente** (não só métodos PHP) —
   escopo que entrou na fatia 2 por conta deste achado.

### ADR-006 — Bootstrap sob `class_exists()`, sem depender de Composer em runtime

Achado do piloto V3RLGPD: nem todo plugin hospedeiro carrega
`vendor/autoload.php` incondicionalmente (alguns cobrem também o layout
achatado do zip de distribuição, com autoload próprio). Todo o bootstrap da
lib precisa tolerar ausência do autoload de dev sem quebrar a ativação do
plugin.

---

## 3. Estrutura entregue (fatia 1 — esqueleto)

> Estado em 25/08/2026. TODO(fatia-2) marca o que ainda é contrato, não
> implementação — ver issue #3 para o escopo completo do que falta.

| Classe | Papel | Estado |
|---|---|---|
| `Support\SiteIdentity` | Normaliza domínio, identifica ambiente de teste/dev (não consome cota) | completo |
| `Support\LicenseKeyMasker` | Nunca deixar chave de licença ir para log | completo |
| `Support\Logger` | Logging do lado da lib | completo |
| `Licensing\LicenseState` | Estado da licença no cliente (imutável, refletindo o servidor) | completo |
| `Licensing\LicenseStatus` | Enum de status | completo |
| `Licensing\SignatureVerifier` | Verificação ed25519 da resposta do servidor | completo |
| `Licensing\ApiClientInterface` / `ApiException` | Contrato de rede | completo |
| `Licensing\HttpApiClient` | Chamadas reais via `wp_remote_post` | TODO(fatia-2) |
| `Licensing\LicenseManager` | Ativação/desativação/validação/refresh | TODO(fatia-2) |
| `Licensing\LicenseStorage` | Persistência em `wp_options`/transients | TODO(fatia-2) |
| `Licensing\AdminPage` | Tela padrão em PHP, opcional (ADR-005) | TODO(fatia-2) |
| `Updater\UpdateGate` | Decide se libera update dado o `LicenseState` | completo |
| `Updater\UpdateChecker` | `PucFactory` + hooks do WP | TODO(fatia-2) |
| `Bootstrap` | Ponto de entrada do plugin hospedeiro | completo (esqueleto) |

CI: `.github/workflows/ci.yml`, matriz PHP 7.4–8.0–8.1–8.2–8.3–8.4, com
`sodium` habilitada (obrigatória para `SignatureVerifier`).

## 4. Fora de escopo desta lib

- **Texto jurídico e base legal** de licenciamento — não são gerados aqui.
- **Tela de licença imposta** a plugin com SPA própria (ADR-005).
- **`deploy.sh` por SSH** continua existindo à parte, para servidores sob
  nosso controle — canal separado do update via V3RLicense (ver issue #4).
