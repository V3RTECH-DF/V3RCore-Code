# Histórico de Desenvolvimento — V3RCore

## 2026-09-06 a 07 — Navegação do painel, correção de cascata e papéis orientados a dados: da decisão a duas adoções reais

### Contexto
Ciclo mais longo do projeto até aqui: nasce um segundo repositório
(`V3RTECH-DF/V3RFront-Code`, pacote `@v3rtech/v3r-front`), a camada de
governo da navegação do painel vai de decidida a adotada em produção por
dois plugins, e a promoção de papéis orientados a dados sai do papel. As
três frentes se cruzam porque a navegação, a cascata de CSS e a permissão
são a mesma dor vista de três ângulos — apurada ao portar telas entre
V3REvent, RIT360 Flow e V3RLGPD.

### Implementado

**A biblioteca PHP não passa a distribuir peça de interface (#26, ADR-017).**
Decidido em debate: a v3r-core continua só PHP; a tela viaja por um
repositório próprio, público, que é a **raiz** (o gerenciador de pacotes
do JavaScript não instala subpasta) e versiona separado. Publicado
`@v3rtech/v3r-front` v0.1.0 com `FamilyHeader`, `FamilyNav` e
`AdminNotices` — a união de três implementações divergentes que já
existiam na casa.

**Navegação do painel — camada de governo, `Admin\Nav\` (#35, ADR-018),
v0.14.0 a v0.21.0.** Uma declaração de tela (`Screen`) alimenta a árvore
filtrada e o bloqueio de acesso direto ao mesmo tempo — não há como
declarar uma coisa e esquecer a outra. Motor de permissão plugável
(`ScreenAccess`/`CapabilityAccess`/`CallableScreenAccess`), visibilidade
de grupo sempre derivada dos filhos, ícone de família (duplo V para
V3RTECH, rosa dos ventos para RIT) por `Assets/brand/`.

**Correção da cascata — `@v3rtech/v3r-front/vite`, `cascadeFix()` (#36),
v0.6.0 do pacote.** O Tailwind 4 emite tudo dentro de `@layer`, e origem
sem camada vence origem em camada antes de a especificidade ser
comparada — o CSS do wp-admin não usa camadas e derrotava o do plugin em
seis produtos da casa. A ferramenta desembrulha e reancora o preflight do
hospedeiro **e** reescopa o CSS do pacote compartilhado na mesma raiz —
a segunda responsabilidade só apareceu quando o V3RLGPD adotou e o item
ativo da navegação ficou indistinguível dos inativos. Filtro sempre por
caminho de módulo, nunca por nome de classe ou propriedade.

**UPDATED BY v0.22.0 (07/09/2026): `Admin\Nav\MenuOrder` — posição e
contiguidade da família na coluna do painel (#25), a metade que a
v0.14.0 tinha deixado pendente (só o ícone havia entrado).** Dois blocos
em sequência — RIT primeiro, V3RTECH em seguida —, ordem alfabética
dentro de cada um, sem nenhum plugin de terceiro entre eles.
`Navigation::registerMenu()` anuncia a entrada e pede a posição da
família a `add_menu_page()`; a contiguidade em si só se garante depois,
pelos filtros `custom_menu_order`/`menu_order` do WordPress, quando a
coluna inteira já existe. A decisão que sustenta a convivência entre
cópias: o anúncio mora numa global do PHP **não prefixada** — ao
contrário da capability sintética da v0.21.0, que é por plugin — porque
posição no menu é um fato sobre a coluna do site (um só para todo mundo),
não uma resposta sobre uma pessoa. Reordenação idempotente e
convergente: qualquer número de cópias, em qualquer ordem, calcula o
mesmo resultado. Ver `docs/CHANGELOG.md` [0.22.0] e ADR-018.

**Papéis orientados a dados — `V3R\Core\Roles\` (#39, ADR-016), v0.20.0.**
V3RLGPD e RIT360 Premiado convergiram sozinhos no mesmo desenho de RBAC
editável pelo cliente; promovido o catálogo de permissões, a matriz
guardada, a resolução com cache por requisição e anti-tranca de
administrador, e o `asScreenAccess()` que liga o motor à navegação numa
linha.

### Decisões
Ver `docs/ARCHITECTURE.md` — ADR-016 (Roles, generalização e não recorte:
"a pessoa tem um conjunto de papéis" cobre os dois consumidores sem
mutilar nenhum), ADR-017 (Front como repositório próprio) e ADR-018
(governo e desenho da navegação são independentes por decisão).

### Validado em produção, com duas adoções reais convivendo
RIT360 Flow (biblioteca a partir da v0.17.0, pacote de front v0.2.0) e
V3RLGPD, no mesmo WordPress. Medição com usuário só-Operador e o
administrador como controle: cinco rotas de tela oculta não desenhavam
para o Operador e abriam para o administrador — a coluna de controle é o
que dá valor à medição, porque uma guarda que lesse a árvore em vez do
`accessMap()` teria negado também a ele.

### Quatro defeitos que só a adoção real revelou, nenhum visível em teste
1. **v0.15.0** — papel próprio não alcançava o painel com WooCommerce
   ativo (`view_admin_dashboard` não concedida).
2. **v0.16.0** — tela solta e grupo comparados em escalas de ordenação
   diferentes; nenhuma tela solta conseguia ficar entre dois grupos.
3. **v0.17.0** — "aparecer no menu" e "estar protegida" eram a mesma
   declaração; tirar do menu tirava a proteção (`hidden` + `accessMap()`).
4. **v0.21.0** — capability sintética da navegação era **constante da
   biblioteca**: o Strauss prefixa classe e namespace, não o valor de uma
   string, então em site com dois plugins da casa a guarda de um avaliava
   contra as telas do outro e cancelava a entrada de menu dele. Só
   apareceu com o **segundo** consumidor — nenhuma quantidade de adoção
   isolada revelaria. Cinco hipóteses foram levantadas e refutadas por
   medição antes da causa; a mais cara não foi a mais errada, foi a mais
   bem escrita (guardas distinguidas pelo nome do método, não pela
   classe — ver `docs/CHANGELOG.md` v0.21.0 para o relato completo).

Padrão comum aos quatro, e ao defeito da cascata que apagava o item ativo
do pacote de front: **nenhum falha por ausência.** Todos com a coisa
presente e a verificação respondendo errado sobre ela — o sistema segue
funcionando e mentindo. Critério adotado daqui para frente: toda
verificação nova responde à pergunta "o que ela diz quando a coisa
existe?", não só quando falta.

### Correção de fato registrada nas issues
O GE Associados **já declarava e prefixava** a dependência da v3r-core —
o levantamento original olhou o `composer.json` da raiz do repositório
dele, não o do plugin. Não muda o corte de nenhuma promoção, mas derruba
a suposição de que extrair peças dele exigiria migração prévia.

### Pendente para o próximo ciclo
- Adoção da navegação e da correção de cascata pelos seis plugins que
  faltam — é opt-in, plugin a plugin, nenhum obrigado a migrar de uma vez
  (#35, #36).
- Adoção dos papéis orientados a dados pelo V3RLGPD e pelo RIT360
  Premiado, um de cada vez, sem migração de dado (#39).
- ~~Convenção de posição das entradas no menu (#25): dois blocos
  contíguos por família, reordenados pela biblioteca — decidido, não
  implementado.~~ **Feito na v0.22.0** (`Admin\Nav\MenuOrder`, ver acima).
- Camada de desenho: atalho de busca de tela (Ctrl+K) e botão de menu
  único no celular, fora da v0.1.0 do pacote por decisão.

## 2026-09-05 — v0.12.0, v0.13.0 e a publicação de tag no sync-all

### Contexto
Sessão de continuação do módulo `Signing\` (v0.11.0, dia anterior): duas
correções no código de autenticidade e a promoção do leitor de certificado
do RIT360 Flow, mais um passo novo na cadeia de sincronização do container.

### v0.12.0 — emitir e selar em dois momentos (#28)
`AuthenticityRegistry::issue()` exigia o arquivo final para calcular o
resumo, mas o código de autenticidade é impresso *dentro* do documento —
no instante da emissão, o arquivo final ainda não existe. Quem chamava
era obrigado a selar um artefato intermediário sem o código impresso, e o
resumo gravado nunca batia com o que a pessoa recebia depois:
`verifyFile()` respondia "documento adulterado" sobre documentos íntegros.
`issue( $mode )` passou a só sortear o código e gravar um registro sem
resumo; `seal( $code, $absoluteFilePath )` recebe o arquivo já pronto (com
o código já impresso) e calcula o resumo depois. Terceiro estado em
`AuthenticityVerification` (`isAwaitingSeal()`) para registro emitido e
ainda não selado, que nunca pode cair em `wasTampered() === true`.

**A janela que fechava sozinha, e por que a ordem não era negociável.**
Levantamento com a sessão do RIT360 Flow: todo registro emitido até então
tinha sido selado com o resumo do artefato intermediário — não do PDF
entregue. Depois da correção, esses registros não caem no estado novo
"emitido e não selado" (são registros **selados**, com resumo que nunca
vai bater); para eles `verifyFile()` continuaria acusando adulteração de
documento íntegro, e a recusa deliberada de selar com resumo diferente
(que é o que impede corromper um registro já selado) é exatamente o que
impossibilita corrigi-los depois. A correção só valia enquanto nenhum
documento real tivesse sido emitido — e o Flow ainda não tinha implantado
a assinatura em produção. Registros de teste se apagam; documento real
assinado teria tornado o defeito permanente.

### v0.13.0 — leitor de certificado promovido do RIT360 Flow (#29)
`Signing\CertificateInspector::inspect()` é o único ponto que chama
`openssl_pkcs12_read()` — a mesma abertura confirma que a senha bate e que
o conteúdo é certificado com chave privada — e devolve
`CertificateInspection` (validade + `CertificateSubject`, que alimenta
`SigningModeResolver::decide()` direto). Três decisões conservadoras do
Flow vieram junto, deliberadamente: validade não reconhecida nunca vira
data inventada (`null`, que leva a `SEM_VALIDADE_CONHECIDA`);
`subjectAltName` não é fonte de documento (o PHP não decodifica o
`othername` da ICP-Brasil, e varrer aquele bloco pegaria NIS/RG no lugar
do CPF); e "atestado" significa não autoassinado, não "emitido pela
ICP-Brasil" (restringir exigiria lista de emissores confiáveis, decisão de
produto não tomada). `ext-openssl` entrou só em `suggest`, nunca em
`require` — ausência degrada para validade nula, nunca falha.

**Por que o registro de autenticidade continua mínimo, sem guardar o
titular do certificado.** `CertificateSubject` agora sabe extrair nome,
tipo e documento (CNPJ/CPF) de quem assinou — mas `AuthenticityRecord`
(o que fica gravado por `issue()`/`seal()`) não ganhou um campo para isso,
de propósito. Duas razões: a rota de conferência é pública e sem
autenticação por design (é o próprio ponto do código — ser conferido por
quem recebeu o documento), então qualquer dado do titular exposto ali
vaza para qualquer um que peça o código, não só para quem tem o
documento em mãos; e a distinção pessoa jurídica/pessoa física que a
extração faz falha justamente nos casos mais comuns de titular
individual — MEI e CNPJ com o responsável no nome comum do certificado,
onde o "titular" formal (a pessoa) e o "titular" do documento (a empresa)
não são a mesma coisa e a extração não tem como escolher com segurança
qual dos dois é o que deveria aparecer numa página pública. Guardar
errado numa rota que qualquer um acessa é pior que não guardar.

### #30 — sync-all passa a publicar tag
`git_safe_publish_tag` entrou na biblioteca compartilhada
`v3rtech-scripts/lib/git-safe.sh` — não em `bin/` deste projeto — pelo
mesmo motivo que todo o resto de `git-safe.sh` mora lá: o cabeçalho do
`bin/git-safe.sh` (mero atalho de busca) documenta que até 08/2026 havia
sete cópias idênticas da camada de segurança de git, uma por projeto, e
corrigir uma falha exigia lembrar das outras seis — o que não acontecia
na prática (duas correções, em datas diferentes, ficaram presas ao
projeto onde nasceram). Publicar tag é a mesma categoria de lógica
(nunca força, nunca sobrescreve o alvo de uma tag que o servidor já
publicou) que as outras operações de git-safe já cobrem; separá-la num
script local teria recriado o problema que a unificação resolveu, desta
vez para uma peça nova. `bin/sync-tag.sh` só invoca a função; o passo
entrou no `sync-all.sh` logo depois do `sync-code`, porque uma tag só faz
sentido depois de o commit que ela aponta já estar no servidor.

### Pendente para o próximo ciclo
- RIT360 Flow trocar a implementação local de `CertificateInspector`/
  `CertificateSubject` por fachada sobre esta peça (fora do escopo desta
  entrega).
- RIT360 Flow decidir quando implantar a assinatura em produção, agora
  que a janela do #28 está fechada.

---

## 2026-09-03/04 — Três promoções seguidas: v0.8.0, v0.9.0 e v0.10.0

### Contexto
Três peças que já existiam duplicadas nos produtos subiram para a
biblioteca, cada uma com um consumidor esperando. O fio comum das três
foi o critério de corte: **sobe a regra, não o modelo**.

### v0.8.0 — Acesso por link temporário (#24)
`Access\AccessToken` (32 bytes em base64url, texto puro efêmero, só o
sha256 persistido, comparação por `hash_equals`) e `Access\AttemptLimiter`
(duas chaves, janela deslizante, teto configurável).

Fora de escopo de propósito: serviço de link inteiro, sessão, tabela e
consumo atômico. RIT360 Solidário e V3REvent discordam no modelo de
identidade — lá a sessão carrega uma pessoa, aqui um conjunto —, e
generalizar a partir dessa divergência produziria a abstração errada.

**A decisão que virou estrutura:** o limitador não expõe `check()`
separado. `registerAttempt()` decide e incrementa numa chamada, sempre —
com dois métodos públicos, o ponto de chamada pode incrementar dentro do
`if` e transformar o bloqueio em oráculo de existência de e-mail. Duas
assimetrias que a versão de origem tinha foram fechadas na extração: o
curto-circuito na leitura dos contadores (que fazia uma tentativa
recusada ler uma chave a menos que uma permitida) e a falta de teste
prendendo o incremento.

⚠️ **Critério de aceite 3 da issue não é cumprível dentro desta
fronteira:** uso único sob corrida depende do UPDATE condicional sobre a
tabela, que é do produto. O teste concorrente é de cada consumidor.

### v0.9.0 — Sugestão de domínio de e-mail, e a convenção de ativo de front (#23)
`Support\EmailSuggestion` (núcleo puro, sem hook de produto), o espelho
JS em `src/Assets/js/`, e 34 casos compartilhados em
`src/Assets/data/email-suggestion-cases.json` exercitados pelas duas
metades — é o que impede navegador e servidor de descolarem.

A decisão de arquitetura que veio junto (ADR-014) vale mais que a peça:
**onde mora ativo de front na biblioteca**. Medido executando o Strauss —
ele copia o que está sob o autoload PSR-4, inclusive não-PHP, e ignora o
resto; ativo em `assets/` na raiz não chegaria ao plugin empacotado.

Dois defeitos de racional corrigidos em relação à origem: a calibração do
limiar **não** separa vizinhos que distam uma edição (`uol`/`bol`/`aol`/
`sol`) — quem separa é a exclusão deles da lista padrão, guarda distinta;
e a guarda "domínio já exato nunca sugere" era redundante com a lista
padrão e por isso não tinha teste que a prendesse.

### v0.10.0 — CNPJ e CPF (#22)
`Documents\Cnpj` e `Documents\Cpf`, com CNPJ alfanumérico. Vetores
oficiais da Receita na suíte, mais vetores de borda do módulo 11 com
resto 0 **e resto 1** para cada verificador — lacuna que as quatro cópias
da casa tinham em comum, e sem a qual `resto < 2` e `resto === 0` são
indistinguíveis.

### Premissa que caiu
A #22 previa que as cópias pudessem discordar em borda e normalização, e
que unificar sem olhar faria a mais restritiva vencer em silêncio.
**Medido: 200 mil entradas pelas quatro implementações e pela nova, zero
divergências de validade.** O que divergia era a forma da API — e o
`Cpf::format()` do RIT360 Solidário, que devolvia a entrada crua em vez
do normalizado. A biblioteca adotou o normalizado (decisão do Bruno,
05/09), e é o único ponto em que o Solidário muda ao migrar.

### Descartado
**Enriquecimento de cadastro por CNPJ** (consulta a serviço público para
preencher razão social e endereço), levantado pela sessão do RIT360 Flow:
não existe em nenhum plugin da casa, e o Bruno pesquisou as APIs
disponíveis e concluiu que o esforço não paga o benefício. Não nasce na
biblioteca nem no produto — avaliado e recusado, não esquecido.

---

## 2026-08-27 — v0.3.1, repositório público, segundo plugin integrado (V3REvent)

### Contexto
Validação ao vivo do ciclo completo contra produção
(`V3RLicense-Code#14`, V3RLGPD atualizando 1.66.1 → 1.67.0 de verdade)
expôs dois defeitos na biblioteca; corrigidos antes do rollout continuar,
que então avançou para o segundo plugin da casa.

### Implementado — v0.3.1
- **`requires` sumia do transiente de atualização do WordPress (#8)** —
  não está em `getFieldNames()`/`extraFields` do `plugin-update-checker`
  upstream; morria na cópia `PluginInfo` → `Update`. Reinjetado pelo
  filtro `pre_inject_update` que o próprio PUC expõe, sem fork.
- **Link "Ver detalhes da versão" ficava sem destino (#10)** —
  `PucBridge` nunca preenchia `homepage`; passa a vir do `changelog_url`
  que o servidor já manda.
- Ver `docs/CHANGELOG.md` [0.3.1] para o detalhe completo.

### Repositório tornado público
`V3RCore-Code` passou a ser público (ADR-011). Biblioteca GPL já
embarcada em cada zip distribuído — sigilo não protegia nada que o
cliente não recebesse; público, o CI dos plugins-cliente dispensa
credencial para instalar a dependência, evitando espalhar um PAT pessoal
por sete repositórios.

### Rollout — V3REvent integrado (issue #4)
Segundo plugin da casa, primeiro depois do piloto V3RLGPD. A receita de
`docs/integracao-em-plugin.md` funcionou seguida seção por seção; dois
achados a complementaram:

- **Tela de licença não é opcional na prática** — sem ela, `UpdateGate`
  recusa update no estado `INACTIVE` sem período de graça, e o cliente
  não tem onde ativar. Ver `docs/integracao-em-plugin.md` §7.1 (novo).
- **`user_has_cap` recursivo** — filtro que concede capabilities
  sintéticas de licença precisa sair cedo quando a capability pedida não
  é de licença; sem isso, `user_has_cap → user_can → user_has_cap` é
  infinito e derruba toda requisição de usuário logado por memória
  esgotada (inclusive `wp-login.php`). Já aconteceu de verdade
  (V3RLGPD-Code#74, corrigido). V3REvent nasceu com a guarda e um teste
  que conta chamadas (retorno não distingue "certo" de recursão
  infinita). Sem correção na biblioteca até aqui — registrado em
  `docs/ARCHITECTURE.md`, comentário de 27/08/2026 da issue #4.

No V3REvent, a licença ganhou aba própria em Configurações (não a tela
padrão), motivada pela issue #11 (rótulo "Licença" sem identificar o
produto).

### Pendente para o próximo ciclo
- Rollout: V3RHelp, V3RProp (depende de composerizar — `V3RProp-Code#57`),
  GE Associados, RIT360 Solidário, RIT360 Premiado (#4).
- Considerar mover a guarda de `user_has_cap` para dentro da biblioteca,
  antes do próximo plugin integrar.
- Metadados `requires`/`tested` (#8), CI em branch de feature (#7),
  prefixar dependências compartilhadas (#6), padronizar versão mínima de
  PHP (#5).

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
