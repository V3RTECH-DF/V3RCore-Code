# Changelog — V3RCore

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/); versionamento [SemVer](https://semver.org/lang/pt-BR/).

## [0.4.0] — 2026-08-27

### Alterado (BREAKING)
- **A biblioteca passa a conceder ela mesma as capabilities de licença
  (#12).** Até a v0.3.1, `Bootstrap` exigia as duas capabilities
  (`$readCapability`/`$manageCapability`) e deixava cada plugin
  hospedeiro registrar o próprio filtro `user_has_cap` para concedê-las —
  e lembrar, por conta própria, de sair cedo quando as capabilities
  pedidas não eram as de licença. Esquecer essa guarda fecha o ciclo
  `user_has_cap → user_can → user_has_cap`, infinito, e derruba toda
  requisição de usuário logado por memória esgotada; foi o que aconteceu
  de verdade em produção (V3RLGPD-Code#74). Um plugin esqueceu, o outro só
  não esqueceu por acaso.
- `Bootstrap` agora registra o filtro sozinho (`Licensing\CapabilityGate`),
  com a guarda de saída antecipada embutida e inescapável, rodando antes
  de qualquer consulta ao plugin. O plugin fornece só a função de decisão,
  via `Bootstrap::withCapabilityDecider(callable $decider)` — chamada
  exclusivamente quando a capability pedida já é a de leitura ou a de
  gestão da licença; dentro dela, o plugin chama
  `user_can()`/`current_user_can()` livremente, sem risco de recursão.
- `Bootstrap::boot()` agora lança `\LogicException` se
  `withCapabilityDecider()` não foi chamado antes — deliberado: sem função
  de decisão a biblioteca não tem como conceder as capabilities com
  segurança, e "simplesmente não concede" é o caminho silencioso que esta
  mudança existe para fechar.
- Migração incompatível de propósito (nova versão MINOR por ainda estar
  em `0.x`, SemVer). V3RLGPD e V3REvent seguem com o filtro próprio até
  migrarem em issue dedicada; `composer.json` de cada um fixa `^0.3.0` e
  não puxa esta versão sozinho.
- Documentação: `docs/integracao-em-plugin.md` §7 (assinatura nova no
  exemplo) e novo §7.1; `docs/ARCHITECTURE.md` ADR-012 e a seção
  "Armadilha conhecida" atualizada para "corrigida".

## [0.3.1] — 2026-08-27

### Corrigido
- **`requires` sumia do transiente de atualização do WordPress
  (#8).** O campo não está em `Update::getFieldNames()` nem em
  `$extraFields` do `plugin-update-checker` upstream, então morria na
  cópia `PluginInfo` → `Update` e o `toWpFormat()` nunca o emitia — mesmo
  o servidor mandando o valor e o `PucBridge` atribuindo-o corretamente.
  Reinjetado pelo filtro `pre_inject_update`, que o próprio PUC expõe
  entre a conversão e a serialização para o WordPress; sem fork do
  upstream e sem tocar em `vendor/`. Sem esse campo, o WordPress não sabe
  avisar que a atualização exige uma versão mínima dele, e pode oferecê-la
  a um site que não a suporta.
- **Link "Ver detalhes da versão" ficava sem destino (#10).** O
  `PucBridge` nunca preenchia `homepage`, e é dele que o `toWpFormat()`
  tira o campo `url` do transiente — justamente o único lugar onde o
  cliente decidiria se atualiza. Passa a vir do `changelog_url` que o
  servidor já manda, que aponta para a página de novidades do manual do
  usuário. Ausente o `changelog_url`, `homepage` fica `null` em vez de
  string vazia: link para lugar nenhum é pior que link ausente.

As duas foram achadas na validação ao vivo do ciclo completo contra
produção (V3RLicense-Code#14), com o V3RLGPD atualizando 1.66.1 → 1.67.0
de verdade, e corrigidas antes de os outros seis plugins integrarem — a
biblioteca é embutida, não compartilhada, então corrigir depois custaria
sete rebuilds e sete releases.


### Adicionado
- **ADR-010 — par único de constantes `V3R_LICENSE_API_URL` /
  `V3R_LICENSE_PUBLIC_KEY` para os sete plugins clientes.** Decisão de
  rollout: um único par genérico, compartilhado por todos os plugins da
  casa (não sete pares com prefixo próprio); a chave pública é constante,
  não variável de ambiente; o par de produção é o default embutido no
  build; e as duas constantes só podem ser sobrescritas juntas no
  `wp-config.php` — regra do par, achado da execução real do V3RLGPD. Ver
  `docs/ARCHITECTURE.md` ADR-010 e `docs/integracao-em-plugin.md` §8.

### Corrigido
- **Implementação de referência da §8 decidia pela existência da constante
  e não guardava nada** se o plugin definisse os nomes compartilhados com
  `if ( ! defined(...) ) define(...)`, padrão do WordPress: as duas
  passariam a existir sempre e a comparação do guard viraria
  `false !== false`. A referência passou a separar a decisão (função pura,
  recebendo os valores) da leitura das constantes, e a ADR-010 registra as
  duas restrições — o plugin não define os nomes compartilhados, e o valor
  igual ao default não serve como sinal de "não configurado". Achado da
  execução real (V3RLGPD).
- **A §8 e a ADR-010 ganharam o motivo decisivo da regra "o plugin não
  define os nomes compartilhados": o vazamento entre plugins.** Num site
  com dois plugins da casa, o primeiro a carregar define o par com o seu
  default e o segundo passa a usar a URL e a chave do primeiro — em
  versões diferentes, silenciosamente. Também entraram o ramo
  `chave_pendente` na decisão (o caminho de todos os sete plugins
  enquanto a chave de produção não existir), o aviso no log restrito ao
  par incoerente, e o teste que trava o invariante lendo o arquivo
  principal do plugin. Todos achados da execução real (V3RLGPD).

## [0.3.0]

### Adicionado
- **Capability por operação nos endpoints REST internos (#9).** `Bootstrap`
  passa a aceitar `$readCapability` (sexto argumento, como antes) e
  `$manageCapability` (sétimo, opcional). `GET .../license` e
  `POST .../license/refresh` checam a capability de leitura;
  `POST .../license/activate` e `POST .../license/deactivate` checam a de
  gestão. Sem o sétimo argumento, gestão cai para leitura — quem já
  integra com uma capability só continua se comportando exatamente como
  antes. `Bootstrap::getReadCapability()`/`getManageCapability()` novos;
  `getCapability()` mantido como alias, agora devolvendo a capability de
  gestão. Corrige o caso em que qualquer portador de papel do plugin
  hospedeiro (não só quem administra) podia desativar a licença via
  chamada direta ao endpoint, mesmo com a tela escondendo o botão. Ver
  `docs/api-contract.md` §8.2.

### Corrigido
- `docs/integracao-em-plugin.md` §7 — assinatura de bootstrap e nota de
  capability atualizadas para as duas capabilities.

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

Primeira tag consumível — fatias 1, 2a e 2b concluídas. Continha o
defeito de auto-prefixação descrito em `[0.2.0]`; não usar para integração
nova.

### Adicionado
- **Fatia 1 — esqueleto da biblioteca (#1)** — estrutura PSR-4 `V3R\Core\`,
  Composer + Strauss para prefixar a lib e suas dependências transitivas em
  cada plugin hospedeiro, CI em matriz PHP 7.4–8.0–8.1–8.2–8.3–8.4.
  `Support\SiteIdentity` (normalização de domínio e detecção de ambiente de
  teste/desenvolvimento, sem consumir cota), `Support\LicenseKeyMasker`,
  `Support\Logger`. `Licensing\LicenseState`, `LicenseStatus`,
  `SignatureVerifier` (verificação ed25519 da resposta do servidor).
  `Updater\UpdateGate`. `Bootstrap` como ponto de entrada. `docs/api-contract.md`
  — contrato completo da API `v3r-license/v1` entre esta lib e o servidor
  V3RLicense, para as duas pontas implementarem de forma independente.
- **Fatia 2a — comunicação cliente-servidor, cache e grace period** —
  `HttpApiClient` via transporte injetável (testável sem WordPress);
  `LicenseStorage` em `wp_options`/transient via `KeyValueStoreInterface`;
  `LicenseManager` com ativação, desativação e refresh com **cache de
  12h** e **grace period de 14 dias**. Assinatura ausente, malformada ou
  inválida nunca vira licença válida — sempre falha de comunicação, mesmo
  caminho de timeout/5xx. Confirmação assinada de
  `expired`/`revoked`/`invalid` suspende o update sem grace.
- **Fatia 2b — integração com o WordPress, endpoints REST internos e
  tela padrão** — `Updater\UpdateChecker`/`PucBridge` liga a lib ao
  mecanismo de atualização do WordPress via Plugin Update Checker, sempre
  atrás do `UpdateGate`. `Rest\LicenseController`/`LicenseRestRouter`
  registra as **quatro rotas REST internas** do protocolo tela↔biblioteca
  (`GET .../license`, `POST .../license/{activate,deactivate,refresh}`,
  ver `docs/api-contract.md` §8). `AdminPage` padrão, em PHP, opcional —
  para plugin sem SPA própria (ADR-005).

### Corrigido
- Autoloader carregava o `plugin-update-checker` original em vez do
  prefixado pelo Strauss (#2).
- **`LicenseManager::checkForUpdate()` enviava a versão instalada como
  pedido de rollback** — pelo §2.4 do contrato, o parâmetro `version`
  significa "me dê esta versão específica". O servidor procurava a
  versão que o cliente já tinha, não achava novidade e respondia
  `update_available: false`, assinado e legítimo: **nenhum site jamais
  veria uma atualização**, em silêncio. Corrigido separando
  `installedVersion` (sempre enviada) de `requestedVersion` (só rollback
  explícito); `PucBridge` passou a ler a versão instalada do cabeçalho
  real do arquivo, não do valor fixado na construção do `Bootstrap`.

### Emendado
- **§7.1 do contrato — resposta de erro não é assinada, logo nunca é
  autoritativa para suspender.** Só payload assinado suspende. Aceitar um
  `403` como prova permitiria a quem controla a rede cortar a atualização
  de um cliente legítimo; o sistema converge para o estado seguro pelo
  decurso da graça de 14 dias, nunca por resposta não autenticada.
- `ApiException` ganha o código `SIGNATURE_INVALID` (distinto de
  `COMMUNICATION_FAILURE`, mas com `isCommunicationFailure()` continuando
  `true` para os dois), para o protocolo interno responder
  `signature_invalid` (502) separado de `server_unreachable` (503) — o
  protocolo externo não distinguia os dois por design.

### Decisões de arquitetura
- Ver `docs/ARCHITECTURE.md` (ADR-001 a ADR-006): updater e licenciamento na
  mesma lib, ed25519 em vez de HMAC, ativações com padrão no produto e valor
  efetivo na licença, licença expirada nunca derruba o plugin, a lib entrega
  capacidade e não tela imposta, bootstrap tolerante à ausência de Composer
  em runtime.
