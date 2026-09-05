#!/usr/bin/env bash
#
# sync-all.sh — deixa o projeto inteiro alinhado (família V3RTECH/RIT360).
#
# Um comando só, em vez de sete. Executa os scripts de bin/ na ORDEM CANÔNICA,
# independente da ordem em que você passar as flags:
#   1. sync-code      envia o código para o repositório
#      (ou pull-code, quando o código é escrito fora e aqui é espelho)
#   2. sync-tag       publica a tag da versão corrente (só depois do código)
#   3. sync-project   envia docs, decisões e backlog
#   4. sync-local     espelha o plugin no WordPress local (DEV)
#   5. build-zip      gera o ZIP instalável em dist/
#   6. publish-manual publica o manual do usuário
#   7. deploy         atualiza os sites de PRODUÇÃO (pede confirmação)
#
# O commit vem antes do build e do deploy de propósito: o que vai para produção
# é o que está registrado no repositório, nunca uma versão só sua.
#
# Uso:
#   ./sync-all.sh -a                 # tudo (deploy pede confirmação)
#   ./sync-all.sh -a -y              # tudo, não-interativo
#   ./sync-all.sh -c -p              # só envia código e projeto, sem tocar produção
#   ./sync-all.sh -l -z              # só sync-local + build-zip
#   ./sync-all.sh -d                 # deploy (reconstrói o zip antes, por segurança)
#   ./sync-all.sh -a --dry-run       # simula: lista os passos; deploy roda em --dry-run
#   ./sync-all.sh -d -- dev nbti     # deploy só nesses sites (em vez de --all)
#
# Flags:
#   -a, --all       Executa todos os passos que existirem neste projeto.
#   -c, --code      sync-code.sh      (commita e envia o repositório do código),
#                   ou pull-code.sh quando é este que existe (espelho do Lovable).
#   -t, --tag       sync-tag.sh       (publica a tag da versão corrente, sem forçar).
#   -p, --projeto   sync-project.sh   (commita e envia docs, decisões e backlog).
#   -l, --local     sync-local.sh     (espelha o plugin no WordPress local/DEV).
#   -z, --zip       build-zip.sh      (gera o ZIP instalável em dist/).
#   -m, --manual    publish-manual.sh (publica o manual do usuário).
#   -d, --deploy    deploy.sh --all   (atualiza os sites de PRODUÇÃO).
#   -y, --yes       Não-interativo: repassa --yes ao deploy (pula a confirmação).
#   --dry-run       Simula: lista os passos e roda o deploy em modo --dry-run.
#   --skip-build    Com -d, NÃO reconstrói o zip antes (usa o mais recente de dist/).
#   -h, --help      Esta ajuda.
#
# Notas:
#   - -d reconstrói o zip antes do deploy (evita mandar artefato velho); use
#     --skip-build para pular. Com -a, o build já faz parte do fluxo.
#   - Fail-fast: para no primeiro passo que falhar (não deploya se o build quebrar).
#   - Tudo após "--" é repassado verbatim ao deploy.sh (ex.: nomes de sites).
#
set -uo pipefail

# readlink -f: este script é chamado por um atalho na raiz da pasta de trabalho,
# então o caminho precisa ser resolvido — senão procura o bin/ no lugar errado.
# A raiz do repositório sai do git, nunca de contar níveis: assim o bin/ pode
# mudar de profundidade sem que este script passe a procurar no lugar errado.
# O caminho RESOLVIDO deste arquivo, guardado antes do cd abaixo. A ajuda lê o
# próprio cabeçalho, e usar "$0" para isso quebrava: chamado pelo atalho da raiz
# do container, $0 é "./sync-all.sh" — relativo ao diretório de ONDE se chamou.
# Depois do cd para a raiz do repositório, esse caminho não existe mais, e
# `./sync-all.sh` sem argumento nenhum morria com "não foi possível ler".
_SELF="$(readlink -f "${BASH_SOURCE[0]}")"
BIN_DIR="$(cd "$(dirname "$_SELF")" && pwd)"
REPO_DIR="$(git -C "$BIN_DIR" rev-parse --show-toplevel 2>/dev/null || dirname "$BIN_DIR")"
cd "$REPO_DIR"                                  # daqui, bin/<script>.sh resolve
PROJECT="$(basename "$(dirname "$REPO_DIR")")"  # nome do container, para exibição

GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
log()  { echo -e "${BLUE}$*${NC}"; }
ok()   { echo -e "${GREEN}$*${NC}"; }
warn() { echo -e "${YELLOW}$*${NC}"; }
err()  { echo -e "${RED}$*${NC}" >&2; }

DO_CODE=0 DO_TAG=0 DO_PROJECT=0 DO_LOCAL=0 DO_ZIP=0 DO_MANUAL=0 DO_DEPLOY=0
ALL=0 YES=0 DRY=0 SKIP_BUILD=0
declare -a DEPLOY_EXTRA=()

