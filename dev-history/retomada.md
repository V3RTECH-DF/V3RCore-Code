# Retomada — V3RCore

_Escrito ao encerrar a sessão de 05/09/2026 (`v3rcore-f3`)._

## Onde você está

**V3RCore** é a biblioteca compartilhada dos plugins da casa (`v3rtech/v3r-core`) —
não é plugin: é pacote Composer embutido em cada plugin por Strauss. Nasceu para
licenciamento e auto-atualização, e virou o lugar onde mora todo componente que
serve a mais de um produto.

- **Código e gestão:** `/mnt/trabalho/Projetos/V3RTECH/V3RCore/Code` — repositório
  único (`V3RTECH-DF/V3RCore-Code`, **público**). A raiz do container não é
  repositório; `CLAUDE.md` e `sync-all.sh` lá são symlinks para dentro de `Code/`.
- **Issues são a lista viva de trabalho.** Levantamento e evidência vão para a
  issue, não para arquivo solto.
- **Permitido:** commitar e tagar direto na `main` (é o que o repositório pratica —
  merge `--no-ff` de branch de feature). **Push é do Bruno**, salvo quando ele
  autoriza a publicação da tag, como fez nesta sessão.
- **A biblioteca não se auto-prefixa.** Quem prefixa é o plugin hospedeiro, numa
  passada só do Strauss. Ver `docs/integracao-em-plugin.md`.

## Estado atual

