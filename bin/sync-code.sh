#!/usr/bin/env bash
# sync-code.sh — envia o repositório do CÓDIGO (Code/), que é nosso e editável.
#
#   bin/sync-code.sh                 # commita tudo e empurra
#   bin/sync-code.sh -m "mensagem"   # mensagem de commit personalizada
#   bin/sync-code.sh --yes           # sem perguntar
#
# Quem faz o trabalho pesado é o git-safe.sh: ele nunca descarta nada, roda a
# validação do projeto ANTES de juntar na principal e para com explicação em
# português quando não consegue provar que nada se perde.
set -euo pipefail
BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$BIN_DIR/config.sh"

COMMIT_MSG="chore: sincroniza código $(date '+%Y-%m-%d')"
[ "${1:-}" = "-m" ] && COMMIT_MSG="${2:?mensagem faltando}"
for a in "$@"; do case "$a" in --yes|-y) export RIT_YES=1 ;; esac; done

exigir_token

[ -d "$CODE_DIR/.git" ] || {
  echo "${C_ERR:-}✗ $CODE_DIR não é um repositório.${C_OFF:-} Rode 'prj.sh -d' para clonar." >&2
  exit 1
}

source "$BIN_DIR/git-safe.sh"

# A validação roda ANTES de juntar o trabalho na branch principal. Acrescente
# aqui o comando do projeto quando ele existir (phpunit, npm test, pytest…).
VALIDACAO=""
[ -x "$CODE_DIR/vendor/bin/phpunit" ] && VALIDACAO="php -d extension=iconv vendor/bin/phpunit"

git_safe_sync "$CODE_DIR" "Código" "$COMMIT_MSG" "$VALIDACAO" || exit 1
echo "${C_OK:-}✓${C_OFF:-} Código sincronizado → $CODE_REPO"