# A ajuda é o próprio cabeçalho, lido até a primeira linha que não é comentário.
# Faixa fixa de linhas (2,44p) quebra em silêncio no primeiro comentário novo —
# e este arquivo é modelo, feito para ser editado em cada projeto.
usage() { awk 'NR>1 { if (!/^#/) exit; sub(/^# ?/, ""); print }' "$_SELF"; }

while [ $# -gt 0 ]; do
  case "$1" in
    -a|--all)        ALL=1; shift ;;
    -c|--code)       DO_CODE=1; shift ;;
    -t|--tag)        DO_TAG=1; shift ;;
    -p|--projeto)    DO_PROJECT=1; shift ;;
    -l|--local)      DO_LOCAL=1; shift ;;
    -z|--zip)        DO_ZIP=1; shift ;;
    -m|--manual)     DO_MANUAL=1; shift ;;
    -d|--deploy)     DO_DEPLOY=1; shift ;;
    -y|--yes)        YES=1; shift ;;
    --dry-run)       DRY=1; shift ;;
    --skip-build)    SKIP_BUILD=1; shift ;;
    -h|--help)       usage; exit 0 ;;
    --)              shift; DEPLOY_EXTRA=("$@"); break ;;
    -*)              err "Flag desconhecida: $1"; echo; usage; exit 2 ;;
    *)               err "Argumento inesperado: '$1' (use -- para passar sites ao deploy)"; exit 2 ;;
  esac
done

# -a liga cada etapa cujo script EXISTE neste projeto. É o que torna este mesmo
# arquivo utilizável em todos: o portal não tem build-zip, o manual avulso não
# tem deploy, e nenhum dos dois deve abortar por causa disso.
if [ "$ALL" -eq 1 ]; then
  { [ -f bin/sync-code.sh ] || [ -f bin/pull-code.sh ]; } && DO_CODE=1
  [ -f bin/sync-tag.sh ]       && DO_TAG=1
  [ -f bin/sync-project.sh ]   && DO_PROJECT=1
  [ -f bin/sync-local.sh ]     && DO_LOCAL=1
  [ -f bin/build-zip.sh ]      && DO_ZIP=1
  [ -f bin/publish-manual.sh ] && DO_MANUAL=1
  [ -f bin/deploy.sh ]         && DO_DEPLOY=1
fi

# Nada selecionado → ajuda.
if [ $((DO_CODE + DO_TAG + DO_PROJECT + DO_LOCAL + DO_ZIP + DO_MANUAL + DO_DEPLOY)) -eq 0 ]; then
  usage; exit 0
fi

# Deploy exige um zip fresco: reconstrói antes, salvo --skip-build ou -z já pedido.
# Só vale onde HÁ build: projeto sem build-zip.sh (portal, site) deploya direto,
# e não pode abortar exigindo um script que não faz sentido ali.
AUTO_BUILD=0
if [ "$DO_DEPLOY" -eq 1 ] && [ "$DO_ZIP" -eq 0 ] && [ "$SKIP_BUILD" -eq 0 ] && [ -f bin/build-zip.sh ]; then
  DO_ZIP=1; AUTO_BUILD=1
fi

# O passo do código tem duas encarnações e uma só posição na ordem: onde o
# código é NOSSO, sync-code.sh envia; onde ele é escrito fora (Lovable),
# pull-code.sh traz. Nunca os dois no mesmo projeto — por isso um único DO_CODE.
CODE_STEP=""
[ -f bin/sync-code.sh ] && CODE_STEP="bin/sync-code.sh"
[ -z "$CODE_STEP" ] && [ -f bin/pull-code.sh ] && CODE_STEP="bin/pull-code.sh"

# Pré-checagem: os scripts necessários existem neste projeto? (portabilidade na família)
declare -a NEED=()
[ "$DO_CODE"    -eq 1 ] && NEED+=("${CODE_STEP:-bin/sync-code.sh}")
[ "$DO_TAG"     -eq 1 ] && NEED+=("bin/sync-tag.sh")
[ "$DO_PROJECT" -eq 1 ] && NEED+=("bin/sync-project.sh")
[ "$DO_LOCAL"   -eq 1 ] && NEED+=("bin/sync-local.sh")
[ "$DO_ZIP"     -eq 1 ] && NEED+=("bin/build-zip.sh")
[ "$DO_MANUAL"  -eq 1 ] && NEED+=("bin/publish-manual.sh")
[ "$DO_DEPLOY"  -eq 1 ] && NEED+=("bin/deploy.sh")
MISSING=0
for s in "${NEED[@]}"; do
  [ -f "$s" ] || { err "Script necessário ausente neste projeto: $s"; MISSING=1; }
done
[ "$MISSING" -eq 1 ] && { err "Abortado: nem todos os scripts requeridos existem em bin/."; exit 3; }

declare -a SUMMARY=()

