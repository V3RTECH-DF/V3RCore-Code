# Retomada — V3RCore (orquestração da migração dos plugins para as peças compartilhadas)

_Escrito em 13/09/2026, ao passar a orquestração para uma sessão nova (a anterior chamava-se "V3RCore Orquestrador Migração 2" e pode ainda estar aberta, repassando resultados de agentes em voo)._

## ⚠️ Primeira coisa a fazer ao abrir

1. **Apresente-se a cada sessão de produto** com `ListAgents` + `SendMessage` (uma mensagem por sessão): "Aqui é a nova sessão do V3RCore que orquestra a migração; a anterior foi encerrada. Continue falando comigo; me diga em que ponto você está e o que espera de mim." Sessões ativas quando este arquivo foi escrito: `v3rhelp-34`, `v3revent-5a`, `premiado-1e`, `solidario-b1`, `v3rprop-28`, `ge-associados-48`. A do V3RLicense foi fechada (ver pendências do Bruno).
2. **Pergunte à sessão anterior** (se ainda aparecer no `ListAgents`) pelo resultado de dois agentes que ela deixou rodando: a correção **v0.7.2 do pacote de tela** e a **foto do "antes" visual do Solidário 2.28.0**. Se ela já tiver saído, confira no `Front/` se há commit local da v0.7.2 (ver abaixo) e refaça a foto do Solidário.

## Papel desta sessão

O Bruno pediu que a migração dos plugins para as peças compartilhadas seja **orquestrada pelo V3RCore**, em **modo autônomo até concluir**. As sessões dos produtos implementam; esta sessão orienta, decide divergências e **valida por medição antes de cada publicação**. Commit, push e publicação na biblioteca e no pacote são autorizados sem pedir a cada vez.

## Versões publicadas nesta rodada

- `v3rtech/v3r-core` **0.26.0**: o `LegacyRedirects` passou a redirecionar endereço antigo que não está registrado como página. Antes dava 403, porque o WordPress recusa dentro do `menu.php`, antes do `admin_init`.
- `@v3rtech/v3r-front` **v0.7.0**: checkbox, radio e `.notice` do wp-admin restaurados dentro da raiz do `cascadeFix`; cabeçalho quebra linha em tela estreita.
- `@v3rtech/v3r-front` **v0.7.1**: tamanho de celular do checkbox, do radio e do padding do aviso (a 0.7.0 fixava o de desktop).
- **v0.7.2 em andamento** (agente da sessão anterior, commit local no `Front/`, sem tag): `flex-shrink: 0` nos controles nativos (checkbox oval em linha flex, medido no V3RHelp e no V3REvent) e título do `FamilyHeader` quebrando linha em vez de truncar em 375 (medido no Premiado, "Config…"). **Ao receber:** rodar `npm test`/`typecheck`/`build` no `Front/`, conferir a prova no navegador descrita no relatório, `git tag -a v0.7.2`, `./sync-all.sh -f`, atualizar `Code/docs/componentes-da-familia.md` e avisar V3REvent, Premiado, V3RProp e Solidário para subir para `#v0.7.2`.

## Estado por produto

