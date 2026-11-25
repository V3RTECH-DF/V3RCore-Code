# Componentes compartilhados da família

> **Leia esta página antes de construir qualquer coisa.** Ela lista o que já
> existe pronto para os produtos da casa consumirem nos ecossistemas da família.
> O que não estiver aqui é que se constrói.
>
> A família possui duas casas irmãs conforme a plataforma do produto:
> - **Plugins WordPress:** container `V3RCore` (`v3rtech/v3r-core` em `V3RCore/Code` + `@v3rtech/v3r-front` em `V3RCore/Front`);
> - **Aplicações Web e Servidor Standalone (infra própria e transição Lovable):** container **`V3RLib`** (`/mnt/trabalho/Projetos/V3RTECH/V3RLib/Code`, repositório `V3RTECH-DF/V3RLib-Code`).
>
> Atualizada em 25/11/2026.
>
> ⚠️ A coluna **quem consome** é a mais informativa desta página: ela revela
> capacidade com um consumidor só — e capacidade com nenhum.

## Por que ela existe

O envio de documento ao serviço de geração foi reimplementado **três vezes**, de
forma independente e com comportamentos divergentes. Foi isso que fez esta
biblioteca existir. A regra que evita a quarta é simples e vem antes do código:
**consultar o que já existe.**

⚠️ Cada capacidade abaixo tem um catálogo próprio, ao lado do código, com o
detalhe de uso. Esta página é o índice — ela diz **o que existe e quem já usa**,
não como se usa.

## Os dois repositórios

| | O que é | Como chega ao plugin |
| --- | --- | --- |
| **`v3rtech/v3r-core`** | biblioteca **PHP** | Composer, embutida pelo Strauss |
| **`@v3rtech/v3r-front`** | pacote de **tela** (React) e ferramenta de build | gerenciador de pacotes do JavaScript, por **tag fixa** |

**A biblioteca governa, o pacote desenha**, e a fronteira entre os dois é
**dado**: o PHP produz a árvore de navegação, o componente consome uma forma
documentada. Os dois versionam separado.

⚠️ O pacote de front **não** viaja pela biblioteca PHP: no empacotamento dos
plugins a tela é compilada **antes** de a biblioteca ser embutida, e cada plugin
precisa embutir a própria cópia para dois produtos nossos não colidirem no mesmo
WordPress.

## O que a biblioteca PHP oferece

