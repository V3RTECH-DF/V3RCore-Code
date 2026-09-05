#!/usr/bin/env bash
# sync-tag.sh — publica no servidor a tag da versão corrente do código.
#
#   bin/sync-tag.sh          # publica, mostrando o que vai enviar antes
#   bin/sync-tag.sh --yes    # sem perguntar
#
# Não cria tag nem escolhe entre várias: só publica a mais recente alcançável
# a partir de HEAD (o que `git describe --tags` acha), e só quando o servidor
# já tem o commit por trás — senão para e explica (rode sync-code primeiro).
# Quem faz o trabalho pesado é o git-safe.sh: nunca força, e nunca sobrescreve
# o alvo de uma tag que o servidor já publicou.
set -euo pipefail
BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$BIN_DIR/config.sh"

for a in "$@"; do case "$a" in --yes|-y) export RIT_YES=1 ;; esac; done

exigir_token

[ -d "$CODE_DIR/.git" ] || {
  echo "${C_ERR:-}✗ $CODE_DIR não é um repositório.${C_OFF:-} Rode 'prj.sh -d' para clonar." >&2
  exit 1
}

source "$BIN_DIR/git-safe.sh"

git_safe_publish_tag "$CODE_DIR" "Tag" || exit 1
echo "${C_OK:-}✓${C_OFF:-} Tag verificada/publicada → $CODE_REPO"
