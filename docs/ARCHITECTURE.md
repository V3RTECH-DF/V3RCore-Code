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

### ADR-007 — Toda a prefixação acontece no hospedeiro; a biblioteca não se protege sozinha

Até v0.1.0, a lib rodava sua própria auto-prefixação (`post-install-cmd`/
`post-update-cmd`, `tools/strauss.php`) do `plugin-update-checker`
embutido. Quebrava exatamente no arranjo real de distribuição: quando o
plugin hospedeiro prefixava v3r-core e o PUC juntos numa única passada do
Strauss, produzia um namespace aninhado
(`Host\Vendor\V3R\Core\Vendor\YahnisElsts\...`) que não batia com o das
classes reais do pacote transitivo processado pelo hospedeiro — fatal
error na ativação. A pasta de auto-prefixação também era `gitignored` e
nunca viajava na tag, então quem instalava a lib recebia código apontando
para um namespace que não existia.

**Decisão (v0.2.0):** removida a auto-prefixação interna;
`PucBridge.php`/`UpdateChecker.php` referenciam só o namespace original
do `plugin-update-checker`. Toda a prefixação passa a ser responsabilidade
de **uma única passada do Strauss no hospedeiro** — receita testada em
`docs/integracao-em-plugin.md`.

**Consequência que não pode ser esquecida:** a biblioteca **não se
protege sozinha**. O isolamento entre plugins com versões diferentes da
lib no mesmo WordPress depende de cada plugin hospedeiro configurar o
Strauss corretamente — ver issue #6 (prefixar dependências
compartilhadas) e o guard de duas pontas descrito no commit
`e70eb27` (verifica prefixo **e** ordem em relação ao `dump-autoload` do
pacote, porque checar só uma ponta aprova o plugin que tem as duas
metades divergentes).

### ADR-008 — Capability por operação, não uma só para as quatro rotas

Até v0.2.0, as quatro rotas REST internas (`GET .../license`,
`POST .../license/{activate,deactivate,refresh}`) usavam o mesmo
`permission_callback`: quem podia consultar a licença também podia
desativá-la, inclusive liberar a cota do domínio no servidor via chamada
direta ao endpoint — mesmo com a tela escondendo o botão.

**Decisão (v0.3.0, #9):** `Bootstrap` aceita `$readCapability` (leitura:
consultar e `refresh`) e `$manageCapability` (gestão: `activate` e
`deactivate`), sétimo argumento opcional — sem ele, gestão cai para
leitura, então quem já integra com uma capability só continua se
comportando exatamente como antes. As duas podem ser sintéticas, ponte
para RBAC próprio do hospedeiro; `manage_options` é erro nos dois
sentidos (larga demais, ou estreita demais e exclui o encarregado). A
tela nunca substitui a autorização do endpoint. Contrato completo:
`docs/api-contract.md` §8.2.

### ADR-009 — Erro de rede não assinado nunca suspende a licença

Emenda ao §7 do contrato, feita durante a fatia 2a: a versão original
mandava aplicar o significado de qualquer erro 4xx, com `license_expired`
suspendendo imediatamente. **Corrigido no §7.1:** respostas de erro não
são assinadas, então aceitar um `403` como prova permitiria a quem
controla a rede cortar a atualização de um cliente legítimo — sem tocar
no servidor de verdade. Só payload assinado (confirmação de
`expired`/`revoked`/`invalid`) suspende sem grace; erro de comunicação,
qualquer que seja o código HTTP, apenas consome a graça de 14 dias e
converge para o estado seguro pelo decurso do prazo, nunca por resposta
não autenticada.

### ADR-010 — Um único par de constantes de produção, embutido no build; `wp-config.php` só sobrescreve os dois juntos

Decisão de rollout para os sete plugins clientes (V3REvent, V3RHelp, V3RLGPD,
V3RProp, GE Associados, RIT360 Solidário, RIT360 Premiado).

**Decisão:**

1. Um único par de constantes, com nomes genéricos compartilhados por todos
   os plugins — `V3R_LICENSE_API_URL` e `V3R_LICENSE_PUBLIC_KEY` — em vez de
   um par por plugin com prefixo próprio. Um mesmo site cliente pode ter
   vários plugins da casa instalados, e todos falam com o mesmo servidor e
   conferem a mesma chave; sete pares seriam sete cópias do mesmo valor a
   manter em sincronia, e a primeira que divergisse falharia só na
   verificação de assinatura.
