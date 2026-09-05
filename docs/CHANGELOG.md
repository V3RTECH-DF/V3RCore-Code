# Changelog — V3RCore

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/); versionamento [SemVer](https://semver.org/lang/pt-BR/).

## [0.13.0] — 2026-09-05

### Adicionado
- **`Signing\CertificateInspector` — o módulo `Signing\` ganha quem olha para
  DENTRO do certificado (#29), promovido do RIT360 Flow.**
  `SigningModeResolver::decide()` já recebia `?DateTimeImmutable $expiresAt`
  como fato apurado, mas nada na biblioteca apurava esse fato — cada
  consumidor teria de abrir o PKCS#12 por conta própria, ou copiar a lógica
  do Flow, para responder à pergunta que a própria biblioteca faz.
  `inspect( CertificateMaterial $material )` abre o arquivo com a senha do
  material (o mesmo objeto que `SignerInterface::sign()` já recebe — sem
  parâmetro novo), único ponto que chama `openssl_pkcs12_read()`: a mesma
  abertura confirma que a senha bate e que o conteúdo é mesmo um
  certificado com chave privada. Devolve `CertificateInspection`
  (`expiresAt()` + `subject()`), no dialeto que o módulo já usa para
  resultado de operação (`AuthenticityVerification`) — pronta para
  alimentar `SigningModeResolver::decide()` direto.
- **`Signing\CertificateSubject` — o titular lido do certificado**: nome,
  tipo e dígitos do documento (CNPJ ou CPF), emissor, e se a identidade é
  atestada ou apenas declarada. `maskedDocument()` delega a
  `Documents\Cnpj::format()`/`Documents\Cpf` (nunca reimplementa a
  máscara): CNPJ sai inteiro e formatado, CPF sai mascarado — a mesma
  regra de exposição do Flow.
- **Três decisões conservadoras preservadas na promoção, deliberadamente,
  porque já foram compradas com erro evitado no Flow:**
  1. Sem validade reconhecida no certificado, `expiresAt() === null` —
     nunca uma data inventada; é esse `null` que faz o resolver cair em
     `SEM_VALIDADE_CONHECIDA`.
  2. `subjectAltName` NÃO é usada para extrair documento. O PHP não
     decodifica o `othername` da ICP-Brasil, e varrer aquele bloco atrás
     de 11 dígitos pegaria NIS ou RG no lugar do CPF. As fontes são o
     nome comum no formato `NOME:DOCUMENTO` e, em seguida,
     `serialNumber`/`organizationIdentifier` — só o campo inteiro, com
     exatamente 11 ou 14 dígitos.
  3. "Atestado" significa "não autoassinado", não "emitido pela
     ICP-Brasil". Certificado de AC privada conta como atestado —
     restringir à ICP-Brasil exigiria lista de emissores confiáveis, que
     é decisão de produto não tomada. Emissor ausente ou ilegível é
     tratado como declarado, o lado conservador.
- **Ausência da extensão `openssl` degrada, nunca é fatal — o risco novo
  que a promoção cria.** Hoje a chamada mora no plugin que sabe que
  assina; na biblioteca, ela passa a viajar dentro de todo plugin que
  carrega a v3r-core. `ext-openssl` NÃO entra no `require` do composer —
  quebraria a instalação de quem nunca assina — só em `suggest`;
  verificação em tempo de execução, e a ausência produz
  `CertificateInspection::failure()`, mesmo caminho que qualquer outra
  causa de "não deu para ler o certificado". É a quarta decisão
  conservadora, e ela nasce nesta versão.
- Depois desta promoção, o RIT360 Flow troca a implementação local por uma
  fachada sobre esta peça, no padrão que já usa para outras integrações
  com a biblioteca — fora do escopo desta entrega.

## [0.12.0] — 2026-09-05

### Corrigido
- **`AuthenticityRegistry::issue()` exigia o arquivo final para calcular o
  resumo — mas o código de autenticidade é impresso DENTRO do documento,
  então no instante da emissão o arquivo final ainda não existe (#28).**
  Quem chamava era obrigado a calcular o resumo de um artefato
  intermediário, sem o código impresso, e o resumo gravado nunca batia
  com o arquivo que a pessoa recebia depois: `verifyFile()` respondia
  "documento adulterado" para documentos íntegros. Emitir e selar agora
  são dois momentos separados: `issue( $mode )` sorteia o código e grava
  um registro sem resumo; `seal( $code, $absoluteFilePath )` recebe o
  código e o caminho do arquivo já pronto (com o código já impresso
  nele), calcula o sha256 e grava. É mudança de assinatura — a lib está
  em 0.x e o único consumidor (RIT360 Flow) troca junto; não manteve o
  parâmetro antigo por compatibilidade.
- **Selar é uma vez só, sem entijolar:** selar de novo com o MESMO resumo
  é aceito e não faz nada (permite refazer uma tentativa que falhou entre
  emitir e selar); selar com um resumo DIFERENTE é recusado com
  `AuthenticitySealingException` (`RESUMO_DIVERGENTE`), porque aceitar
  trocaria o que o registro promete depois de já selado. A mesma exceção
  cobre código inexistente (`CODIGO_INEXISTENTE`) e arquivo
  inexistente/ilegível (`ARQUIVO_ILEGIVEL`) — nunca em silêncio, e nunca
  gravando registro novo.
- **`AuthenticityVerification` ganha um terceiro estado — "emitido e
  ainda não selado" — ao lado de "não existe" e "existe e confere".**
  Antes, um registro sem resumo caindo no `fileMatches` booleano teria
  virado `wasTampered() === true`: a página teria afirmado "este
  documento foi adulterado" sobre um documento intacto. Um registro não
  selado agora nunca produz `wasTampered() === true`, e
  `isAwaitingSeal()` deixa quem consome distinguir "não confere" de "não
  há como conferir ainda" sem inspecionar campo nulo por conta própria.
  `AuthenticityRegistry::verifyFile()` sobre um registro não selado
  devolve `AuthenticityVerification::awaitingSeal()`.
- **`AuthenticityRecord::fromArray()` aceita a ausência do resumo** — o
  campo `file_hash` passa a ser opcional no formato persistido, refletindo
  o registro emitido e ainda não selado. Registro gravado antes desta
  versão (que sempre tem o campo) continua lendo exatamente como antes.

## [0.11.0] — 2026-09-04

### Adicionado
- **Namespace novo `V3R\Core\Signing\` — primeira fatia da peça de
  assinatura de documentos, para o RIT360 Flow e o V3RProp consumirem em
  vez de manterem duas versões divergentes (#27).** O V3RProp já tem um
  mecanismo próprio que funciona, e é dele que vêm os quatro defeitos que
  esta peça existe para não repetir (V3RProp-Code#62, #63 e #64): (1) o
  documento não dizia como foi assinado — a página era idêntica com e sem
  certificado digital; (2) o "código de autenticidade" era derivado de
  campos públicos, calculado diferente conforme o motor de PDF e guardado
  em lugar nenhum — irreproduzível e inverificável; (3) não existia nada
  que conferisse um documento emitido; (4) a senha do certificado ficava
  em texto claro e a chave privada era escrita em pasta pública durante a
  assinatura.
- **O corte:** a biblioteca não gera PDF e não ganha dependência de
  terceiro — bibliotecas de PDF são pesadas, trazem constantes globais e
  brigariam com a prefixação feita pelo hospedeiro. Ela define o contrato
  do assinador (`SignerInterface`) e guarda o que é sensível; a
  implementação concreta e a geração do documento ficam com cada plugin.
- **`AuthenticityCode` / `AuthenticityRecord` / `AuthenticityRegistry` /
  `AuthenticityVerification`** — o código de autenticidade passa a ser
  **emitido, não derivado**: imprevisível (CSPRNG sobre um alfabeto de 31
  símbolos, sem os caracteres que se confundem à mão — `0/O`, `1/I/L`),
  gerado na emissão e guardado junto do modo de assinatura e do resumo
  sha256 do arquivo. A conferência é **consulta**, nunca recálculo, e
  `verifyFile()` distingue "código nunca existiu" (`notFound()`) de "o
  arquivo foi alterado depois de emitido" (`wasTampered()`).
- **`SigningMode` / `SigningModeReason` / `SigningModeResolver` /
  `SigningModeDecision`** — a decisão do modo de assinatura é uma função
  pura e conservadora: qualquer incerteza (sem certificado, validade
  desconhecida, certificado vencido) degrada para `REGISTRO_ELETRONICO`,
  nunca o contrário, e o motivo (`SigningModeReason`) vem sempre junto —
  nunca existe modo sem motivo. É o que explica ao administrador por que
  a assinatura não saiu como ele esperava, em vez de degradar em silêncio.
- **`CertificateSecretVault` / `CertificateMaterial` /
  `CertificateVaultException`** — cofre da senha do certificado, cifrada
  com `sodium_crypto_secretbox` (XSalsa20-Poly1305, autenticada) sob uma
  chave que **não vem embutida no pacote do plugin** (ver ADR-015). Sem
  chave configurada e utilizável, o cofre recusa operar
  (`CHAVE_DE_CIFRAGEM_INDISPONIVEL`) — nunca grava em texto claro como
  alternativa. Perder a chave é degradação recuperável, não perda de
  dados: os documentos já emitidos continuam abrindo e o código de
  autenticidade continua conferindo; só o certificado precisa ser
  recadastrado.
- **`EphemeralSecretFile`** — entrega do material sensível em disco (para
  quando o assinador exigir arquivo, não bytes em memória) fora da área
  servida pela web, com permissão restrita (`chmod 0600`), nome
  imprevisível, remoção garantida no encerramento do processo
  (`register_shutdown_function` + `__destruct()` como segunda rede) e
  `sweepOrphans()` para o caso não coberto por nenhum dos dois — processo
  morto por sinal não capturável (`kill -9`, OOM killer) — que o
  hospedeiro roda periodicamente (ex.: cron).
- **ADR-015**, registrada em `docs/ARCHITECTURE.md`: a chave que cifra a
  senha do certificado **não segue** a convenção de constante embutida no
  pacote usada pelo licenciamento (ADR-010). Catálogo completo do
  namespace, com o que integrar e o que configurar:
  `docs/integracao-em-plugin.md` §7.4.
- Migração aditiva — MINOR, não MAJOR: só namespace e classes novos;
  nenhuma API existente muda.
- Suíte: 350 testes PHPUnit (1733 asserções), verdes — 46 deles (1087
  asserções) exercitam só `Signing\`.

## [0.10.0] — 2026-09-04

### Adicionado
- **Namespace novo `V3R\Core\Documents\` — `Cnpj` e `Cpf`, promovidos de
  quatro cópias na casa** (GE Associados, V3REvent, V3RLGPD, RIT360 Flow; o
  RIT360 Solidário tinha a sua de CPF; #22). Classes puras, sem WordPress,
  com a mesma API nas duas: `normalize()`, `isValid()`, `format()`. Divergir
  no dialeto entre elas reintroduziria dentro da biblioteca a diferença que
  a promoção veio eliminar. Motivo da promoção: o CNPJ alfanumérico muda a
  regra de formação, e cada cópia era uma chance de ficar para trás e passar
  a recusar CNPJ válido (ou aceitar inválido) calada, num campo que alimenta
  documento com validade jurídica.
- **CNPJ alfanumérico** (regra da Receita Federal, produção a partir de
  julho de 2026): as 12 primeiras posições aceitam `0-9` e `A-Z`, os dois
  dígitos verificadores continuam numéricos, máscara inalterada. O DV é
  módulo 11 com os pesos clássicos sobre `ASCII(c) - 48` (`0-9` → 0-9, `A-Z`
  → 17-42); como para dígito esse valor é o próprio dígito, o CNPJ numérico
  é caso particular do alfanumérico — uma implementação valida os dois, e a
  retrocompatibilidade é por construção, sem ramo separado que possa ficar
  para trás.
- A biblioteca entrega a regra, não o modelo: quem tem objeto-valor no
  próprio domínio (GE Associados, com `from()`/`equals()`) mantém a classe e
  passa a delegar só a validação — não precisa reescrever domínio para
  consumir.
- Três decisões de comportamento registradas em
  `docs/documentos-cnpj-cpf.md`: (a) "todos os caracteres iguais" é
  recusado — convenção da casa, não regra da Receita, e por construção não
  alcança alfanumérico legítimo, já que os verificadores numéricos impedem
  que um CNPJ com letra tenha os 14 caracteres iguais; (b) `format()` de
  entrada incompleta devolve o normalizado, nunca o texto cru — **única
  mudança de comportamento da migração**, porque a cópia de CPF do RIT360
  Solidário devolvia a entrada original; (c) a normalização remove o que não
  é dígito/letra em vez de recusar a entrada — herdado das quatro cópias,
  mantido de propósito, e o preço é aceitar caractere grudado.
- Comparação das quatro cópias antes da promoção, pelo risco de divergirem
  (exigida pela issue): 200 mil entradas (aleatórias, documentos válidos
  gerados e vizinhos com um caractere trocado) passadas pelas quatro
  implementações e pela nova — zero divergências de validade. O que
  divergia era a forma da API (objeto-valor vs. estático; `is_valid` vs.
  `isValid`) e o `format()` citado acima. O risco registrado na issue era
  prospectivo, não uma divergência já instalada.
- Testes: vetores oficiais do documento da Receita (`12.ABC.345/01DE-35`,
  com o cálculo demonstrado passo a passo, e `12.345.678/0001-95`), mais
  vetores de borda do módulo 11 com resto 0 e resto 1 para cada
  verificador — sem o caso de resto 1, `resto < 2` e `resto === 0` são
  indistinguíveis e a versão errada recusa calada um documento legítimo.
  Suíte: 304 testes PHPUnit (646 asserções) + 40 no espelho JS, verdes.
- Catálogo do componente: `docs/documentos-cnpj-cpf.md`.
- Consumidor imediato: RIT360 Flow (formulário público de cadastro de
  organizações), que deixa de criar a quinta cópia.
- Migração retrocompatível — MINOR, não MAJOR: só acréscimo de namespace e
  classes; nenhuma API existente muda.

## [0.9.0] — 2026-09-03

### Adicionado
- **`Support\EmailSuggestion` — sugestão de correção de domínio de e-mail,
  promovida do V3REvent (V3REvent-Code#157, v1.76.0; #23).** `defaultDomains()`
  devolve uma lista embutida de 13 domínios comuns no Brasil; `suggest(string
  $email, array $knownDomains): ?string` compara o domínio digitado contra a
  lista e devolve a correção provável, ou `null`. Regra inegociável: **sugere,
  nunca bloqueia** — quem chama nunca troca o valor sozinho. Validação
  agressiva rejeitaria endereço legítimo e impediria o cadastro, erro pior que
  o que se corrige. Diferença em relação à origem: a biblioteca não aplica
  filtro do WordPress (o hook é do produto) — entrega a lista de domínios e
  recebe de volta a lista já resolvida pelo chamador.
- **`src/Assets/js/email-suggestion.js` — espelho no navegador** (UMD, global
  `V3RCoreEmailSuggestion`, sem DOM e sem jQuery). É a metade com valor real
  da peça: a sugestão aparece enquanto a pessoa digita, não só depois do
  envio do formulário.
- **`src/Assets/data/email-suggestion-cases.json`** — 34 casos exercitados
  pelas duas implementações (PHP e JS), mais o teste que prende
  `dominiosPadrao` (do conjunto) a `defaultDomains()` (do núcleo PHP). É o que impede as duas
  metades de descolarem: uma correção aplicada em só um lado quebra o outro
  no mesmo commit.
- **`Frontend\AssetLocator` — capacidade nova da biblioteca: distribuir ativo
  de front-end** (ADR-014). Quatro decisões: os ativos moram dentro de `src/`
  porque o Strauss só copia para o pacote empacotado o que está sob o
  autoload PSR-4 do pacote (inclusive arquivo não-PHP), e ignora o que está
  fora — verificado executando o Strauss, não suposto; a URL é derivada do
  caminho real do arquivo via `plugins_url()`, com base explícita opcional
  para hospedeiro fora de `wp-content/plugins` (mu-plugin, tema); a versão do
  ativo é a data de modificação do arquivo, não a versão do plugin — a versão
  do plugin identifica a release, não o pacote gerado, e já produziu na casa
  cache servindo arquivo anterior; e nada é enfileirado sozinho — só quando o
  hospedeiro chama `enqueueScript()`, preservando o opt-in da distribuição.
- **Infraestrutura de teste JS**: `package.json` sem dependências (runner
  nativo `node --test`), `tests/js/`, script `composer test:js`, alvo `make
  test-js`, `make check` passa a rodar os dois, e job `test-js` no CI.
- Dois achados corrigidos em relação à implementação de origem, ambos com
  teste: a calibração do limiar pelo comprimento do rótulo **não** separa
  vizinhos que distam uma edição (`uol`/`bol`/`aol`/`sol`) — quem separa é a
  exclusão desses domínios da lista padrão, e são duas guardas distintas que
  o comentário de origem fundia; e a guarda "domínio já exato na lista nunca
  sugere" era redundante com a lista padrão (nenhum par dela dista ≤2) e por
  isso não tinha teste que a prendesse — agora tem, com lista estendida, que
  é quando ela passa a valer.
- Catálogo do componente: `docs/sugestao-de-dominio-de-email.md`. Receita de
  integração ganhou a §7.3 (`docs/integracao-em-plugin.md`).
- Suíte: 250 testes PHPUnit (535 asserções) + 40 testes no espelho JS, todos
  verdes.
- Consumidor: o V3REvent passa a consumir pela biblioteca fixando `^0.9.0`, substituindo a cópia local de `Core\Support\EmailSuggestion` e do espelho JS — migração ainda não feita neste commit.
- Migração retrocompatível — MINOR, não MAJOR: só acréscimo de namespace,
  classes e infraestrutura de teste; nenhuma API existente muda.

## [0.8.0] — 2026-09-03

### Adicionado
- **Namespace novo `V3R\Core\Access\` — segredo de link temporário e
  limitador de tentativas (#24).** Duas peças agnósticas da mecânica de
  acesso por link temporário verificado por e-mail, escolhidas por um
  critério duplo: não tocam identidade nenhuma, e errar tem consequência
  de segurança. `AccessToken` gera 32 bytes aleatórios em base64url,
  mantém o texto puro só como valor efêmero (o que viaja no e-mail/URL) e
  persiste apenas o `sha256`; `fromPlaintext()` recusa string vazia (o
  hash de `""` é válido e não pode virar consulta), e `matches()` compara
  com `hash_equals()` — tempo constante. `AttemptLimiter` limita
  tentativas por duas chaves (identificador/e-mail e origem/IP) sobre
  `Licensing\Storage\KeyValueStoreInterface`, com janela deslizante e
  teto configuráveis (padrões 900s / 3 tentativas) e
  `resetIdentifier()`/`resetOrigin()` para a tela de suporte.
- `AttemptLimiter::registerAttempt()` é o único método de decisão, e
  incrementa as duas chaves incondicionalmente — lê os contadores antes
  de incrementar, nunca no lugar do incremento. Não existe `check()`
  público separado de propósito: com dois métodos, o ponto de chamada
  poderia incrementar dentro do `if`, e o próprio bloqueio viraria
  oráculo de existência de e-mail (por comportamento ou por
  temporização). A leitura dos dois contadores também não tem
  curto-circuito, para o número de leituras/escritas no armazenamento
  ser idêntico numa tentativa permitida e numa recusada.
- Catálogo do componente, incluindo o que fica de fora e por quê:
  `docs/acesso-por-link-temporario.md`. Ver também ADR-013.
- 20 testes novos em `tests/Access/` (suíte completa: 203 testes verdes),
  incluindo o teste que prende o incremento fora da checagem
  (`testTentativaRecusadaTambemIncrementaAOutraChave`).
- Consumidores: RIT360 Solidário migra o que já tem em produção
  (RIT360-Solidario-Code#66); V3REvent nasce consumindo
  (V3REvent-Code#151, que estava bloqueada por esta issue).
- Migração retrocompatível — MINOR, não MAJOR: acréscimo de namespace e
  classes novas, nenhuma API existente muda.

## [0.7.0] — 2026-08-28

### Adicionado
- **`Support\PluginVersion::resolve()` — primeira fase da unificação dos
  pontos de versão dos plugins (v3rtech-scripts#32).** Movida da
  implementação que já funcionava só no V3RLGPD (`Core\PluginVersion`,
  issue #72 daquele repositório) para a biblioteca, para que outros
  plugins da casa deixem de manter cópia hardcoded da versão sem fonte
  comum — a falta de acesso a essa classe, não escolha, era o motivo de
  cada plugin duplicar a lógica. Lê o cabeçalho `Version:` do arquivo
  principal do plugin em tempo de boot; se a leitura falhar, vier vazia
  ou o ambiente se comportar de forma inesperada, cai para o fallback
  hardcoded passado pelo chamador — nunca deixa uma exceção escapar
  (derrubaria o boot do plugin) nem devolve string vazia (quebraria em
  silêncio o cache-busting de assets).
- Migração retrocompatível — MINOR, não MAJOR: acréscimo de classe nova,
  nenhuma API existente muda.

## [0.6.0] — 2026-08-28

### Adicionado
- **A biblioteca repassa ao WordPress o ícone do produto (segunda metade
  de V3RLicense-Code#23).** O payload de `GET /update-check` já trazia
  `icons` (`1x`/`2x`) quando o servidor de licenças tem ícone cadastrado
  para o produto — a biblioteca ignorava o campo, e a tela "Painel →
  Atualizações" continuava mostrando a peça de quebra-cabeça genérica.
  `Updater\UpdateAvailability::fromPayload()` agora lê `icons` e expõe
  `getIcons(): ?array`; `Updater\PucBridge::requestInfo()` popula
  `PluginInfo::$icons` a partir dele, no mesmo ponto em que já populava
  `requires`/`requires_php`/`tested`. O Plugin Update Checker
  (`yahnis-elsts/plugin-update-checker`) já sabia propagar `icons` de
  `Plugin\PluginInfo` até o transiente `update_plugins` via
  `Plugin\Update::toWpFormat()` — não precisou de nenhum filtro próprio,
  diferente do que #8 exigiu para `requires`.
- Payload sem a chave `icons` (produto sem ícone cadastrado) continua
  funcionando exatamente como hoje: `getIcons()` volta `null`, e
  `PluginInfo::$icons` recebe o array vazio que a própria classe já
  declara por padrão. Payload com `icons` em formato inesperado (ex.:
  string em vez de mapa tamanho => URL) também é tratado como ausente —
  nunca derruba a checagem de atualização, que é caminho crítico.
- Migração retrocompatível — MINOR, não MAJOR: V3RLGPD e V3REvent fixam
  `^0.5.0`/`^0.4.0` e continuam funcionando sem alteração.

## [0.5.0] — 2026-08-27

### Adicionado
- **Rótulo do menu e título da tela padrão de licença nomeiam o produto
  (#11).** `Licensing\AdminPage::registerMenu()` registrava a tela com
  texto fixo `"Licença"` — o slug já era por produto
  (`v3r-core-license-<slug>`), o texto não. Num site com dois plugins da
  casa usando a tela padrão, Configurações listava duas entradas idênticas
  distinguíveis só pela URL, e a página aberta também se intitulava só
  "Licença". Agora o texto é "Licença do &lt;produto&gt;" (ex.: "Licença do
  V3REvent"), traduzível com o nome como parâmetro (`sprintf` dentro de
  `__()`, não concatenado fora — preserva a ordem das palavras noutros
  idiomas).
- `Bootstrap::withProductName(string $productName): self` — método fluente
  novo, opcional, repassado a `createAdminPage()`. Sem chamá-lo,
  `AdminPage` cai para o `productSlug` (nunca para o "Licença" genérico) —
  **retrocompatível**: quem não informar o nome não precisa mexer em nada.
  Método fluente e não um 8º parâmetro posicional do construtor, pelo
  mesmo motivo de `withCapabilityDecider()` (docblock do método): PHP 7.4
  sem named arguments, e a lista de parâmetros já está no limite do
  legível.
- Migração retrocompatível — MINOR, não MAJOR: V3RLGPD e V3REvent fixam
  `^0.4.0` e continuam funcionando sem alteração.

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