run_step() {  # $1=rótulo; demais=comando
  local label="$1"; shift
  echo; log "▶ $label"
  local t0=$SECONDS rc=0
  "$@" || rc=$?
  local dt=$(( SECONDS - t0 ))
  if [ $rc -eq 0 ]; then
    ok "  ✓ $label (${dt}s)"; SUMMARY+=("$label|OK|${dt}s")
  else
    err "  ✗ $label FALHOU (código $rc)"; SUMMARY+=("$label|FALHOU|${dt}s")
    print_summary; err "Interrompido (fail-fast) — passos seguintes não executados."; exit 1
  fi
}

dry_step() {  # $1=rótulo; demais=comando exibido
  local label="$1"; shift
  echo; warn "  [dry-run] $label  →  $*"; SUMMARY+=("$label|DRY|-")
}

print_summary() {
  echo; log "=== Resumo ==="
  printf '  %-26s %-10s %s\n' "PASSO" "STATUS" "TEMPO"
  local row s st t
  for row in "${SUMMARY[@]}"; do
    IFS='|' read -r s st t <<< "$row"
    case "$st" in
      OK)      printf "  ${GREEN}%-26s %-10s %s${NC}\n" "$s" "$st" "$t" ;;
      DRY)     printf "  ${YELLOW}%-26s %-10s %s${NC}\n" "$s" "$st" "$t" ;;
      *)       printf "  ${RED}%-26s %-10s %s${NC}\n" "$s" "$st" "$t" ;;
    esac
  done
}

# --- Plano ---
log "=== ${PROJECT} · sincronização ==="
echo "  Passos: $( [ $DO_CODE -eq 1 ] && printf '%s ' "$(basename "$CODE_STEP" .sh)" )$( [ $DO_TAG -eq 1 ] && printf 'sync-tag ' )$( [ $DO_PROJECT -eq 1 ] && printf 'sync-project ' )$( [ $DO_LOCAL -eq 1 ] && printf 'sync-local ' )$( [ $DO_ZIP -eq 1 ] && printf 'build-zip ' )$( [ $DO_MANUAL -eq 1 ] && printf 'publish-manual ' )$( [ $DO_DEPLOY -eq 1 ] && printf 'deploy ' )"
[ "$AUTO_BUILD" -eq 1 ] && warn "  (build-zip incluído automaticamente antes do deploy; use --skip-build para pular)"
[ "$DRY" -eq 1 ] && warn "  Modo: DRY-RUN"

# --- Execução na ordem canônica ---
# --yes precisa chegar aos passos que confirmam o push (sync-code, sync-project,
# publish-manual). Eles já sabem tratar a flag — exportam RIT_YES=1, que é o que a
# git-safe.sh lê. Antes o -y só era repassado ao deploy, então o push continuava
# perguntando mesmo em modo não-interativo.
declare -a YARG=()
[ "$YES" -eq 1 ] && YARG=(--yes)

if [ "$DO_CODE" -eq 1 ]; then
  _cl="$(basename "$CODE_STEP" .sh)"
  if [ "$DRY" -eq 1 ]; then dry_step "$_cl" "$CODE_STEP"; else run_step "$_cl" "$CODE_STEP" "${YARG[@]}"; fi
fi
if [ "$DO_TAG" -eq 1 ]; then
  if [ "$DRY" -eq 1 ]; then dry_step "sync-tag" bin/sync-tag.sh; else run_step "sync-tag" bin/sync-tag.sh "${YARG[@]}"; fi
fi
if [ "$DO_PROJECT" -eq 1 ]; then
  if [ "$DRY" -eq 1 ]; then dry_step "sync-project" bin/sync-project.sh; else run_step "sync-project" bin/sync-project.sh "${YARG[@]}"; fi
fi
if [ "$DO_LOCAL" -eq 1 ]; then
  if [ "$DRY" -eq 1 ]; then dry_step "sync-local (DEV)" bin/sync-local.sh; else run_step "sync-local (DEV)" bin/sync-local.sh; fi
fi
if [ "$DO_ZIP" -eq 1 ]; then
  if [ "$DRY" -eq 1 ]; then dry_step "build-zip" bin/build-zip.sh; else run_step "build-zip" bin/build-zip.sh; fi
fi
if [ "$DO_MANUAL" -eq 1 ]; then
  if [ "$DRY" -eq 1 ]; then dry_step "publish-manual" bin/publish-manual.sh; else run_step "publish-manual" bin/publish-manual.sh "${YARG[@]}"; fi
fi
if [ "$DO_DEPLOY" -eq 1 ]; then
  declare -a dargs=()
  if [ ${#DEPLOY_EXTRA[@]} -gt 0 ]; then dargs=("${DEPLOY_EXTRA[@]}"); else dargs=(--all); fi
  [ "$YES" -eq 1 ] && dargs+=(--yes)
  [ "$DRY" -eq 1 ] && dargs+=(--dry-run)
  # deploy roda de verdade mesmo em dry-run (o próprio deploy.sh simula com --dry-run).
  run_step "deploy ${dargs[*]}" bin/deploy.sh "${dargs[@]}"
fi

print_summary
echo
ok "Concluído."