**v0.11.0** publicada (tag no remoto; `main` sincronizada). A v0.11.0 é de **outra
sessão** (`v3rcore-96`, assinatura de documentos, issue #27) — ver o aviso no fim.

Entregue e validado por esta sessão, cada um com catálogo em `docs/`:

| Tag | O que entrou | Catálogo |
|---|---|---|
| v0.8.0 | `Access\AccessToken` e `Access\AttemptLimiter` (#24) | `docs/acesso-por-link-temporario.md` |
| v0.9.0 | `Support\EmailSuggestion` + espelho JS + `Frontend\AssetLocator` (#23) | `docs/sugestao-de-dominio-de-email.md` |
| v0.10.0 | `Documents\Cnpj` e `Documents\Cpf` (#22) | `docs/documentos-cnpj-cpf.md` |

Suíte no fim da sessão: **304 testes PHPUnit (646 asserções) + 40 no espelho JS**,
verdes; phpcs e phpstan (nível 8) limpos.

**Pela metade:** nada nesta sessão. **Pronto e não validado:** nada — as três
entregas têm teste e guardas verificadas por remoção.

## O que a última sessão fez

Promoveu três peças que já existiam duplicadas nos produtos, cada uma com
consumidor esperando: acesso por link temporário (V3REvent estava bloqueado),
sugestão de domínio de e-mail, e validação de CNPJ/CPF (o RIT360 Flow ia virar a
quinta cópia). Detalhe completo em `dev-history/DEV-HISTORY.md`, entrada de
2026-09-03/04.

## Decisões, com o motivo

- **Sobe a regra, não o modelo.** Critério de corte das três promoções: a peça não
  pode tocar identidade de produto, e errar nela tem de doer (segurança ou
  jurídico). Foi o que manteve fora a sessão, a tabela de tokens e o conceito
  genérico de "sujeito" no #24 — Solidário e V3REvent discordam exatamente aí.
- **Quem tem objeto-valor no domínio mantém a classe e delega a validação.**
  Evitou obrigar o GE Associados a reescrever domínio para consumir `Documents\`.
- **Ativo de front mora dentro de `src/`** (ADR-014) — o Strauss só copia o que
  está sob o autoload PSR-4. Medido executando, não suposto.
- **Versão de ativo é a data de modificação do arquivo**, não a versão do plugin.
- **`format()` de entrada incompleta devolve o normalizado, nunca o cru**
  (confirmado pelo Bruno em 05/09). Único ponto em que o RIT360 Solidário muda de
  comportamento ao migrar o CPF.
- **Enriquecimento de cadastro por CNPJ: descartado** pelo Bruno — as APIs
  disponíveis não pagam o esforço. Avaliado e recusado, não esquecido.

## Premissas que caíram

- **"As cópias de CNPJ/CPF podem não concordar."** Não é o caso: 200 mil entradas
  pelas quatro implementações da casa e pela nova, **zero divergências de
  validade**. O que divergia era a forma da API. O risco era prospectivo.
- **"A calibração do limiar protege contra `uol`/`bol`/`aol`/`sol`."** Não protege —
  eles distam uma edição e o limiar de rótulo curto é 1. Quem protege é a exclusão
  deles da lista padrão. O comentário de origem no V3REvent afirmava o contrário, e
  foi corrigido lá.
- **"O critério 3 da #24 (uso único sob corrida) é da biblioteca."** Não é:
  atomicidade depende da tabela, que é do produto.

## Issues pendentes, por prioridade

| # | O que é | Por que está nesta posição |
|---|---|---|
| 23 | Sugeridor de domínio de e-mail — falta o V3REvent consumir da biblioteca | Único item cuja **entrega já está pronta e parada**; enquanto não migra, existem duas cópias vivas do mesmo algoritmo |
| 26 | A biblioteca não distribui peça de interface, e elas nascem duplicadas | Mesmo problema da #23 um nível acima; o V3REvent e o Flow já têm cópia do mesmo componente de tela |
| 4 | Rollout do v3r-core nos 6 plugins | É o que faz todo o resto valer: biblioteca sem consumidor não economiza nada |
| 15 | Três plugins carregam a biblioteca de PDF sem prefixo | Vira colisão em produção assim que a #16 padronizar — risco que cresce sozinho |
| 16 | Padronizar geração de PDF e catálogo de componentes | Mesma família da #15; a duplicação de PDF já custou três implementações divergentes |
| 19 | Tela de licença consulta o servidor duas vezes por abertura | Bug real, mas invisível para quem usa |
| 6 | Prefixar dependências compartilhadas (mpdf, dompdf, phpspreadsheet…) | Dívida técnica com risco de colisão |
| 13 | Três repositórios não validam commit por CI | Infraestrutura: protege trabalho futuro, não entrega nada hoje |
| 7 | CI não roda em branch de feature | Idem, menor alcance |
| 25 | Convenção de posição das entradas de menu da família | Melhoria de organização, sem impacto funcional |
| 14 | Padronizar a publicação dos plugins | Processo; grande e sem prazo |

**O que mais pesou na ordem:** o que já está pronto e parado vem antes do que ainda
precisa ser construído; risco que cresce sozinho vem antes de dívida estável. A
#27 (assinatura de documentos) ficou fora da tabela por ser da sessão `v3rcore-96`.

## Próximo passo

Migrar o **V3REvent** para consumir `Support\EmailSuggestion` e o ativo JS da
biblioteca (issue #23): fixar `^0.10.0`, apagar `templates/assets/email-suggestion.js`
e `Core\Support\EmailSuggestion`, aplicar o filtro do produto sobre
`EmailSuggestion::defaultDomains()`, e trocar o global no `public.js` de
`V3REventEmailSuggestion` para `V3RCoreEmailSuggestion` — é a única linha de tela
que muda. Fazer só com a árvore do V3REvent livre.

## Comandos úteis

```bash
composer check        # phpcs + phpstan + phpunit + testes JS, nesta ordem
make test-js          # só o espelho JS (node --test, sem dependências)
vendor/bin/phpunit    # só a suíte PHP
```

Publicar uma versão: merge `--no-ff` da branch de feature em `main`, `git tag -a
vX.Y.Z`, e `git push origin main --follow-tags`. Carregue o token no mesmo comando
(`source bin/config.sh; gh ...`) — nunca imprima cofre nem `.envrc`.

## ⚠️ Aviso ao abrir a próxima sessão

Em 04-05/09 havia **duas sessões abertas neste mesmo repositório** — esta e a
`v3rcore-96`, que entregou a #27 (assinatura de documentos, v0.11.0) commitando
direto na `main`. Antes de mexer em qualquer coisa, rode `git log` e `git tag` e
confirme que o seu contexto é o estado real: duas sessões escrevendo na mesma
árvore é como se perde trabalho.
