# Retomada — V3RCore (biblioteca e pacote de tela da família)

_Escrito ao encerrar a sessão de 13/09/2026 (sessão aberta em 08/09, "GE Associados shared library migration")._

## ⚠️ Primeira coisa a fazer ao abrir

**Avise a sessão do V3RHelp que ela deve falar com você, e não com a sessão anterior (encerrada).** Ela está coordenando a `V3RHelp-Code#88` com o V3RCore.

- Nome na frota: `v3rhelp-34`; endereço `uds:/run/user/1000/cc-socks/1012325.sock` (use `SendMessage` com `to` = esse endereço; se não resolver, `ListAgents` e procure `v3rhelp`).
- Mensagem sugerida: "Aqui é a nova sessão do V3RCore — a anterior foi encerrada. Continue a #88 falando comigo: sigo aguardando o aviso de que o pacote das fatias 1+2+3 está instalado do ZIP no dev-wp para validar o acesso por pessoa antes de publicar."

Canais entre sessões: sessões `local_…` do app → `mcp__ccd_session_mgmt__send_message` (ache o id com `mcp__ccd_session_mgmt__list_sessions`); sessões da frota (endereço `uds:` ou nome) → `SendMessage`. Para responder, copie o `from` da mensagem recebida.

## Onde você está

Container `/mnt/trabalho/Projetos/V3RTECH/V3RCore` (a raiz **não** é repositório). Dois repositórios, ambos públicos:
- `Code/` → `V3RTECH-DF/V3RCore-Code`, biblioteca PHP `v3rtech/v3r-core`, **v0.25.0**. Docs e gestão moram aqui (`docs/`, `dev-history/`).
- `Front/` → `V3RTECH-DF/V3RFront-Code`, pacote de tela `@v3rtech/v3r-front`, **v0.6.0** (consumido por `github:V3RTECH-DF/V3RFront-Code#v0.6.0`, sem token).

**Permitido:** commit, push e publicação sem pedir a cada vez (autorização durável do Bruno, 09/09). Envio por `sync-all.sh` da raiz, nunca `git push` cru. Porta de entrada do que existe: `Code/docs/componentes-da-familia.md` — atualizar na mesma entrega (capacidade nova, versão que muda o que a peça resolve, consumidor novo).

**Seu papel:** o Bruno pediu que a implantação de componente compartilhado nos plugins seja **orquestrada pelo V3RCore**; em divergência, o V3RCore decide. As sessões dos produtos dão alô, você orienta, e **valida por medição** as fatias de permissão antes de publicarem.

## Estado atual

- **Agrupamento do menu da família: concluído nos 9 produtos da linha principal** (`V3RCore-Code#40`, fechada) e medido com até nove anunciantes. Quatro plugins de outra classe (Campos Extras, Descontos e Multas, Google Drive Viewer, EventMaster WP) ficam **fora por decisão do Bruno**.
- **GE Associados migrou por inteiro** (GE 1.80.0, `GEAssociados-Code#183` fechada), validado por mim nas capacidades antes e depois da conversão de permissões.
- **Migração de cabeçalho/menu/avisos/estilo:** feita em V3RLGPD, RIT360 Flow e GE. **Em andamento:** V3RHelp (`#88`). **Issues abertas em 13/09, aguardando alô de cada sessão:** `V3REvent-Code#180`, `V3RProp-Code#67`, `RIT360-Premiado-Code#212`, `RIT360-Solidario-Code#76` (migrar o painel para as peças compartilhadas) e `V3RLicense-Code#64` (só a parte visual).

## Coordenações em voo

