# Changelog — V3RCore

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/); versionamento [SemVer](https://semver.org/lang/pt-BR/).

## [0.21.0] — 2026-09-07

### Corrigido
- **`Admin\Nav\NavCapabilityGate` respondia a capability sintética da
  entrada de menu com a MESMA STRING em todas as cópias prefixadas pelo
  Strauss — e quem respondia por último vencia.** O Strauss prefixa
  classes e namespaces na hora de embutir a biblioteca em cada plugin,
  mas **não prefixa o valor de uma constante de texto**: a capability
  sintética da raiz era uma constante da lib, então cada plugin carregava
  a própria cópia da guarda avaliando a mesma string contra as próprias
  telas. Num site com dois plugins da casa nessa camada, a guarda do
  plugin A respondia **sim** (vê as telas de A) e a guarda do plugin B,
  outra classe, outra cópia, mesma string, avaliava contra as telas de
  **B**, não via nenhuma, e escrevia **não** — cancelando a entrada de
  menu de A. Medido num WordPress com oito plugins da casa instalados,
  com RIT360 Flow e V3RLGPD adotando a navegação. Correção: a capability
  sintética da raiz passa a ser **por plugin**, derivada do slug da
  entrada de menu — como a das telas já era.
- **Furo gêmeo, fechado junto: guarda perguntada sobre capability
  sintética que não é dela ficava em silêncio, e é isso que faltava para
  oito plugins da casa conviverem no mesmo painel** sem um cancelar a
  entrada de menu do outro — a guarda nunca nega o que não reconhece,
  só se abstém.
- ⚠️ **`view_admin_dashboard` não entra nesta mudança, e é deliberado:**
  é do ecossistema, não por plugin — a guarda só **acrescenta** o `true`,
  nunca nega, e várias cópias concedendo em paralelo é inofensivo e
  correto.
- **Compatibilidade:** o valor da capability nunca é persistido — é
  sintético, existe só dentro do filtro, e o contrato sempre disse que o
  plugin não deve verificá-la diretamente. Mudar o texto não exige
  migração de dado; ainda assim é mudança observável, daí o minor.
- **O método, e é a parte que ensina:** antes desta causa aparecer,
  quatro hipóteses foram levantadas e refutadas por medição — três da
  biblioteca, uma do consumidor. Uma quinta "causa" chegou a produzir
  três correções antes de se revelar mal fundamentada: a identificação
  das guardas havia sido feita **pelo nome do método**, não pela classe,
  e apresentada como medida. Duas daquelas correções eram defeitos reais
  e ficam de pé (o cache por pessoa e a sentinela publicada antes da
  hora, na 0.20.2); a terceira — guarda única por processo — resolveu um
  problema que naquele site não existia, e continua correta pelo próprio
  mérito. **A lição:** relatório de medição não é a medição. Listar por
  **classe** foi o que distinguiu cópias prefixadas; listar por **nome
  de método** confundiu duas cópias diferentes da mesma biblioteca com
  duas instâncias da mesma cópia.

## [0.20.2] — 2026-09-07

### Corrigido
- **`Admin\Nav\NavCapabilityGate` pendurava um filtro `user_has_cap` por
  `Navigation` construída, não um por processo — a causa raiz do 403 na
  entrada de menu do RIT360 Flow, com todas as telas declaradas abrindo
  normalmente.** Construir a navegação mais de uma vez no mesmo ciclo é
  uso normal da API publicada (o plugin constrói uma no boot e outra ao
  levar a árvore para a tela), e cada construção pendurava o **próprio**
  filtro, na mesma prioridade: duas instâncias respondendo em paralelo, a
  segunda sobrescrevendo o que a primeira tinha concedido — o cálculo
  respondia `true` numa instância e `false` na outra para a mesma pessoa
  e a mesma tela. Correção: um único filtro por processo, agregando as
  declarações de **todas** as instâncias conhecidas; cada tela continua
  respondida pelo respondente do registro que a declarou. ⚠️ Ignorar o
  segundo registro em vez de agregá-lo trocaria a corrida por um buraco
  — as telas declaradas só nele ficariam sem guarda nenhuma, abrindo
  normalmente para qualquer um, sem nada avisar. Exceção deliberada ao
  padrão da biblioteca de evitar estado estático: o filtro do WordPress
  **é** estado de processo, e representá-lo como tal é honesto — com
  saída explícita (`resetForTests()`) para a suíte não ficar
  ordem-dependente.
- **O cálculo publicava `false` antes de terminar de varrer as telas.**
  Enquanto o laço roda, ele chama o respondente de cada tela — o que
  dispara o filtro `user_has_cap` inteiro do WordPress de novo — e uma
  consulta reentrante nesse meio-tempo recebia o `false` provisório como
  se fosse definitivo, mesmo quando a tela visível existia e seria
  encontrada segundos depois: estado interno vazando como resposta.
  Correção: o resultado só é publicado quando a varredura termina;
  consulta reentrante recebe silêncio (nunca um `false` de mentira) e
  nunca recomeça um segundo laço.
- **O cache do resultado agregado não sabia de quem era a resposta.**
  Guardado sem identificar a pessoa, o valor calculado para quem
  perguntava cedo demais — inclusive para o usuário `0`, identidade
  ainda não resolvida — era servido para qualquer outro na mesma
  requisição. Correção: cache por pessoa, preservando a autoinvalidação
  por mudança no conjunto de telas (0.20.1) e a promessa de consultar o
  respondente no máximo uma vez por permissão distinta.

  Os três foram achados investigando o mesmo 403, e o método é o que a
  entrada registra: três hipóteses plausíveis — duas na biblioteca, uma
  no consumidor — foram levantadas e refutadas por medição antes de a
  causa aparecer; cada uma teria virado uma correção que não corrigia,
  com o defeito de pé e aparência de resolvido.

- **`docs/navegacao-do-painel.md` corrigido junto:** o aviso que dizia
  para não construir a navegação duas vezes estava errado desde a causa
  raiz acima e foi substituído — construir mais de uma vez é permitido,
  a guarda é única, e o custo é só releitura, nunca concorrência de
  resposta.

## [0.20.1] — 2026-09-07

### Corrigido
- **`Admin\Nav\NavCapabilityGate` guardava a resposta agregada de "esta
  pessoa enxerga ao menos uma tela?" na primeira consulta e nunca a
  revia.** ⚠️ Se algo perguntasse antes de o plugin terminar de declarar
  as telas — outro plugin, o WooCommerce, qualquer código que rode cedo
  — a resposta era **não**, com o registro ainda vazio, e ficava presa
  no não pelo resto da requisição. Sintoma medido na adoção do RIT360
  Flow: usuário não administrador recebia **403 na entrada de menu do
  produto**, com **todas as telas declaradas abrindo normalmente** —
  elas têm permissão própria e não dependem dessa conta agregada; o
  administrador não via nada, porque passa por outro caminho. ⚠️ O que
  travou o diagnóstico: reproduzir a checagem por linha de comando
  respondia "pode", e a requisição real dava 403 — as duas leituras
  estavam certas, porque o WordPress avalia a permissão da entrada
  **enquanto monta o menu** e guarda o veredito ali; depois disso a
  resposta pode mudar sem desfazer o que já foi decidido. Correção: o
  cache se invalida sozinho quando o conjunto de telas declaradas muda,
  deduzido do próprio registro, sem o consumidor precisar avisar nada —
  a promessa de responder o respondente no máximo uma vez por permissão
  distinta continua valendo enquanto nada muda, e tem teste que a
  protege.
- **A guarda respondia sobre a pessoa errada.** O filtro `user_has_cap`
  do WordPress é disparado para **qualquer** usuário —
  `user_can( $outro, ... )` é uso normal — e a guarda ignorava sobre
  quem era a pergunta, respondendo sempre sobre o usuário corrente. É
  defeito de autorização, não de conveniência: podia conceder ou negar
  errado para terceiros. Não era a causa do 403 acima, e ninguém tinha
  notado — morderia na primeira tela que listasse pessoas com o que
  cada uma pode, com a resposta parecendo certa em toda revisão.
  Correção: pergunta sobre alguém que não é o usuário corrente **não é
  respondida** — a guarda se cala, sem conceder e sem negar. O
  respondente sabe responder só sobre a pessoa corrente, por contrato;
  inventar resposta para outra seria pior que se calar. Limitação
  documentada no contrato (§3 e §4).

Os dois defeitos foram achados investigando o 403 relatado pela adoção
do RIT360 Flow.

## [0.20.0] — 2026-09-07

### Adicionado
- **Namespace novo `V3R\Core\Roles\` — o motor de papéis orientados a
  dados, editáveis pelo cliente.** Promovido de duas implementações que
  convergiram sozinhas: V3RLGPD e RIT360 Premiado escreviam a permissão do
  mesmo jeito, guardavam o papel com a mesma estrutura, montavam o
  catálogo a partir dos módulos do produto e se preocupavam com as mesmas
  armadilhas — e divergiam num eixo só, **um papel por pessoa contra
  vários**, onde um é generalização do outro (`V3RCore-Code#39`).
  ⚠️ **Contraste com a recusa de promoção do acesso por link temporário
  (`#24`):** lá os dois consumidores discordavam exatamente no ponto que a
  abstração teria de fixar (identidade da sessão), e escolher um lado
  mutilaria o outro. Aqui não havia esse ponto de discórdia — só um
  generalizava o outro —, e é essa diferença que decide promover ou não.
  Detalhe completo em `docs/papeis-orientados-a-dados.md`.
- **A forma promovida é "a pessoa tem um conjunto de papéis".**
  `PermissionEngine::rolesOf()` normaliza o formato antigo (um papel só,
  string) para lista, sem o produto converter nada — um produto com um
  papel por pessoa continua funcionando, é só um conjunto de um elemento.
- **Onde se guarda é configuração, não código:** nome da option (matriz de
  papéis) e nome do meta (papel da pessoa) são passados no construtor.
  ⚠️ É isso que faz a adoção **não ter migração de dado** — um produto que
  já usa seus próprios nomes continua usando os mesmos, e a troca é
  reversível.
- **Rótulo e descrição vêm sempre do código**, mesmo em instalação já
  semeada; só as permissões vêm do que está guardado. É o que faz
  renomear um papel-modelo ter efeito sem migração.
- **Módulo novo aparece nos papéis já semeados**, por regra geral
  (`RoleMatrix::reconcileModule()`), não por lista escrita à mão. ⚠️ Sem
  isso, funcionalidade nova nasce invisível para quem já tem papel
  atribuído, e ninguém percebe porque nada erra. Confirmada contra os
  dados reais dos dois produtos: reproduz exatamente as exclusões que
  cada um fazia à mão.
- **Cache por requisição obrigatório, com o bypass de administrador
  resolvido uma vez por pessoa** (não uma vez por permissão). ⚠️ Motivo
  medido: consultar a permissão de dentro do filtro de capability do
  WordPress **reentra no filtro**, e uma chamada por permissão distinta
  vira N reentradas — já causou incidente de esgotamento de memória em
  produção no V3RLGPD.
- **Liga à camada de navegação em uma linha**
  (`PermissionEngine::asScreenAccess()`): o motor entrega a resposta na
  forma que `Admin\Nav\Navigation` aceita desde a 0.18.0. Provado montando
  a navegação real com o motor (`tests/Roles/PermissionEngineNavigationTest.php`).
- **O que NÃO subiu:** a tela de "usuários e papéis" e a validação de
  criação de papel customizado — são da tela, que ainda não subiu. Também
  ficam no produto a lista de módulos, os rótulos, os papéis-modelo e as
  correções de dado específicas de cada produto.

## [0.19.0] — 2026-09-07

### Adicionado
- **`Admin\Nav\LegacyRedirects` — os endereços de submenu antigos continuam
  funcionando depois da adoção.** Apurado na adoção do V3RLGPD
  (`V3RTECH-DF/V3RLGPD-Code#77`): adotar a camada de navegação troca oito
  entradas de menu por uma só, os endereços dos submenus antigos deixam de
  existir, e quem os tem salvos — exatamente quem usa aquela tela todo
  dia — passa a receber **página em branco, sem erro nenhum**. O defeito
  não aparece em teste nenhum, só abrindo um endereço antigo de verdade.
  Sobravam sete plugins para fazer a mesma troca.
- **O mapa é do plugin; a biblioteca cuida do entorno.** `slug antigo =>
  destino` não tem como ser derivado automaticamente — medido no V3RLGPD:
  `v3rlgpd-atendimento` vira `/atendimento`, mas `v3rlgpd-docs` vira
  `/manual` e a entrada raiz não vira nada; qualquer regra automática
  erraria esses dois. `LegacyRedirects` cuida do resto: age em
  `admin_init` (não `admin_menu`), não intercepta requisição que não seja
  do próprio plugin, higieniza o parâmetro recebido e monta o destino com
  `admin_url()` — funciona também com o WordPress em subdiretório.
- **Destino em duas formas, detectáveis pelo próprio valor:** começando
  com `/` ou `#` é rota interna, composta como fragmento sobre o endereço
  da entrada única do plugin e **preservando os demais parâmetros da
  requisição**; qualquer outro valor é URL absoluta, usada exatamente
  como está, sem parâmetro acrescentado.
- ⚠️ **Recusa na declaração o mapa que contenha o slug da própria entrada
  única — não no redirecionamento.** Mapear a entrada para si mesma cria
  laço infinito: não uma tela quebrada, o **painel inteiro travando**. A
  checagem está no construtor, chamado no boot do plugin — falhar ali é
  seguro (`InvalidArgumentException`, visível na hora), bem diferente do
  painel travado que ela evita. Foi este risco — não o mapa em si — que
  decidiu a peça existir: o V3RLGPD escapou por ter pensado nele, e o
  segundo plugin da família não pensaria.
- **Não decide permissão.** Redireciona; quem barra é o destino — a
  guarda de rota do cliente ou a camada 1/2 desta mesma biblioteca, que já
  vale para toda rota, inclusive as que nunca tiveram submenu. Repetir a
  decisão aqui criaria uma segunda fonte de verdade sem fechar buraco
  nenhum. Consequência aceita: quem não pode ver a tela é levado até ela
  e recebe a recusa **dentro do produto**, com mensagem melhor que o erro
  genérico do WordPress.
- ⚠️ **Preservar parâmetros não é a mesma proteção para roteamento no
  servidor e no cliente.** Para quem roteia no servidor, resolve de
  verdade. Para quem roteia no cliente, não cobre o caso equivalente: o
  estado profundo vive depois do `#`, que nunca chega ao servidor — e os
  dois caminhos possíveis perdem (destino com fragmento descarta o que
  vinha no endereço salvo; destino sem fragmento faz o navegador carregar
  o fragmento antigo, apontando para rota morta). É limitação do meio,
  não da peça. Contrato completo em `docs/navegacao-do-painel.md`.

## [0.18.0] — 2026-09-07

### Adicionado
- **O respondente de permissão da navegação pode ser uma função, não só um
  objeto.** Apurado na adoção do V3RLGPD (`V3RTECH-DF/V3RLGPD-Code#77`):
  antes, o plugin hospedeiro tinha de **implementar** a interface
  `Admin\Nav\ScreenAccess`. ⚠️ **Escrever `implements` sobre uma interface
  ainda ausente é fatal error na ativação** — e biblioteca instalada mas
  ainda não prefixada é estado normal logo após um clone (`docs/integracao-em-plugin.md`
  §7). O V3RLGPD teve de declarar a classe condicionalmente — com a
  interface presente, `implements`; sem ela, a mesma classe sem
  `implements`, corpo numa trait para não duplicar. Cada um dos oito
  plugins da família repetiria essa dança, e cada um a escreveria
  diferente — o que já aconteceu com `view_admin_dashboard` (v0.15.0). A
  biblioteca já tinha o padrão certo para este problema:
  `Bootstrap::withCapabilityDecider()` recebe uma função pelo mesmo
  motivo, e o V3RLGPD já a usa no licenciamento — foi a camada de
  navegação que não seguiu o padrão da casa.
- **`Navigation` passa a aceitar função ou objeto** como respondente
  (`Admin\Nav\CallableScreenAccess`). A interface `ScreenAccess` continua
  existindo e documentada, como alternativa para quem preferir classe.
  Quem já passa objeto não muda nada.
- **Ciclo de vida do respondente, agora documentado:** quem o constrói é o
  plugin, e a biblioteca o reusa — o mesmo respondente serve a árvore, o
  mapa e a guarda. Cache guardado dentro dele vale por requisição **se, e
  só se**, o plugin criar um respondente só e uma `Navigation` só. Dois
  `Navigation` no mesmo ciclo releem tudo duas vezes, sem nada quebrar
  visivelmente.
- **`Screen` ganha `surfaces` — quinto parâmetro opcional, no fim do
  construtor** (`Admin\Nav\Screen`). Apurado no mesmo `#77`: o V3RLGPD
  desenha navegação em dois lugares a partir das mesmas telas — o painel
  no wp-admin e uma página do site público do cliente, por shortcode —, e
  a segunda deliberadamente não oferece Configurações, Manual, Onboarding,
  o assistente inicial nem tipos de documento. ⚠️ **Não é permissão: é
  escopo.** Um operador que pode Configurações no painel não deve receber
  "sem acesso" no site da organização — aquelas telas simplesmente não
  existem ali.
- **`Navigation::tree()` e `Navigation::accessMap()` aceitam a superfície**
  como parâmetro opcional. Tela sem `surfaces` declarada existe em todas —
  nada do que já foi escrito muda. Os rótulos de superfície são do
  produto; a biblioteca não sabe o que é "painel" ou "público".
- ⚠️ **Tela fora da superfície é OMITIDA do mapa, não incluída com valor
  negativo — e isso parece contradizer a regra da v0.17.0, mas é ela
  funcionando.** A regra "negada vem com valor negativo, nunca omitida"
  existe para distinguir "existe e você não pode" de "não existe". Tela
  fora da superfície é, ali, o segundo caso: presente com valor negativo
  → "sem acesso"; ausente → "essa tela não existe aqui".
- **A superfície não filtra o portão do servidor:** o endereço é um só e
  continua guardado pela permissão da tela. Superfície decide onde a tela
  aparece e onde o roteador pode abri-la; não desfaz o endereço. Contrato
  completo em `docs/navegacao-do-painel.md`.

## [0.17.0] — 2026-09-06

### Adicionado
- **`Screen` ganha tela oculta, e `Navigation` ganha `accessMap()` — a
  guarda passa a alcançar rota que o servidor nunca vê.** Apurado na
  adoção do RIT360 Flow (`RIT-DF/RIT360-Flow-Code#124`), medindo com
  usuário sem permissão: o contrato tratava "aparecer na navegação" e "ser
  alcançável e guardada" como a mesma declaração. Para plugin que roteia
  no servidor, coincide. **Para plugin que roteia no cliente, não.**
- **O buraco:** o painel do Flow é uma tela só, com roteamento por
  fragmento de URL — tudo vive em `admin.php?page=v3rflow`, e o que vem
  depois do `#` nunca chega ao servidor. Duas consequências, ambas
  medidas: telas em transição precisavam sair do menu, mas continuavam
  existindo como rota — tiradas da declaração, ficaram sem guarda nenhuma,
  entre elas a do certificado de assinatura. E, mesmo declarando, a
  camada 1 da guarda não alcança rota de fragmento: o portão existe num
  endereço que o roteador do consumidor nunca visita. Três telas de
  configuração abriram para quem não podia; duas nem consultaram o
  servidor — simplesmente desenharam. ⚠️ É a diferença entre **protegido**
  e **ainda não vazou**.
- **`Screen` ganha o parâmetro opcional `hidden`, no fim do construtor**
  — declaração existente continua valendo. Tela oculta é declarada,
  guardada e endereçável, mas fora da árvore de navegação: não aparece
  nem solta nem dentro de grupo; grupo que só tenha telas ocultas some,
  pela regra de grupo vazio que já existia; e continua contando para
  "enxerga ao menos uma tela", que governa a entrada raiz do menu e a
  concessão de `view_admin_dashboard`. É o padrão que o GE Associados já
  usava em produção, e que ficou de fora ao escrever o contrato.
- **`Navigation::accessMap()` — o mapa que autoriza.** Devolve, para
  todas as telas declaradas (visíveis e ocultas), se o usuário corrente
  pode abri-la, por identificação da tela. Tela negada aparece com valor
  negativo, **nunca omitida** — omitir a tornaria indistinguível de rota
  inexistente, que é justamente a diferença que o roteador precisa.
  Reaproveita o mesmo respondente e o mesmo cache por requisição, sem
  consulta nova por tela.
- ⚠️ **Árvore e mapa não são a mesma lista:** a árvore omite as ocultas, o
  mapa não. Quem usar a árvore como fonte de autorização deixa exatamente
  as rotas ocultas sem guarda — o defeito que originou esta versão.
- **A regra que passa a valer, mais dura que a anterior:** rota de cliente
  sem tela declarada não tem guarda nenhuma. Quem roteia no cliente
  declara todas as rotas, inclusive as transitórias e as que não aparecem
  no menu — a saída para não poluir o menu é a tela oculta, não deixar de
  declarar. E a conferência do roteador, nesse desenho, não é reforço: é
  a única guarda daquelas rotas. Contrato completo em
  `docs/navegacao-do-painel.md` §2, §4 e §5.

## [0.16.0] — 2026-09-06

### Adicionado
- **`Screen` ganha ordem opcional, e a árvore de navegação passa a ter uma
  regra única de ordenação: `order` ordena entre irmãos, em qualquer
  nível; sem `order` declarada, vale a ordem de declaração.** Apurado na
  adoção do RIT360 Flow (`RIT-DF/RIT360-Flow-Code#121`): o `TreeBuilder`
  ordenava o primeiro nível comparando **duas escalas diferentes** — grupo
  pelo valor declarado em `Group::order()`, tela solta pelo índice de
  inserção, sempre um número pequeno. Com grupos declarando 20, 30 e 50,
  qualquer tela solta caía antes de todos eles, e não existia valor
  declarável que a colocasse entre dois grupos. O Flow precisa de
  `Painel · Pessoas · Organizações · Fluxos · Configurações`, com Painel e
  Fluxos como telas soltas, e obtinha
  `Painel · Fluxos · Pessoas · Organizações · Configurações`.
- ⚠️ **O enquadramento que importa: não era "tela solta não tem ordem", era
  duas escalas sendo comparadas entre si.** O Flow só foi o primeiro a
  misturar os dois tipos de nó no primeiro nível.
- **O que mudou:** `Screen` ganha o parâmetro opcional de ordem, no fim do
  construtor — declaração existente continua funcionando sem alteração.
  No primeiro nível, tela solta e grupo passam a usar a **mesma** chave de
  ordenação, com o mesmo significado. Dentro de um grupo, e em árvore
  plana, a mesma regra vale — sem exceção por nível. Sem nenhuma `order`
  declarada, a árvore sai **idêntica** à de antes.
- ⚠️ **A armadilha que fica, e é deliberado que fique:** misturar irmãos
  com `order` declarada e irmãos sem ela volta a comparar duas escalas —
  valores declarados contra índices de inserção — e o resultado surpreende.
  Entre irmãos, declare `order` para todos ou para nenhum. Não há
  sentinela nem exceção: a camada roda dentro do `admin_menu` do
  WordPress, e exceção ali derruba o painel inteiro do hospedeiro — o
  consumidor descobriria o problema com o site fora do ar por causa de uma
  ordenação. Contrato completo em `docs/navegacao-do-painel.md` §5.

## [0.15.0] — 2026-09-06

### Adicionado
- **`Admin\Nav\NavCapabilityGate` passa a conceder `view_admin_dashboard` a
  quem enxerga ao menos uma tela declarada — a biblioteca passa a conceder
  uma capability de terceiro ao usuário do hospedeiro, e é por isso que o
  número de versão não é `0.14.1`.** Defeito medido na primeira adoção real
  desta camada (RIT360 Flow, `RIT-DF/RIT360-Flow-Code#122`), num WordPress
  com WooCommerce ativo: usuário com papel próprio do plugin, que a árvore
  dizia poder ver certas telas, era redirecionado para fora do painel (302
  para a página de conta) — nas telas **permitidas**; as negadas davam 403
  corretamente. O modo de falha é silencioso: o papel existe, é atribuído
  normalmente, e a pessoa simplesmente não entra.
- **Causa, confirmada lendo o código do WooCommerce**
  (`includes/admin/class-wc-admin.php:175-215`, `prevent_admin_access`): ele
  expulsa do `wp-admin` quem não tiver nenhuma de `edit_posts`,
  `manage_woocommerce` ou `view_admin_dashboard`. Papel próprio de plugin
  costuma ter só `read` mais as capabilities do produto, e cai no bloqueio.
  Atinge qualquer plugin da casa que adote a navegação com papel próprio e
  conviva com loja — ou com plugin de associação e de área do cliente, que
  restringem o painel do mesmo jeito.
- **Por que esta saída, e não as outras duas:** `view_admin_dashboard` é a
  saída que o próprio WooCommerce desenhou — significa "esta pessoa usa o
  painel" e não é verificada pelo núcleo do WordPress em lugar nenhum;
  conceder não dá direito de editar conteúdo nem de mexer na loja. Conceder
  `edit_posts` resolveria o sintoma dando o poder largo que a separação de
  papéis existe para evitar. Filtrar `woocommerce_prevent_admin_access`
  passa por cima de uma decisão deliberada do dono do site, e vira dívida em
  cada plugin de associação que faça o mesmo bloqueio.
- **A divergência que já existia na casa:** três plugins já tinham
  tropeçado nisto, e resolveram de duas maneiras incompatíveis — `V3RLGPD`
  (`src/includes/Core/Capabilities.php:65`) e `RIT360 Premiado`
  (`src/includes/Core/Capabilities.php:45`) concediam `view_admin_dashboard`;
  `V3REvent` (`src/includes/Admin/Admin.php:29`) filtrava
  `woocommerce_prevent_admin_access`. O RIT360 Flow seria o quarto jeito —
  é a divergência que esta camada existe para desfazer.
- **Regras do comportamento:** a concessão nunca nega — a capability é de
  terceiro, então a biblioteca só acrescenta o `true`; valor já concedido
  por outra origem é preservado. Reaproveita a mesma conta
  (`hasAnyVisibleScreen()`) que a entrada raiz do menu já faz, sem varredura
  nova por consulta.
- ⚠️ **Ao adotar, para quem já concede localmente (V3REvent, V3RLGPD,
  Premiado):** a concessão local só pode sair depois de o plugin adotar
  esta camada, e depois de conferir no cenário real — WooCommerce ativo,
  usuário sem `edit_posts` — que ele continua entrando. As coberturas não
  são idênticas: a local costuma valer para todo mundo que tem o papel; a
  da biblioteca vale para quem enxerga ao menos uma tela.

## [0.14.0] — 2026-09-06

### Adicionado
- **`Admin\Nav\` — camada de governo da navegação do painel (#35): entrada
  única no menu do WordPress e navegação inteiramente interna à família.**
  O modo de falha que o desenho fecha: alguém acrescenta tela nova à
  navegação e esquece de proteger o endereço dela. A saída é **uma
  declaração alimentando duas guardas** — o plugin declara cada tela uma
  vez (`Screen`: identificação, rótulo, grupo e permissão), e a mesma
  declaração monta a árvore filtrada (`Navigation::tree()`) **e** bloqueia
  o acesso direto pela URL. Não há como declarar uma coisa e esquecer a
  outra. Contrato completo em `docs/navegacao-do-painel.md`.
- **`ScreenAccess` plugável, com `CapabilityAccess` (sobre
  `current_user_can()`) como padrão.** A biblioteca não trava na
  verificação nativa do WordPress porque V3RLGPD e RIT360 Premiado têm
  matriz de papéis editável pelo cliente — travar rebaixaria os dois ao
  adotar o componente.
- **`NavCapabilityGate` — a capability sintética que faz o gate NATIVO do
  WordPress valer também para motor de permissão próprio.** A tela é
  registrada como página oculta com uma capability inventada
  (`v3r_nav_<slug>`), respondida no filtro `user_has_cap` delegando ao
  `ScreenAccess` do plugin — sem o plugin precisar inventar capabilities
  de mentira, e sem a biblioteca depender de `current_user_can()`.
- **Visibilidade derivada dos filhos, do grupo até a entrada raiz
  (`TreeBuilder`).** Grupo aparece se ao menos uma tela dentro dele
  aparecer; a entrada do produto no menu segue a mesma regra — quem não
  enxerga tela nenhuma não vê a entrada, e não cai numa tela vazia.
- **Declaração acumulativa: `Registry::add()` é chamado por qualquer parte
  do plugin, sem lista central.** É o que o V3RHelp já faz hoje, e sem
  isso perderia a modularidade que é a razão do desenho dele.
- **Navegação plana é caso de primeira classe, não degenerado:** sem
  grupos declarados, a árvore sai plana, sem caso especial em quem
  consome.
- **`Assets/brand/` — ícones de família para o menu do painel (#25):**
  duplo V para a V3RTECH e rosa dos ventos (sem o anel) para a RIT,
  silhueta monocromática em `currentColor`, sem fundo, desenhada para
  20px — marca de produto reduzida a esse tamanho perde o que a
  distingue. A arte é reconstrução, sem vetor oficial versionado na casa,
  e será substituída pela oficial quando existir (ver `README.md` da
  pasta).

### Fora do escopo, por decisão
- **A camada de desenho** (barra, cabeçalho, estilo, fonte) não existe:
  depende da #26 — a biblioteca ainda não distribui peça de interface.
  Quem adotar agora recebe a árvore pronta e desenha a própria barra.
- **Posição na coluna do painel e reordenação** dos blocos da família:
  é a #25, ainda não implementada — só o ícone entrou nesta versão.

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
