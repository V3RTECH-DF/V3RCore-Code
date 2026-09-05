# Arquitetura — V3RCore

> Biblioteca Composer (não é plugin), namespace `V3R\Core\`, PHP 8.2+, embutida em
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
  **O dano decisivo é entre plugins, não dentro de um:** num site com dois
  plugins da casa, o primeiro a carregar (ordem alfabética, que ninguém
  controla) define os nomes compartilhados com o seu default, e o segundo
  conclui que o dono do site sobrescreveu o par — passando a falar com a
  URL do primeiro e a conferir a chave do primeiro. Em versões diferentes,
  que é o normal, a configuração de um vaza para o outro e o guard mente.
  É o mesmo acoplamento silencioso que o Strauss resolve para namespaces,
  aqui em constantes globais.
- **A chave pendente é um estado da decisão.** Enquanto a chave pública de
  produção não existir, o default do build é um placeholder — o caminho de
  todos os sete plugins em qualquer site que não configure nada. A decisão
  recusa nesse caso, para o plugin não iniciar e falhar na verificação de
  assinatura de toda resposta; e o aviso no log sai só no par incoerente,
  nunca na chave pendente, que hoje é o estado esperado.
- **O invariante é travado por teste**, lendo o próprio arquivo principal
  do plugin à procura de `define( 'V3R_LICENSE_...' )`. Documento não
  impede reintrodução daqui a seis meses; teste impede.
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

### ADR-011 — Repositório público (27/08/2026)

`V3RCore-Code` passou a ser repositório público. A biblioteca é derivada
de GPL e já vai embutida (prefixada pelo Strauss) em cada zip distribuído
aos clientes — o sigilo do repositório não protegia nada que o cliente
não recebesse de qualquer forma. Público, o CI dos plugins que a
consomem (ver `docs/integracao-em-plugin.md` e o rollout da issue #4)
dispensa credencial para instalar a dependência via Composer, e evita
espalhar um PAT pessoal por sete repositórios — cuja rotação quebraria
todas as sete pipelines em silêncio, até a próxima tag de cada uma.

### ADR-012 — A biblioteca concede as capabilities de licença; o plugin só decide (V3RCore-Code#12, v0.4.0)

Até a v0.3.1, o `Bootstrap` exigia duas capabilities de licença
(`$readCapability`/`$manageCapability`) e deixava cada plugin hospedeiro
registrar o próprio filtro `user_has_cap` para concedê-las. O filtro
precisa sair cedo quando as capabilities pedidas não são as de licença —
esquecer isso fecha o ciclo `user_has_cap → user_can → user_has_cap` e
derruba toda requisição de usuário logado por memória esgotada
(V3RLGPD-Code#74). Dois plugins integraram; um esqueceu a guarda, o
outro só não esqueceu por acaso.

**Mudança incompatível deliberada:** a partir da v0.4.0, `Bootstrap` registra
o filtro `user_has_cap` sozinho (`Licensing\CapabilityGate`), com a guarda de
saída antecipada embutida e inescapável — ela roda antes de qualquer consulta
ao plugin hospedeiro, não depois. O plugin fornece só a função de decisão via
`Bootstrap::withCapabilityDecider(callable $decider)`, chamada exclusivamente
quando a pergunta já é sobre uma das duas capabilities de licença; dentro
dela, o plugin pode chamar `user_can()`/`current_user_can()` à vontade sem
criar recursão. `boot()` lança `\LogicException` se `withCapabilityDecider()`
não foi chamado — erro alto e imediato, para nunca cair no caminho
silencioso de "a capability simplesmente não é concedida".

**Por que método dedicado, e não um oitavo parâmetro do construtor:** a
biblioteca sustenta PHP 7.4, que não tem named arguments. Um parâmetro
obrigatório depois de `$readCapability`/`$manageCapability` (os dois
opcionais) ou vira posicional incômodo para quem só quer sobrescrever um dos
dois, ou obriga a tirar os defaults dos dois — as duas opções piores que uma
chamada fluente separada, encadeável com `boot()`.

Escopo desta ADR é só a biblioteca. V3RLGPD e V3REvent — os dois
consumidores atuais, cada um com o próprio filtro `user_has_cap` — migram
para `withCapabilityDecider()` em issue própria, depois desta versão
publicada; até lá seguem presos em `^0.3.0` no `composer.json` de cada um,
que não puxa a v0.4.0 sozinho.

### ADR-013 — Acesso por link temporário: só as duas peças que não tocam identidade (V3RCore-Code#24, v0.8.0)

Dois consumidores (RIT360 Solidário e V3REvent) precisam da mesma mecânica
de acesso por link temporário verificado por e-mail. O levantamento
considerou subir o serviço de link inteiro — sessão, tabela de tokens,
consumo atômico, conceito genérico de "sujeito" — e não só o segredo e o
limitador.

**Decisão:** sobem para `V3R\Core\Access\` apenas `AccessToken` (segredo,
hash, comparação em tempo constante) e `AttemptLimiter` (limite de
tentativas por duas chaves, sobre `Licensing\Storage\KeyValueStoreInterface`).
Critério de corte duplo: a peça não pode tocar identidade nenhuma, e o erro
nela tem que ter consequência de segurança — é o que distingue o que sobe
do que fica em cada produto.

**Por quê não generalizar mais:** os dois consumidores divergem exatamente
no ponto que uma abstração de "sessão" ou "sujeito do acesso" precisaria
fixar. Solidário tem doador com id estável — a sessão carrega uma pessoa.
V3REvent não tem equivalente — o mesmo e-mail pode ser responsável de N
inscrições e participante de M inscritos, então a sessão carrega um
conjunto. Generalizar a partir de dois casos que discordam no ponto que a
abstração fixaria produz a abstração errada: serve mal aos dois e é cara
de desfazer depois. Pelo mesmo motivo não sobem a tabela de tokens nem o
consumo atômico (UPDATE condicional) — dependem da tabela do produto, e um
armazenamento chave/valor não dá a mesma garantia de atomicidade.

**Consequência prática:** `AttemptLimiter::registerAttempt()` é o único
método de decisão, e incrementa as duas chaves incondicionalmente — lida
antes do incremento, nunca dentro dele — para o bloqueio não virar oráculo
de existência de e-mail. Catálogo completo, com exemplos de uso e o que
continua sendo do produto: `docs/acesso-por-link-temporario.md`.

### ADR-014 — Ativo de front-end da biblioteca mora dentro de `src/`, e o hospedeiro enfileira (V3RCore-Code#23, v0.9.0)

A v3r-core nasceu como biblioteca PHP embutida por Strauss. A promoção do
sugeridor de domínio de e-mail (#23) forçou a decisão que faltava: **algumas
peças só entregam valor com uma metade no navegador** — a sugestão aparece
enquanto a pessoa digita, ou não serve para nada. Distribuir só o PHP entrega
metade do serviço; deixar o JS em cada plugin faz as duas metades descolarem,
que é exatamente o que a promoção existe para evitar.

**Decisão, em quatro partes:**

1. **Os ativos moram dentro de `src/`** (`src/Assets/js`, `src/Assets/data`), e
   não numa pasta irmã na raiz. Não é organização — é mecânica: o Strauss copia
   para `vendor-prefixed/` o que está sob o caminho do autoload PSR-4 do
   pacote, **inclusive arquivos que não são PHP**, e ignora o que está fora.
   Ativo em `assets/` na raiz simplesmente não chega ao plugin empacotado.
   Verificado executando o Strauss contra um pacote de teste e contra a
   própria biblioteca: o `.js` e o `.json` chegam intactos (o prefixador não os
   toca) ao lado do PHP já prefixado.
2. **A URL é derivada do caminho real do arquivo**, nunca fixa: ela depende de
   onde o hospedeiro instalou a biblioteca. `Frontend\AssetLocator` resolve via
   `plugins_url()` a partir do próprio caminho; hospedeiro fora de
   `wp-content/plugins` (mu-plugin, tema) informa a base no construtor.
3. **A versão do ativo é a data de modificação do arquivo**, não a versão do
   plugin — a versão do plugin identifica a release, não o pacote gerado, e já
   produziu na casa cache servindo o arquivo anterior. Ativo ausente devolve
   versão nula em vez de uma constante: versão inventada congelaria o cache num
   arquivo que mudou.
4. **Nada é enfileirado sozinho.** O `AssetLocator` não registra hook nenhum;
   quem quer o ativo chama `enqueueScript()`. Plugin que não quer o front não
   paga nada — a distribuição da biblioteca continua opt-in por desenho.

**Consequência que atravessa as próximas peças:** toda peça com lado de
navegador segue este caminho, e o par PHP/JS é exercitado por um **conjunto de
casos compartilhado**, versionado junto do ativo (`src/Assets/data/`), para que
uma correção aplicada em só uma das metades quebre a outra no mesmo commit.

### ADR-015 — A chave de cifragem do cofre de certificado não segue a convenção de constante embutida no pacote (V3RCore-Code#27, v0.11.0)

O licenciamento (ADR-010) embute um único par de constantes de produção no
build do plugin, e isso é deliberado: a chave pública ali **não é segredo**
— é a mesma em todo plugin da casa, e qualquer um que a tivesse não
aprenderia nada de útil com ela.

**Aqui a propriedade é o oposto, e é o que impede reaproveitar o mesmo
padrão.** `CertificateSecretVault` cifra a senha do certificado digital de
cada cliente antes de guardá-la, e a chave de cifragem **precisa ser
secreta e própria de cada site**. Se ela viesse embutida no pacote do
plugin — como o par de licenciamento — qualquer pessoa que baixasse o
plugin teria a chave que decifra a senha de certificado de **qualquer**
cliente que o rodasse, porque o pacote é o mesmo para todos.

**Decisão:** não há default de produção, nem placeholder, nem função de
fallback para essa chave. `CertificateSecretVault::fromConstant()` lê uma
constante que o **próprio site** define no `wp-config.php`
(`V3R_SIGNING_ENCRYPTION_KEY`, por convenção) e, na ausência ou no formato
inválido dela, `isAvailable()` responde falso — o cofre recusa cifrar ou
decifrar, nunca grava a senha em texto claro como alternativa. Cabe ao
plugin hospedeiro gerar essa chave por site (ex.:
`base64_encode(random_bytes(32))`) e orientar o administrador a configurá-la
— ver `docs/integracao-em-plugin.md` §7.4.

**Por que isto não contradiz a recusa de cifrar documentos em repouso no
RIT360 Flow:** perder esta chave é degradação **recuperável**, não perda
de dados — os documentos já emitidos continuam abrindo e o código de
autenticidade (`AuthenticityRegistry`) continua conferindo, porque nenhum
dos dois depende desta chave; só o certificado precisa ser recadastrado.
Cifrar em repouso um documento cuja chave se perde de vez seria perda
definitiva, e essa é a diferença que decide o corte, não o fato de uma
coisa ser "segredo" e a outra não.

---

## 3. Estrutura entregue (fatias 1, 2a e 2b — v0.4.0)

> Estado em 26/08/2026, atualizado em 27/08/2026 (v0.4.0: `Licensing\CapabilityGate`,
> ADR-012/#12; v0.3.1: fixes #8/#10, repositório público), em 03/09/2026
> (v0.8.0: namespace novo `V3R\Core\Access\`, ADR-013/#24), em 03/09/2026
> (v0.9.0: `Support\EmailSuggestion` e `Frontend\AssetLocator`, ADR-014/#23)
> e em 04/09/2026 (v0.10.0: namespace novo `V3R\Core\Documents\`, `Cnpj` e
> `Cpf`, #22; v0.11.0: namespace novo `V3R\Core\Signing\`, ADR-015/#27).
> Fatia 2 (issue #3) concluída; nada mais listado como `TODO(fatia-2)`.

| Classe | Papel | Estado |
|---|---|---|
| `Support\SiteIdentity` | Normaliza domínio, identifica ambiente de teste/dev (não consome cota) | completo |
| `Licensing\CapabilityGate` | Concede as capabilities de licença via `user_has_cap`, guarda de saída antecipada inescapável (ADR-012) | completo |
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
| `Access\AccessToken` | Segredo de link temporário: geração, hash, comparação em tempo constante (ADR-013) | completo |
| `Access\AttemptLimiter` | Limite de tentativas por duas chaves, incremento incondicional (ADR-013) | completo |
| `Support\EmailSuggestion` | Sugestão de correção de domínio de e-mail (nunca bloqueia), promovida do V3REvent (ADR-014) | completo |
| `Frontend\AssetLocator` | Distribuição de ativo de front-end da biblioteca (URL e versão derivadas do arquivo, opt-in) (ADR-014) | completo |
| `Documents\Cnpj` | Validação de CNPJ numérico e alfanumérico (`normalize()`/`isValid()`/`format()`), promovida de quatro cópias (#22) | completo |
| `Documents\Cpf` | Validação de CPF, mesma API de `Documents\Cnpj` (#22) | completo |
| `Signing\AuthenticityCode` / `AuthenticityRecord` / `AuthenticityRegistry` / `AuthenticityVerification` / `AuthenticitySealingException` | Código de autenticidade emitido (nunca derivado); `issue()`/`seal()` separam emitir de gravar o resumo sha256, porque o código é impresso dentro do documento (#28); conferência por consulta, com terceiro estado "emitido e ainda não selado" (#27, #28) | completo |
| `Signing\SigningMode` / `SigningModeReason` / `SigningModeResolver` / `SigningModeDecision` | Decisão pura e conservadora do modo de assinatura, sempre com o motivo (#27) | completo |
| `Signing\CertificateSecretVault` / `CertificateMaterial` / `CertificateVaultException` | Cofre da senha do certificado, cifrada com chave própria do site — nunca embutida no pacote (ADR-015) | completo |
| `Signing\EphemeralSecretFile` | Material sensível em disco fora da área servida pela web, remoção garantida e varredura de sobras (#27) | completo |
| `Signing\SignerInterface` / `SigningException` | Contrato do assinador — a biblioteca não gera PDF nem ganha dependência de terceiro (#27) | completo |

CI: `.github/workflows/ci.yml`, matriz PHP 8.2–8.3–8.4, com
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

### Rollout: segundo plugin integrado (V3REvent, 27/08/2026)

Primeiro plugin depois do piloto V3RLGPD (issue #4). A receita de
`docs/integracao-em-plugin.md` foi seguida seção por seção e funcionou;
achados que a complementam (ambos incorporados ao documento):

- **A tela de licença não é opcional na prática** — ver
  `docs/integracao-em-plugin.md` §7.2.
- **Armadilha do `user_has_cap` recursivo**, abaixo.

No V3REvent, a licença ganhou aba própria em Configurações (consumindo
os endpoints REST internos), não a tela padrão da biblioteca — motivado
pela issue #11 (rótulo "Licença" sem identificar o produto, indistinguível
num site com dois plugins da casa usando a tela padrão). **Corrigida na
v0.5.0**: `Licensing\AdminPage` nomeia o produto no rótulo do menu e no
título da página via `Bootstrap::withProductName()`, opcional — quem usar
a tela padrão a partir daqui não tem mais essa colisão.

Faltam no rollout: V3RHelp, V3RProp (depende de composerizar —
`V3RProp-Code#57`), GE Associados, RIT360 Solidário, RIT360 Premiado.