| Produto | Onde está | O que espera de você |
| --- | --- | --- |
| V3RHelp (`#88`) | fatia 1 com o implementador | Cadência decidida pelo Bruno: **publicação 1 = fatias 1+2+3, só depois da sua validação de acesso por pessoa**; publicação 2 = fatia 4 (visual). |
| V3REvent (`#180`) | não começou | Camada inteira (roteia por fragmento, entrada única). Tem área de avisos e cascata próprias, e dois bundles (painel + público). |
| RIT360 Premiado (`#212`) | não começou | Camada inteira. Trocar o redirecionamento próprio de seções antigas pelo `LegacyRedirects` — conferir que quem não pode ver continua recusado. |
| V3RProp (`#67`) | não começou | **Decidido pelo Bruno: uma entrada só no menu** (vale para toda a família). Falta a forma técnica: páginas próprias ocultas (como o GE) ou SPA por fragmento. `LegacyRedirects` para os submenus aposentados. |
| RIT360 Solidário (`#76`) | não começou | **Uma entrada só no menu, decidido.** Levantar quais submenus são rotas do app e quais são páginas próprias; `LegacyRedirects` para os aposentados. |
| V3RLicense (`#64`) | não começou | Só visual (não embute a biblioteca em produção). Decidir `FamilyNav` com árvore montada no produto ou manter navegação própria. |

## Decisões pendentes do Bruno

1. **`GEAssociados-Code#189` — Tesoureiro com usuário real**: o dev-wp não tem usuário nesse papel; criar ou alterar conta depende dele (opções: ele cria um usuário de teste; autoriza dar o papel a um usuário de teste existente e devolver; ou pular).
2. **Reinstalar os plugins do dev-wp a partir dos pacotes publicados**: hoje GE, Flow e V3RLicense estão anteriores à adoção, e Premiado, Solidário, V3RHelp e V3RProp com **biblioteca antiga embutida apesar da versão nova** (copiados da árvore de trabalho). Medição de menu ali engana.

## Como validar uma fatia de permissão (o método que funcionou no GE)

1. Pacote **instalado do ZIP** no dev-wp (nunca cópia de árvore); conferir `Version::CURRENT` e a peça dentro do ZIP.
2. Script de medição **somente leitura**, administrador que **não** é a conta pessoal, identidade resolvida **dentro do processo** semeando `$GLOBALS['wp_filter']['determine_current_user']` antes do `wp-load.php` (padrão de `Code/bin/sonda-menu-familia.php`) — **nunca gerar cookie ou sessão** (regra global).
3. Listar `current_user_can` para as capacidades do produto, as calculadas (menu, licença, `v3r_nav_root_<slug>`, `v3r_nav_<tela>`, `view_admin_dashboard`) e a coluna/submenus; **antes × depois** da mudança, com `diff`.
4. **Controle negativo que discrimina** (ex.: capacidade inventada gravada no papel tem de sair negada) — e isolar cada ponta (no GE, regravar a sonda **depois** da conversão provou a ponte, não só a limpeza).
5. Snapshot dos papéis antes e depois da restauração, com `diff`. Registrar o resultado na issue do produto.

## Decisões, com o motivo

- **Todo plugin da família tem uma entrada só no menu do WordPress** (Bruno, 13/09) — menu lateral com várias entradas deixa de existir; navegação interna pela barra da família.

- **Formato do anúncio de menu é contrato público** (`$GLOBALS['v3r_nav_family_menu_entries'][slug] = ['family','title']`, não muda sem MAIOR) — produto que não embute a biblioteca anuncia por escrita direta (0.25.0).
- **`NavCapabilityGate::registerRootMenu()` é uso suportado por chamada direta** (0.24.0) — plugin com página real por tela não pode chamar `registerMenu()` e ficaria sem `view_admin_dashboard` (WooCommerce expulsa papel próprio do painel).
- **`LegacyRedirects` ganhou o caso "slug de página do painel"** preservando parâmetros (0.23.0); o ramo de URL absoluta continua literal de propósito (não vaza parâmetro para fora do site).
- **Limitador de eixo único: origem como identificador** é uso suportado; um prefixo por ponto de limite; identificador vazio vira teto global.
- **Não promover** caminho de conversão de capacidade nativa → matriz, nem marcador de conversão: um consumidor só (GE).

## Premissas que caíram (não repita)

