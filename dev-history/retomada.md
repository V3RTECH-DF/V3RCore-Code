# Retomada — V3RCore

_Escrito ao encerrar a sessão de 05/09/2026._

## Onde você está

`v3rtech/v3r-core` é a **biblioteca PHP compartilhada** dos plugins WordPress da casa
(V3RTECH/RIT). Não é plugin: é embutida em cada plugin por Strauss, e **não se
auto-prefixa** — quem prefixa é o hospedeiro.

- **Código e issues:** `Code/`, repositório público `V3RTECH-DF/V3RCore-Code`. A raiz
  do container **não é** repositório; `CLAUDE.md` e `sync-all.sh` na raiz são symlinks
  para dentro de `Code/`.
- **Issues são a lista viva de trabalho.** Levantamento e evidência vão para a issue,
  nunca para arquivo solto.
- **Pode commitar** (é o padrão da casa ao concluir uma unidade de trabalho).
  **Publicar é do Bruno** — entregue o comando, não rode.
- Autenticação do GitHub: `source Code/bin/config.sh` no **mesmo comando** do `gh`.

## Estado atual

**v0.13.0 publicada** (main e tag no servidor, local alinhado). Duas versões saíram nesta
sessão, ambas no módulo `Signing/`:

- **v0.12.0** — emissão e selamento do código de autenticidade em dois momentos (`#28`).
- **v0.13.0** — leitor de certificado: validade e titular (`#29`).

Tudo o que foi entregue está **validado por efeito e publicado**. Nada pela metade.

O módulo `Signing/` é hoje o mais completo da biblioteca e tem catálogo próprio em
`docs/assinatura-com-certificado.md`.

## O que a última sessão fez

Começou como consulta da sessão do RIT360 Flow sobre duas questões de assinatura e
terminou cobrindo quatro frentes.

1. **`#28`** — o código de autenticidade é impresso *dentro* do documento, então o arquivo
   final não existe quando ele é emitido. `issue()` exigia o arquivo; `verifyFile()`
   acusaria adulteração de documento íntegro. Separado em `issue()` + `seal()`.
2. **`#29`** — o leitor de certificado subiu do Flow, com quatro ajustes de forma.
3. **`#15`** — três plugins prefixaram todas as dependências de terceiro, cada um em
   sessão própria. Fechou também a `#6` e revelou a `#32` (colisão real entre dois
   produtos nossos, em versões de majors diferentes), já entregue pelo Flow.
4. **`#30`** — o `sync-all` passou a publicar tag; a lógica foi para a biblioteca
   compartilhada do `v3rtech-scripts`, não para um script local.

Documentação alinhada: catálogo do módulo, receita de integração, README, `bin/README`,
dev-history e changelog.

## Decisões, com o motivo

- **O registro de autenticidade continua mínimo** — não guarda o titular do certificado.
  A rota de conferência é pública e sem autenticação, e a distinção pessoa jurídica ×
  pessoa física falha justamente nos casos que importam (MEI, CNPJ com o responsável no
  nome comum). Página com duas caras vaza pela ausência. Em vez disso, a conferência por
  arquivo — que já existia e ninguém usava — foi ligada na página pública do Flow.
- **`ext-openssl` fica em `suggest`, nunca em `require`** — a biblioteca viaja dentro de
  plugins que nunca assinam; sem a extensão, a leitura degrada para validade nula, que
  leva ao modo degradado, em vez de quebrar a instalação.
- **O leitor não usa os OIDs da ICP-Brasil** — o PHP não decodifica o `othername`, e
  varrer o bloco atrás de 11 dígitos pega NIS ou RG no lugar do CPF.
- **A lógica de publicar tag foi para o `v3rtech-scripts`**, não para um script local:
  escrever só aqui seria a primeira de N cópias, o erro que o próprio `git-safe.sh`
  documenta ter cometido.
- **`#16` adiada por tamanho** e **`#26` marcada para quando não houver outras
  prioridades**; issues-espelho abertas nos seis plugins que geram documento.