### Armadilha conhecida — `user_has_cap` recursivo nas capabilities de licença (corrigida na biblioteca, v0.4.0)

Até a v0.3.1, o filtro `user_has_cap` que concedia as capabilities
sintéticas de licença era responsabilidade de cada plugin hospedeiro, que
precisava lembrar de **sair cedo** quando as capabilities pedidas na
chamada não eram as de licença. Sem essa saída antecipada, o ciclo
`user_has_cap → user_can → user_has_cap` é infinito e derruba **toda
requisição de usuário logado** por memória esgotada — inclusive
`wp-login.php`. Requisição anônima passa normalmente, então o site parece
no ar visto de fora; só quem tenta logar (ou já está logado) trava.

Já aconteceu de verdade: **V3RLGPD-Code#74**, corrigido no plugin — o
filtro equivalente consultava as permissões antes de olhar quais
capabilities foram pedidas. O V3REvent já nasceu com a guarda, por acaso
(quem escreveu tinha o padrão corrigido fresco na cabeça, não porque a
documentação o prescrevesse) — nada garantia que o próximo plugin
acertasse também.

**Corrigida na biblioteca em V3RCore-Code#12 (v0.4.0, ADR-012).** A
guarda deixou de ser responsabilidade de cada plugin: `Bootstrap` registra
o filtro `user_has_cap` sozinho, via `Licensing\CapabilityGate`, com a
saída antecipada embutida e inescapável — ela roda antes de qualquer
consulta à função de decisão do plugin, nunca depois. A função de decisão
(`Bootstrap::withCapabilityDecider()`) só é chamada quando a pergunta já
é sobre uma das duas capabilities de licença; dentro dela, o plugin chama
`user_can()`/`current_user_can()` à vontade sem criar recursão. O teste
que prova isso — `tests/Licensing/CapabilityGateNoRecursionTest.php` —
migrou para a biblioteca no mesmo formato usado nos dois plugins
(`LicenseCapsNoRecursionTest`): conta **chamadas**, não confere retorno,
porque recursão infinita não devolve resposta errada, devolve estouro de
memória; um teste de retorno passaria com o código defeituoso.

V3RLGPD e V3REvent continuam com o filtro próprio até migrarem para
`withCapabilityDecider()` — issue separada, depois desta versão
publicada; `composer.json` de cada um fixa `^0.3.0` e não puxa a v0.4.0
sozinho.

## 4. Fora de escopo desta lib

- **Texto jurídico e base legal** de licenciamento — não são gerados aqui.
- **Tela de licença imposta** a plugin com SPA própria (ADR-005).
- **`deploy.sh` por SSH** continua existindo à parte, para servidores sob
  nosso controle — canal separado do update via V3RLicense (ver issue #4).