- **Enumerar adotantes por "quem consome a biblioteca"** deixou o V3RLicense (servidor, não cliente) de fora. A pergunta certa é "quem tem entrada no painel de um site da casa".
- **`registerMenu()` e o ramo de parâmetros do `LegacyRedirects` pressupõem roteamento por fragmento** — descoberto pelo consumidor lendo o corpo do método, duas vezes. Página real por tela adota só declaração e guardas.
- **`.v3r-typography` na própria raiz do `cascadeFix` não casa** — tem de ser descendente (contrato do pacote §3, corrigido).
- **A primeira sonda do menu gerava cookie de administrador** — violava a regra global; substituída em 11/09.
- **Busca textual não mede estrutura**: dois diagnósticos errados (CNPJ "sem validação", "quatro telas quebram com F5") e uma varredura de CI com falso positivo nasceram disso. Execute a estrutura.
- **"Publicado" tem quatro posições**: commitado e enviado × tag empurrada × release publicada × rodando nas produções. E o `bump-version.sh` **não cria tag** — publique a tag à parte.
- **Data de modificação não prova sobrescrita** (`rsync -a` preserva); a data de alteração (ctime) sim.
- **Relatório de implementador não é prova**: no GE, um teste declarado como feito não existia na suíte.

## Issues abertas do V3RCore, por prioridade

| # | Descrição curta | Por que nesta posição |
| --- | --- | --- |
| 44 | Versão da biblioteca embutida no pacote não era visível | ⚠️ **Dúvida para fechar**: capacidade entregue na 0.23.0 e conferência no `v3r-release`; falta a adoção no empacotamento dos produtos (`GEAssociados-Code#186`). Pergunte ao Bruno. |
| 43 | Cabeçalho compartilhado transborda a tela em 375px | Afeta quem usa o painel no celular, em todos os adotantes. |
| 42 | Promover montagem de chamada REST (8 cópias, 4 defeituosas) | Defeito latente em 4 produtos, quebra silenciosa com permalink simples. |
| 45 | Matriz de papéis não distingue "nunca semeada" de "esvaziada" | Latente; tela que apagar todos os cargos ressuscitaria os padrões. |
| 14 / 34 / 41 | Padronizar publicação, guard de prefixação divergente, CI que não avisa | Um bloco; a primeira fatia já saiu (`v3r-release`). |
| 38 | Identificador de tela vira endereço global sem proteção de colisão | Latente. |
| 33 | Registro de ativação não aprende a versão nova | Interno. |
| 16 / 31 / 37 | PDF padronizado, vocabulário de recusa do V3RSigner, atributos de bloco | Aguardando segundo consumidor ou priorização. |

O que mais pesou: impacto em quem usa o painel antes de dívida interna.

## Próximo passo

Avisar o `v3rhelp-34` (topo deste arquivo) e aguardar o pacote das fatias 1+2+3 do V3RHelp para validar com o método acima.

## Comandos úteis

```bash
cd /mnt/trabalho/Projetos/V3RTECH/V3RCore && RIT_YES=1 ./sync-all.sh -c   # envia a biblioteca (Code/, com docs e gestão)
cd /mnt/trabalho/Projetos/V3RTECH/V3RCore && RIT_YES=1 ./sync-all.sh -f   # envia o pacote de tela (código e tag)
cd /mnt/trabalho/Projetos/V3RTECH/V3RCore/Code && composer check          # phpunit + phpstan + phpcs + testes JS
cd /mnt/trabalho/Projetos/V3RTECH/V3RCore/Code && bin/bump-version.sh minor  # sobe Version::CURRENT; NÃO cria tag
cd /mnt/trabalho/Projetos/V3RTECH/V3RCore/Code && git tag -a vX.Y.Z -m "..." && cd .. && RIT_YES=1 ./sync-all.sh -t   # publica a tag
docker exec -e V3R_PROBE_LOGIN=<usuario-de-teste> dev-wp php /caminho/sonda-menu-familia.php   # coluna do painel + anunciantes (somente leitura)
```

Token do GitHub: `source <projeto>/bin/config.sh` no mesmo comando do `gh` (para repositórios RIT-DF, use o `config.sh` de um projeto RIT).