| Capacidade | O que resolve | Desde | Quem consome hoje | Detalhe |
| --- | --- | --- | --- | --- |
| **Licença e atualização** | o plugin valida a licença e se atualiza sozinho. **Modo produto gratuito** (`Bootstrap::withFreeProduct()`): produto marcado como gratuito no V3RLicense se atualiza **sem chave, sem tela e sem capability de licença** (o site é registrado pelo servidor); opt-in, sem o modo nada muda | 0.1.0 (modo gratuito: 0.27.0, `#55`) | os 9; modo gratuito: V3RMural (previsto; V3RAnalytics migra depois, hoje com chave pública embutida) | `api-contract.md` §2.4.1, `integracao-em-plugin.md` §7.6 |
| **Documentos** | valida e formata CNPJ e CPF, inclusive o alfanumérico | 0.10.0 | Flow, GE Associados | `documentos-cnpj-cpf.md` |
| **Sugestão de e-mail** | corrige o domínio digitado errado enquanto a pessoa escreve | 0.9.0 | V3REvent | `sugestao-de-dominio-de-email.md` |
| **Ativo de front** | distribui script/estilo da biblioteca para a tela do plugin | 0.9.0 | V3REvent | `sugestao-de-dominio-de-email.md` |
| **Acesso por link temporário** | a pessoa entra por um link enviado ao próprio e-mail | 0.8.0 | V3REvent; GE Associados (só o limitador de tentativas) | `acesso-por-link-temporario.md` |
| **Notificação** | despacho de mensagem multi-canal (hoje, e-mail) | primeiras versões | Flow | — |
| **Assinatura com certificado** | código de autenticidade, leitura do certificado, modo de assinatura | 0.11.0 | Flow | `assinatura-com-certificado.md` |
| **Navegação do painel** | entrada única no menu, navegação interna, permissão e bloqueio de endereço, posição e contiguidade da família na coluna do painel | 0.22.0 | **os 9** — Flow, V3RLGPD, V3RHelp (1.33.0), V3REvent (1.87.0), RIT360 Premiado (2.32.0), V3RProp (1.31.0) e RIT360 Solidário (2.28.0) pela camada inteira; GE Associados só declaração e guardas; V3RLicense por escrita direta, sem embutir a biblioteca | `navegacao-do-painel.md` |
| **Endereços antigos** | o endereço salvo de um submenu que deixou de existir continua funcionando — inclusive preservando parâmetros quando o destino é rota interna ou outra página do painel; e inclusive quando o slug antigo não coincide com nenhuma página registrada, caso em que o WordPress recusava com 403 antes de `admin_init` disparar | 0.19.0 (slug de página do painel: 0.23.0; slug não registrado por ninguém: 0.26.0, `#40`) | V3RLGPD (1.79.1, com 0.26.0), RIT360 Premiado (2.32.0), V3RProp (1.31.0), RIT360 Solidário (2.28.0) | `navegacao-do-painel.md` §7 |
| **Papéis orientados a dados** | papéis que o cliente edita, e quem pode o quê | 0.20.0 | V3RLGPD; GE Associados (motor atrás de marcador de conversão, papel nativo como atribuição) | `papeis-orientados-a-dados.md` |
| **Versão embutida em tempo de execução** | `Version::CURRENT` diz qual versão da biblioteca chegou de fato ao pacote — o guard de prefixação só confere que ela chegou | 0.23.0 (#44) | `v3r-release` (item 20) | `integracao-em-plugin.md` §7.5 |

## O que o pacote de tela oferece

| Peça | O que resolve | Desde | Quem consome hoje |
| --- | --- | --- | --- |
| **Cabeçalho** | logo, título da tela, versão e ações, na régua da família; quebra linha em tela estreita sem apagar o título | 0.1.0 (tela estreita: 0.7.0; título longo sem reticências no celular: 0.7.2) | V3RLGPD, Flow, GE Associados, V3RHelp (1.34.0), V3RProp (1.32.0), V3RLicense (0.35.0), RIT360 Solidário (2.29.0), V3REvent (1.88.0), RIT360 Premiado (2.33.0) |
| **Barra de navegação** | grupos e abas, a partir da árvore que o PHP entrega | 0.1.0 | V3RLGPD, Flow, GE Associados, V3RHelp (1.34.0), V3RProp (1.32.0), V3RLicense (0.35.0), RIT360 Solidário (2.29.0), V3REvent (1.88.0), RIT360 Premiado (2.33.0) |
| **Área de avisos do painel** | põe os avisos do WordPress no lugar certo — abaixo da barra de navegação — com o desenho do wp-admin preservado | 0.1.0 (desenho do aviso: 0.7.0) | V3RLGPD, Flow, GE Associados, V3RHelp (1.34.0), V3RProp (1.32.0), V3RLicense (0.35.0), RIT360 Solidário (2.29.0), V3REvent (1.88.0), RIT360 Premiado (2.33.0) |
| **Guarda de rota** | responde se a pessoa pode abrir uma rota, negando o desconhecido | 0.2.0 | V3RLGPD, Flow, V3RHelp, V3REvent (1.87.0), RIT360 Premiado (2.32.0), V3RProp (1.31.0), RIT360 Solidário (2.28.0) |
| **Correção da cascata** | impede o CSS do painel de derrotar o do plugin, e o do plugin de apagar o componente; devolve checkbox, radio e aviso do wp-admin que o reset ancorado apagava — só dentro do wp-admin, sem impor esse desenho em página pública; e exclui do reset as árvores que o **WordPress** desenha dentro da raiz (editor, botões e modal de mídia), com a lista exportada em `WP_OWNED_TREES` e acrescentável por `cascadeFix('#raiz', { wpOwnedTrees: [...] })` | 0.6.0 (⚠️ use 0.8.0 — árvores do WordPress preservadas, `#51`; a 0.7.3 é o mínimo: a 0.6.0 apaga checkbox, radio e o desenho do aviso; a 0.7.0 encolhe esses controles e o aviso no celular; a 0.7.1 deixa checkbox e radio ovais dentro de linha flex estreita; a 0.7.0–0.7.2 pintam o checkbox com o azul do wp-admin também fora dele) | V3RLGPD (1.80.0) e V3REvent (1.90.1) **já na 0.8.0**; GE Associados, V3RHelp (1.34.0, painel e embutido), V3RProp (1.32.0), V3RLicense (0.35.0), RIT360 Solidário (2.29.0), RIT360 Premiado (2.33.0) |

Detalhe de todas: `Front/docs/contrato-do-pacote.md`.

## Receitas da família

Padrão de construção que não vive em nenhum dos dois pacotes: o produto escreve o
código, mas a forma é da casa, e errá-la custa caro.

| Receita | O que resolve | Desde | Quem segue hoje | Detalhe |
| --- | --- | --- | --- | --- |
| **Painel no frontend** | marcação de dois nós para embutir o painel de gestão numa página pública do site, sem que a raiz isolada apague o teto de largura que o tema concede | receita documentada em 16/09/2026 (`#52`) | V3REvent (1.89.0) e V3RLGPD (1.79.3), os dois com os dois nós | `painel-no-frontend.md` |

## Aplicações Web e Servidor Standalone — V3RLib

Para aplicações web completas, PWAs e serviços de backend fora do WordPress (tanto em infraestrutura própria quanto em transição do Lovable), a casa unifica seus componentes canônicos no monorepo **`V3RLib`** (`/mnt/trabalho/Projetos/V3RTECH/V3RLib/Code`, repositório `V3RTECH-DF/V3RLib-Code`).

A biblioteca foi instituída na issue `V3RTECH-DF/v3r-ia-Code#18` (`feat(arquitetura): varredura multi-projeto e biblioteca canonica de componentes reutilizaveis de aplicacoes`) a partir da varredura comparativa dos produtos maduros da casa (`MiwLab`, `RIT360 Financeiro`, `SocialReport`, `V3RCondo` e `V3RCore`), extraindo implementações provadas para eliminar redundâncias e telas soltas sem navegação comum.

### Pacotes e distribuição

| Pacote / Artefato | O que é | Como chega à aplicação |
| --- | --- | --- |
| **`@v3rtech-df/contratos`** | Schemas universais TypeScript/Zod (entidades, sessões, papéis, auditoria, IA, mensagens e LGPD) | Dependência npm versionada (`package.json`) |
| **`@v3rtech-df/brasil`** | Formatação e validação nacional: CPF, CNPJ comum e alfanumérico, moeda BRL sem salto de cursor, fuso por UF, expurgo de linhas de total e remoção de EXIF/GPS | Dependência npm versionada |
| **`@v3rtech-df/tema`** | Tokens de design (Tailwind CSS v4 + variáveis CSS), paleta oficial, modo claro/escuro/auto e garantia automática de contraste WCAG AA | Dependência npm versionada |
| **`@v3rtech-df/ui`** | Componentes visuais canônicos React (Casca, barra superior sem sininho, modais acessíveis, avisos discretos, tela de login, leitor de manual `/manual`, diálogo de feedback e assistente de IA) | Dependência npm versionada |
| **`@v3rtech-df/servidor`** | Utilitários de backend Node.js / Edge / PostgreSQL (guarda de sessão, isolamento RLS, bootstrap de 1º acesso com 2FA TOTP e conector de feedback direto com GitHub) e a governança: cópias cifradas `.enc` sem chave no servidor com ensaio de restauração, aceite de termos (5 anos) e retenção/expurgo LGPD (as telas correspondentes ficam no `@v3rtech-df/ui`) | Dependência npm versionada |
| **`@v3rtech-df/ia`** | Orquestrador agnóstico de provedores de IA (OpenAI, Anthropic, Gemini, Groq), chaves cifradas, cota da casa vs. BYOK do cliente, teste de latência ao vivo e fallback em cascata | Dependência npm versionada |
| **`@v3rtech-df/mensagens`** | Construtor e disparador multi-canal (E-mail HTML responsivo e Telegram) com identidade dinâmica da organização ativa e fallback para a marca do app | Dependência npm versionada |
| **`.v3rlib-lock.json`** | Sincronizador transitório de integridade para aplicações mantidas temporariamente no Lovable: mapeia e trava cópias somente-leitura com hash SHA-256 | Arquivo na raiz do código (`Code/.v3rlib-lock.json`), validado deterministicamente no rito |

### Os 12 blocos canônicos

| Bloco Canônico | O que resolve e cobre | Origem de referência | Pacotes |
| --- | --- | --- | --- |
| **1. Casca da aplicação e navegação** | Menu lateral retrátil no computador (`>= 1024px`) e gaveta no celular (`375px`), guarda global de rotas autenticadas por papel sem piscar nem entrar em loop, limpeza automática de rascunhos e telas de detalhe na troca de organização ativa, rodapé institucional abrindo Termos e Privacidade em modal sem perda de contexto de tela. | `MiwLab` + `V3RCondo` + `RIT360 Financeiro` | `@v3rtech-df/ui`, `@v3rtech-df/tema`, `@v3rtech-df/contratos` |
| **2. Topo, avatar e alternância de tema** | Barra superior (`BarraSuperior`) com seletor de organização ativa, busca rápida de telas, atalho para o assistente de IA, leitor de manual, diálogo de feedback, área administrativa/superadmin, menu do avatar (perfil, 2FA, tema claro/escuro/auto e logout), sem sininho de histórico de notificações (conforme padrão oficial da casa). | `MiwLab` + `RIT360 Financeiro` | `@v3rtech-df/ui`, `@v3rtech-df/tema` |
| **3. Tela de login** | Layout institucional em duas colunas, botão Google OAuth condicional, mensagens orientativas de sessão expirada por inatividade ou outro aparelho, preservação do e-mail digitado no celular após recarga, etapa de 2FA TOTP e validação segura no servidor. | `RIT360 Financeiro` (base) + `V3RCondo` + `MiwLab` | `@v3rtech-df/ui`, `@v3rtech-df/servidor`, `@v3rtech-df/contratos` |
| **4. Primeiro acesso e segurança inicial** | Inauguração segura sem variáveis de ambiente ou por credencial provisória de ambiente (`inicializado = false`), bloqueio imediato com troca obrigatória de credencial provisória e pareamento compulsório de 2FA TOTP com chaves de contingência para superadmin (opcional/configurável para usuários comuns). Proíbe senhas fixas em seeds. | `MiwLab` + `V3RCondo` / `Katatu` | `@v3rtech-df/ui`, `@v3rtech-df/servidor`, `@v3rtech-df/contratos` |
| **5. Feedback direto para GitHub Issues** | Modal global de feedback acionável de qualquer tela com captura automática de rota, aparelho, versão e anexos, além de autorização expressa para depoimentos. O conector no servidor abre diretamente a issue no repositório GitHub (`owner/repo`), mantendo fila invisível temporária no banco caso o GitHub oscile (com expurgo imediato pós-sucesso), guarda jurídica de consentimento LGPD para depoimentos e fallback por e-mail para suporte quando fora da nossa infraestrutura (sem fila interna de triagem nem tela administrativa). | `RIT360 Financeiro` + `V3RCondo` + `MiwLab` (diretiva unificada de 06/11/2026) | `@v3rtech-df/ui`, `@v3rtech-df/servidor` |
| **6. Manual do usuário com fonte única** | Estrutura editorial escrita uma única vez na pasta `Manual/` (Markdown com `_config.yml`) que alimenta tanto a publicação estática no GitHub Pages quanto o leitor navegável integrado na rota `/manual` dentro da casca (sem duplicar `.md` dentro de `Code/`), suporte a zoom em imagens (`zoom-imagem.js/css`), busca textual instantânea e vídeos curtos de apoio. | `SocialReport` / `RIT360 Financeiro` + `MiwLab` + `V3RCondo` | `@v3rtech-df/ui` |
| **7. Provedores de IA com fallback** | Cadastro de chaves de API cifradas sem exibição em tela, separação entre franquia da casa e BYOK do cliente, teste de latência ao vivo, ordenação por prioridade com queda automática em cascata caso o provedor principal falhe ou esgote cota, e filtro de bloqueio de dados pessoais sensíveis antes do envio. | `MiwLab` + `RIT360 Financeiro` + `V3RCondo` | `@v3rtech-df/ia`, `@v3rtech-df/ui`, `@v3rtech-df/servidor` |
| **8. Acesso Google OAuth 2.0** | Painel administrativo para cadastro e cifragem de credenciais, cópia em um clique dos URIs de redirecionamento autorizados, botão de teste de conexão sem sair da sessão, e trava de segurança que impede vincular ou aceitar convite com conta Google cujo e-mail divirja do convite. | `MiwLab` + `SocialReport` + `RIT360 Financeiro` | `@v3rtech-df/ui`, `@v3rtech-df/servidor` |
| **9. Mensagens com identidade visual** | Construtor centralizado de e-mails HTML responsivos e mensagens Telegram com aplicação dinâmica de logotipo, nome e cores da organização ativa (com checagem de contraste WCAG AA e fallback automático para a marca da plataforma), pré-visualização ao vivo, modo de ensaio para auditoria antes de disparos em lote e proteção contra links falsos. | `MiwLab` / `SocialReport` + `RIT360 Financeiro` / `V3RCondo` | `@v3rtech-df/mensagens`, `@v3rtech-df/servidor` |
| **10. Governança, cópias cifradas e LGPD** | Rotina de backup com cifragem ponta a ponta sem chaves no servidor (`.enc`), ensaio de restauração em banco temporário isolado antes da validação, retenção programada de dados com modo simulação e aviso prévio, e gestão de termos de uso com bloqueio de tela até novo aceite e registro auditável por 5 anos. | `MiwLab` + `SocialReport` + `V3RCondo` / `RIT360 Financeiro` | `@v3rtech-df/servidor`, `@v3rtech-df/ui` |
| **11. Avisos discretos e confirmações** | Popups flutuantes discretos no canto inferior direito para notificações rápidas ("Salvo", "Copiado"), que pausam ao passar o mouse e não bloqueiam o conteúdo da tela; modais de confirmação acessíveis para ações destrutivas ou irreversíveis com foco seguro inicial no botão cancelar; e textos explicativos visíveis em botões desabilitados. | `SocialReport` + `RIT360 Financeiro` / `V3RCondo` + `MiwLab` | `@v3rtech-df/ui`, `@v3rtech-df/tema` |
| **12. Assistente virtual com IA** | Painel de atendimento acessível pela barra superior que responde dúvidas operacionais estritamente com base no manual oficial (indicando com clareza quando a informação não consta no manual, sem alucinar), consulta dados respeitando o contexto de permissão do usuário logado, zera o contexto na troca de organização e opera em modo seguro com proposta de preenchimento para confirmação humana. | `RIT360 Financeiro` + `V3RCondo` | `@v3rtech-df/ui`, `@v3rtech-df/ia`, `@v3rtech-df/servidor` |

### Os 7 blocos extras

| Bloco Extra | O que resolve e cobre | Origem de referência | Pacotes |
| --- | --- | --- | --- |
| **1. Multi-tenancy e RLS** | Isolamento multi-organização estrito no banco de dados e APIs via Row-Level Security por tenant, confirmação de vínculo no aceite de convite e limpeza reativa completa de estado de tela ao alternar a organização ativa. | `MiwLab` + `RIT360 Financeiro` + `V3RCondo` | `@v3rtech-df/servidor`, `@v3rtech-df/contratos` |
| **2. Pacote Brasil e limpeza EXIF** | Validação e formatação de CPF e CNPJ (incluindo o formato alfanumérico), moeda brasileira BRL sem saltos de cursor na digitação, manipulação de datas imunes a fusos horários, cálculo automático de fuso por UF, expurgo automático de linhas de totais em planilhas e remoção estrita de metadados GPS/EXIF de fotos enviadas antes da persistência. | `V3RCore` + `RIT360 Financeiro` + `V3RCondo` + `MiwLab` | `@v3rtech-df/brasil` |
| **3. Vocabulário adaptável e contraste WCAG** | Adaptação terminológica dinâmica às preferências e convenções de cada cliente com concordância gramatical em português, além de cálculo dinâmico de contraste de luminância (WCAG 2.1 AA, `>= 4.5:1`) para logomarcas, botões e barras sobre fundos claros e escuros. | `MiwLab` | `@v3rtech-df/tema`, `@v3rtech-df/ui` |
| **4. PWA e Push Notifications VAPID** | Suporte completo a instalação móvel/desktop (Service Worker, manifesto, ícones e splash), indicador discreto de nova versão disponível com recarga suave e disparo de notificações push nativas via protocolo VAPID direto da aplicação, sem dependência de serviços externos proprietários (Firebase). | `V3RCondo` + `RIT360 Financeiro` | `@v3rtech-df/ui`, `@v3rtech-df/servidor` |
| **5. Verificação pública de documentos** | Emissão de comprovantes, certidões e relatórios com QR Code e código alfanumérico de validação pública, com rota pública de conferência de autenticidade acessível sem necessidade de login. | `V3RCondo` + `V3RCore` | `@v3rtech-df/ui`, `@v3rtech-df/servidor` |
| **6. Novidades da versão e tour guiado** | Diálogo ilustrado de apresentação das novidades da versão (alimentado automaticamente pela seção correspondente do manual) e passeio guiado passo a passo pelas principais funcionalidades, acionável a qualquer momento pelo menu de perfil. | `RIT360 Financeiro` | `@v3rtech-df/ui` |
| **7. Guarda de edição e anti-SSRF** | Interceptação e diálogo de confirmação para prevenir perda de alterações em formulários ao navegar ou fechar a aba, e proteção robusta no backend contra ataques SSRF na validação e busca de URLs ou webhooks externos. | `SocialReport` + `MiwLab` | `@v3rtech-df/ui`, `@v3rtech-df/servidor` |

### Peças de tela prontas no `@v3rtech-df/ui`

Antes de desenhar uma tabela, um cartão de indicador, um botão ou um campo de formulário num app web, use estas peças. Já vêm com o design system da casa (contraste nos dois modos, alvo de 44 px, foco visível, textos em português). O uso está no `docs/consumo.md` da V3RLib, na seção indicada.

| Peça | O que resolve | Onde ler |
| --- | --- | --- |
| **`TabelaDados`** | Lista em tabela com ordenação pedida ao servidor, paginação por cursor, seleção por linha e em lote (com a `BarraLote`), estados de carregando, vazio e erro, e cartões no celular. | `consumo.md` §3.3 e §29 |
| **`CartaoIndicador`** | Número de painel com comparação com a referência quando o negócio permite, e frase no lugar do número quando ainda não há dado (DS-CASA-13 e 14). | `consumo.md` §3.3 |
| **`Botao`** | Botão geral da casa, com variantes (primário, secundário, perigo, só ícone), hover com contraste garantido e alvo de 44 px. | `consumo.md` §3.5 |
| **Campos do kit** (`Campo`, `CampoTexto`, `CampoSelecao`, `CampoAreaTexto`, `CampoMarcar`, `CampoCor`, `CampoArquivo`, `CampoImagem`, `SeletorVarios`) | Rótulo visível acima, ajuda e erro ligados ao campo, mensagens em português sem a validação nativa do navegador; cor com normalização de hexadecimal; arquivo e imagem com botão e textos da casa; seleção múltipla com busca, etiquetas e árvore. | `consumo.md` §3.4 (seleção múltipla: issue #110) |

### Especificação técnica e arquitetura

A especificação técnica detalhada de cada módulo, contratos TypeScript/Zod, estratégia de monorepo e o plano de entrega em 3 ondas estão documentados em:
- `/mnt/trabalho/Projetos/V3RTECH/V3RLib/Code/docs/ARCHITECTURE.md` — Arquitetura de pacotes, dependências, matriz de reuso e modelo de governança.
- `/mnt/trabalho/Projetos/V3RTECH/V3RLib/Code/docs/PRD.md` — Requisitos de produto, critérios de aceite dos blocos canônicos e ondas de entrega.

## ⚠️ O que este índice revela, e vale saber antes de decidir

**A maior parte tem um consumidor só.** Foi assim que a assinatura, os
documentos e o link temporário nasceram: um produto precisou, a peça subiu, e
ninguém mais foi atrás.

**E cinco dos nove plugins ainda apontam para a `0.7.0`**, de agosto. Estão em
produção e funcionando — só não alcançam nada do que veio depois. Quase tudo
listado aqui está disponível para plugins que não sabem que existe.

⚠️ **Adoção validada sozinha não prova convivência.** Cinco defeitos desta
camada só apareceram quando o **segundo** plugin adotou — e um deles fazia um
produto **cancelar a entrada de menu do outro**, coisa que nenhuma quantidade
de teste em um plugin só revelaria. Quem adotar deve medir num site com mais de
um plugin da casa instalado.

## Como consumir

A distribuição é **opt-in**: o plugin declara o que precisa, e quem não usa não
carrega peso. A receita de integração — declaração da dependência, prefixação,
empacotamento e verificação — está em `integracao-em-plugin.md`.

⚠️ **Fixe sempre uma versão.** "A principal andou" não é "a versão saiu": o
consumidor alcança o que está publicado em tag, e é isso que faz o build de cada
máquina produzir a mesma coisa.

## Quando construir em vez de consumir

Quando a capacidade **não está nesta página**. E, nesse caso, a pergunta
seguinte é se ela deveria estar: peça que sirva a mais de um produto nasce aqui,
não no plugin.

⚠️ O critério de corte não é "parece genérico". As promoções que deram certo
subiram o que **não toca a identidade de nenhum produto** e cujo erro tem
consequência séria. A que foi recusada — o serviço de link temporário inteiro —
foi recusada porque os dois consumidores discordavam exatamente no ponto que a
abstração teria de fixar. Ver `acesso-por-link-temporario.md` §2 e o ADR-016.