## Premissas que caíram

- **A `#4` (rollout) e a `#6` (prefixação) já estavam prontas** e ninguém tinha fechado.
  Os oito plugins consomem a biblioteca com licenciamento e auto-atualização ativos.
- **O V3RProp não estava "fora do escopo por não ter `composer.json`"** — tem, e consome
  a biblioteca prefixada. (A cópia local de TCPDF/FPDI continua existindo e **não aparece
  em levantamento que olhe só o `composer.json`**.)
- **A colisão de bibliotecas não era hipotética** — estava ativa entre V3REvent e RIT360
  Flow, com o mesmo componente em versões de majors diferentes no mesmo WordPress.
- **O critério "comparar o artefato antes e depois" não discrimina** — se a detecção do
  motor ficar apontando para o nome não prefixado, o plugin cai no motor alternativo, o
  arquivo sai e a comparação aprova. Foi preciso provar **qual motor gerou**.
- **A duplicação entre workflow e script de build não é desleixo** — nos seis plugins que
  reimplementam, o script mora no repositório de gestão, que o runner não clona.

## Issues pendentes, por prioridade

| # | Descrição curta | Por que está nesta posição |
|---|---|---|
| 14 | Padronizar a publicação dos plugins | Já derrubou o checkout de quatro sites; é o único item com dano ao cliente já ocorrido. **Prompt do Solidário pronto, aguardando abertura da sessão.** |
| 33 | Registro de ativação nunca aprende a versão nova | Mente sobre quem roda o quê justamente durante incidente. Tem saída barata: o servidor já recebe o dado e o descarta. |
| 34 | Guard de prefixação em três cópias divergentes | Uma acha defeito que as outras aprovam — produz confiança injustificada. Decidir **junto com a `#14`**, não antes. |
| 19 | Tela de licença consulta o servidor duas vezes | Bug isolado, o mais barato da lista; o usuário sente como lentidão. |
| 35 | Componente de navegação da família | Aberta por outra sessão, com especificação. **Ver dúvida abaixo.** |
| 25 | Menus espalhados pelo admin | Provavelmente superseded pela `#35`. |
| 31 | Vocabulário de recusa do V3RSigner | Duas respostas do serviço pedem ações opostas; errar cria fila que desiste do que deu certo. Sem pressa declarada pelo Flow. |
| 26 | Biblioteca não distribui peça de interface | Decisão: fazer **quando não houver outras prioridades**, antes da primeira demanda real. |
| 16 | Padronizar a geração de documento | Adiada por tamanho, por decisão. Issues-espelho abertas nos seis plugins. |
| 13 + 7 | CI não valida commit / não roda em branch de feature | Um trabalho só, por decisão. Sem dano observado. |

O que mais pesou na ordem: **dano já ocorrido ao cliente**, depois **falha silenciosa**
(o que mente sem quebrar), depois custo. Reordene se discordar.

⚠️ **Dúvida a resolver:** a `#35` (aberta por outra sessão, com especificação completa)
parece **superseder** a `#25`. Se sim, a `#25` fecha apontando para ela.

## Próximo passo

Abrir sessão no RIT360 Solidário com o prompt da `#14` — unificar a receita de
empacotamento, movendo o script de build para dentro do repositório do código para que o
workflow o chame em vez de reescrevê-lo. O prompt está pronto no histórico da sessão de
05/09; o modelo a copiar é o V3REvent ou o RIT360 Flow, os dois únicos que já fazem certo.

## Comandos úteis

```bash
# Validação completa (suíte, phpstan, phpcs, testes JS)
cd Code && composer check

# Só a suíte
cd Code && vendor/bin/phpunit

# Sincronizar o código com o servidor (roda a suíte antes de enviar)
./sync-all.sh -c

# Publicar a tag da versão corrente — passo próprio, o -c NÃO leva tags
./sync-all.sh -t

# Issues (o token vem do config.sh, no mesmo comando)
cd Code && source bin/config.sh && gh issue list --repo "$ISSUES_REPO" --state open
```
