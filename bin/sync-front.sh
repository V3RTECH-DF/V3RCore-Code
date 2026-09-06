#!/usr/bin/env bash
# sync-front.sh — envia o repositório do PACOTE DE FRONT da família (Front/).
#
#   bin/sync-front.sh                 # commita tudo e empurra
#   bin/sync-front.sh -m "mensagem"   # mensagem de commit personalizada
#   bin/sync-front.sh --yes           # sem perguntar
#
# É repositório SEPARADO do Code/ de propósito, por dois motivos mecânicos:
# o gerenciador de pacotes do JavaScript instala a raiz de um repositório, não
# uma subpasta — então o pacote precisa SER a raiz para cada plugin declarar a
# dependência apontando direto para o GitHub; e os dois versionam em ritmos
# diferentes, o que num repositório só tornaria a numeração ambígua.
#
# Mesmo motor do sync-code.sh: o git-safe.sh nunca descarta nada e roda a
# validação ANTES de juntar na principal.
set -euo pipefail
BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$BIN_DIR/config.sh"

COMMIT_MSG="chore: sincroniza pacote de front $(date '+%Y-%m-%d')"
[ "${1:-}" = "-m" ] && COMMIT_MSG="${2:?mensagem faltando}"
for a in "$@"; do case "$a" in --yes|-y) export RIT_YES=1 ;; esac; done

exigir_token

[ -d "$FRONT_DIR/.git" ] || {
  echo "${C_ERR:-}✗ $FRONT_DIR não é um repositório.${C_OFF:-} Clone $FRONT_REPO ali." >&2
  exit 1
}

source "$BIN_DIR/git-safe.sh"

# A validação roda ANTES de juntar na principal. Só existe quando as
# dependências já foram instaladas — em máquina sem node_modules, o passo é
# pulado em vez de reprovar o envio por uma ferramenta ausente.
VALIDACAO=""
[ -d "$FRONT_DIR/node_modules" ] && VALIDACAO="npm test && npm run build"

git_safe_sync "$FRONT_DIR" "Pacote de front" "$COMMIT_MSG" "$VALIDACAO" || exit 1
echo "${C_OK:-}✓${C_OFF:-} Pacote de front sincronizado → $FRONT_REPO"

# A tag vai junto, e aqui isso NÃO é atalho preguiçoso: cada plugin declara a
# dependência fixando uma tag (sem ela, o build de cada máquina pega um estado
# diferente da branch principal). Código publicado sem a tag correspondente não
# é alcançável por consumidor nenhum — separar os dois passos, como se faz no
# Code/, só produziria pacote publicado que ninguém consegue instalar.
# Quem confirma continua sendo o git-safe; nada é publicado em silêncio.
git_safe_publish_tag "$FRONT_DIR" "Tag do pacote de front" || exit 1