2. A chave pública **não é segredo** e não vai por variável de ambiente —
   é constante mesmo, embutida no código. Contraste que não deve ser
   confundido: a chave **privada**, do lado do servidor V3RLicense, vai por
   variável de ambiente (issue V3RLicense-Code#11). As duas chaves têm
   exigências opostas (uma nunca pode vazar, a outra existe para ser
   pública) e não devem seguir a mesma regra por simetria de nome.
3. O par de **produção** é o default embutido no build do plugin. O cliente
   comum não edita `wp-config.php`; se o default fosse o par de
   desenvolvimento, todo cliente precisaria configurar algo só para o
   plugin funcionar.
4. **Regra do par:** URL e chave vêm sempre da mesma fonte, e mudam juntas.
   Se uma das duas constantes estiver definida em `wp-config.php` e a outra
   não, o plugin recusa e avisa — nunca combina a constante de uma com o
   default da outra. URL de produção com chave de desenvolvimento (ou
   vice-versa) é um par incoerente que passa por qualquer guard de
   ambiente e só falha depois, na verificação de assinatura — sintoma bem
   mais difícil de diagnosticar do que "não iniciou".

**Consequência:** todo plugin hospedeiro resolve o par de configuração antes
de instanciar `Bootstrap` — nunca lendo `V3R_LICENSE_API_URL` e
`V3R_LICENSE_PUBLIC_KEY` em pontos diferentes do código. Duas restrições
fazem parte da decisão, e sem elas o guard do ponto 4 não guarda nada:

- **O plugin nunca define as duas constantes compartilhadas.** Elas
  pertencem ao `wp-config.php` do site, e a existência delas é o único
  sinal de que o dono do site sobrescreveu o par. Um `if ( ! defined(...) )
  define(...)` no topo do plugin — padrão do WordPress — faz as duas
  existirem sempre, e a comparação do guard vira `false !== false`. Os
  defaults de produção moram em constantes de nome próprio do plugin.
- **A decisão é uma função pura**, que recebe os valores; a leitura das
  constantes fica num adaptador fino em volta. É o que permite testar o
  estado *futuro* — chave de produção já existente — antes do dia da
  implantação. Inline, dependendo de constante global, a decisão não é
  testável, e o defeito só apareceria implantando.

Receita de integração: `docs/integracao-em-plugin.md` §8.

**Origem:** o ponto 4 (regra do par) e as duas restrições acima foram
levantados pela sessão do V3RLGPD ao
integrar a biblioteca, a partir do mesmo tipo de achado que já rendeu o
guard de duas pontas do ADR-007.

---

## 3. Estrutura entregue (fatias 1, 2a e 2b — v0.3.0)

> Estado em 26/08/2026. Fatia 2 (issue #3) concluída; nada mais listado
> como `TODO(fatia-2)`.

| Classe | Papel | Estado |
|---|---|---|
| `Support\SiteIdentity` | Normaliza domínio, identifica ambiente de teste/dev (não consome cota) | completo |
| `Support\LicenseKeyMasker` | Nunca deixar chave de licença ir para log | completo |
| `Support\Logger` | Logging do lado da lib | completo |
| `Licensing\LicenseState` | Estado da licença no cliente (imutável, refletindo o servidor) | completo |
| `Licensing\LicenseStatus` | Enum de status | completo |
| `Licensing\SignatureVerifier` | Verificação ed25519 da resposta do servidor | completo |
| `Licensing\ApiClientInterface` / `ApiException` | Contrato de rede | completo |
| `Licensing\HttpApiClient` | Chamadas via transporte injetável (`WordPressHttpTransport`) | completo |
| `Licensing\LicenseManager` | Ativação/desativação/refresh, cache de 12h, grace period de 14 dias | completo |
| `Licensing\LicenseStorage` | Persistência em `wp_options`/transients (`KeyValueStoreInterface`) | completo |
| `Licensing\AdminPage` | Tela padrão em PHP, opcional (ADR-005) | completo |
| `Updater\UpdateGate` | Decide se libera update dado o `LicenseState` | completo |
| `Updater\UpdateChecker` / `PucBridge` | Liga ao mecanismo de atualização do WP via Plugin Update Checker | completo |
| `Rest\LicenseController` / `LicenseRestRouter` | Quatro rotas REST internas tela↔biblioteca, capability por operação (ADR-008) | completo |
| `Bootstrap` | Ponto de entrada do plugin hospedeiro | completo |

CI: `.github/workflows/ci.yml`, matriz PHP 7.4–8.0–8.1–8.2–8.3–8.4, com
`sodium` habilitada (obrigatória para `SignatureVerifier`). Pendente:
CI não roda em branch de feature, só em `main` e PR para `main` (#7).

### Defeito na validação ao vivo do ciclo completo (fatia 2, não pego pela suíte)

`LicenseManager::checkForUpdate()` enviava a versão **instalada** no
parâmetro que o contrato (§2.4) usa para pedir rollback. O servidor
recebia um pedido de "me dê a versão que você já tem", não encontrava
novidade e respondia `update_available: false` — assinado, legítimo, e
**nenhum site jamais veria uma atualização**, em silêncio. 126 testes
verdes com o defeito presente: o transporte falso devolvia o que o teste
programava, sem interpretar o que foi enviado. Corrigido separando
`installedVersion` de `requestedVersion`; agora há teste que verifica o
que é enviado, não só o que é recebido. Ver `docs/CHANGELOG.md` [0.1.0] e
comentário de fechamento da issue #3.

## 4. Fora de escopo desta lib

- **Texto jurídico e base legal** de licenciamento — não são gerados aqui.
- **Tela de licença imposta** a plugin com SPA própria (ADR-005).
- **`deploy.sh` por SSH** continua existindo à parte, para servidores sob
  nosso controle — canal separado do update via V3RLicense (ver issue #4).