| Produto | Publicação 1 (camada + guarda) | Publicação 2 (visual) | Próximo passo |
|---|---|---|---|
| V3RHelp | 1.33.0 ✅ | 1.34.0 + 1.34.1 (checkbox no celular) ✅ | Issue #88 fechada. Falta a confirmação de que a recaptura do manual terminou e deixou os usuários 98/99 intactos. |
| GE Associados | (já migrado) | 1.81.2 com v0.7.1 ✅ | Concluído (GEAssociados-Code#201 fechada). |
| V3REvent | 1.87.0 ✅ | fatia 4 instalada no dev-wp, **bloqueada** | Check-in em branco: pedaços sob demanda importavam `./admin.js`/`./front.js`, e a query `?ver=` gerava uma segunda cópia do React. A correção (entrada fina com `import()` do app) está com o implementador. Rótulos da barra: "API" volta a "Shortcodes e API"; "Painel"/"Dashboard" seguem a opção (c), cada superfície com o rótulo de hoje, e há issue para o Bruno unificar. Depois: subir para v0.7.2, remedir (viewport criada já em 375, `matchMedia` registrado, padding do aviso) e liberar. |
| RIT360 Premiado | 2.32.0 ✅ | fatia 4 instalada, ajustes pedidos | O aviso ainda fica entre cabeçalho e barra (tem de ficar abaixo); botões e alguns inputs não estão em Exo 2 (apurar a regra vencedora); subir para v0.7.2 (título truncado em 375). Página pública idêntica ✅. |
| V3RProp | 1.31.0 ✅ | 1.32.0 instalada | Botão e um input fora do wrapper saem em fonte de sistema: a sessão apura por CDP (suspeita: diálogos do núcleo do WordPress impressos fora do app). Com a causa, decidir entre produto e pacote e liberar (preferência: subir para v0.7.2 antes de publicar). |
| RIT360 Solidário | 2.28.0 ✅ | planejando | Foto do "antes" visual (em curso ou a refazer). Plano da fatia 4 com a proposta de build (preferência: um `vite.config` por raiz; a página pública tem de sair byte a byte igual). **Cor de destaque é decisão do Bruno** (teal #24ae9c do documento × laranja #F49E27 do app); variável num ponto único. |
| V3RLicense | — (só visual) | 0.35.0 construída com v0.7.0, instalada no dev-wp | **Sessão fechada.** Reabrir, subir para v0.7.2 (ou 0.7.1), remedir (o "antes" está registrado) e liberar. Dados de teste no dev-wp: produto 81, licença 156, cliente 64 e `zz-temp-teste-64-aviso.php`. |
| V3RLGPD | já migrado (1.79.0) | — | **Republicar** com v3r-core 0.26.0 (5 dos 7 endereços antigos dão 403 hoje, V3RLGPD-Code#119) e v3r-front v0.7.1+. Avisos acima da barra (V3RLGPD-Code#118). |
| RIT360 Flow | já migrado (0.51.0) | — | **Republicar** com v3r-front v0.7.1+. O código não está nesta máquina (repositório `RIT-DF/RIT360-Flow-Code`). |

## Pendências do Bruno

1. **Quem republica o V3RLGPD e o Flow**: ele abre uma sessão para cada um (recomendado) ou esta sessão faz direto.
2. **Reabrir a sessão do V3RLicense.**
3. **Cor do Solidário** (a sessão do produto leva a ele).

## Regras da família decididas nesta rodada (valem para toda adoção)

- **Slug de tela começa com o slug do produto.** As capacidades `v3r_nav_<slug>` e as páginas ocultas são globais, e o V3RHelp 1.33.0 sem prefixo respondia `v3r_nav_dashboard` por outro plugin (V3RCore-Code#38). Onde o slug coincidiria com endereço antigo do `LegacyRedirects`, use o infixo `-tela-`.
- **Tela sem capacidade própria resolve para "é da equipe do produto"** (papel do produto ou `manage_options`), nunca para "está logado". Senão todo cliente do WooCommerce ganha o wp-admin.
- **Cada tela usa a regra de visão que o produto já declara** (aba e endereço antigo). O fragmento sem guarda é atalho, não regra.
- **`access === null` só quando a biblioteca não chegou**; chave ausente no boot nega.
- **Endereço antigo:** redireciona todo mundo, e a recusa acontece dentro do produto (opção A, contrato §7). A mudança de 403 para 302 com recusa é intencional.
- **Área de avisos abaixo da barra de navegação** (contrato do pacote §8).
- **Um `vite.config` por raiz com `cascadeFix`** (V3RCore-Code#47 para build com várias entradas).
- **`--v3r-accent` com a cor do produto, com valor.** Migração não renomeia telas nem muda rótulos.
- **Unificação não reduz:** desenho de marca do produto (GE) sobrepõe o nativo do pacote com especificidade maior, sem `!important`.

## Como validar (instrumentos desta rodada)

- **Acesso por pessoa:** `Code/bin/medir-acesso.sh <produto> <pasta>`, com configuração e adaptador em `Code/bin/sondas/`. É somente leitura: identidade semeada antes do WordPress carregar, reproduz a ordem do `admin.php`. Mede capacidades, expulsão do WooCommerce, RBAC do produto, mapa e árvore da camada, cada `permission_callback` REST e o acesso direto a cada endereço. **Meça um processo por perfil.** Fotos do "antes" e do "depois" desta rodada: `/tmp/claude-1000/-mnt-trabalho-Projetos-V3RTECH-V3RCore/66b72209-7ad6-4152-bbad-029cd4aac9dd/scratchpad/` (`antes-familia/`, `depois-familia/`, `*-antes/`, `*-depois/`), e matrizes nos comentários das issues de cada produto.
- **Visual:** agente `e2e-runner` no dev-wp como `dev-claude` (credencial `LOCALHOST_USER/LOCALHOST_PASS` no cofre, login por script). Pedir **viewport criada já na largura** e `matchMedia` registrado. Aviso de teste por mu-plugin temporário de cada produto.
- **Sequência de cada publicação:** instalar o ZIP publicado → foto do "antes" → instalar o ZIP novo (nunca cópia de árvore) → medir → liberar → o produto publica e apaga os dados de teste.
- **Usuários de teste compartilhados do dev-wp:** `dev-claude` (97, administrador), `claude-operador` (98, só `v3rflow_operador`), `claude-assinante` (99, só `subscriber`). Nenhuma sessão pode dar papel a eles.

## Premissas que caíram nesta rodada (não repita)

- **Sonda em CLI sem `$plugin_page` e com `PHP_SELF` do script** responde SIM ou recusa erradas no acesso direto. Gerou uma correção desnecessária no V3RHelp, já registrada.
- **`LegacyRedirects` "medido ao vivo" no V3RLGPD** funcionava só para os slugs que coincidiam com páginas registradas.
- **`./sync-all.sh -c` commita a árvore inteira do `Code/`**: não rode com agente editando lá (memória `sync-code-captura-arvore`).
- **Relato de agente visual** já leu "18px no corpo" que era título de card, e padding de desktop em 375. Confira como a medida foi obtida antes de mandar corrigir.

## Issues abertas relevantes

- V3RCore-Code#46 (cascadeFix apagava checkbox, radio e aviso; aguardando validação nos produtos, fechar quando V3REvent, Premiado e V3RProp publicarem a visual)
- V3RCore-Code#43 (cabeçalho em 375; idem)
- V3RCore-Code#47 (cascadeFix para várias entradas)
- V3RCore-Code#48 (fonte embarcada em base64)
- V3RCore-Code#38 (proteção de colisão de slug na biblioteca, depois da migração)
- V3RLGPD-Code#118 (avisos abaixo da barra) e V3RLGPD-Code#119 (endereços antigos 403)
