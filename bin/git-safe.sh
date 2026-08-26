#!/usr/bin/env bash
# git-safe.sh — atalho para a biblioteca única, no v3rtech-scripts.
#
# A camada de segurança de git da casa mora em UM lugar só:
#     <v3rtech-scripts>/lib/git-safe.sh
#
# Antes eram sete cópias idênticas, uma por projeto. Corrigir uma falha exigia
# lembrar das outras seis — e não se lembra: em 08/2026 uma correção de classe
# ficou só onde nasceu, e em 16/08/2026 o mesmo aconteceu com a leitura de
# "vazio" como sucesso. Aqui fica só a BUSCA; o conteúdo é lá.
#
# O acoplamento é real e assumido: um projeto clonado sem o v3rtech-scripts por
# perto não sincroniza. Por isso a busca tem três candidatos e a falha é
# barulhenta, com o caminho procurado e o que fazer — nunca silenciosa.
#
# Este arquivo é carregado com `source`, nunca executado.

_gs_procura() {
  local c
  for c in "${V3R_SCRIPTS:-}" \
           "${PRJ_RAIZ:-/mnt/trabalho/Projetos}/V3RTECH/v3rtech-scripts" \
           "/mnt/trabalho/Cloud/Compartilhado/Linux/v3rtech-scripts"; do
    [ -n "$c" ] && [ -r "$c/lib/git-safe.sh" ] && { printf '%s' "$c/lib/git-safe.sh"; return 0; }
  done
  return 1
}

_GS_LIB="$(_gs_procura)" || {
  {
    echo "✗ Não encontrei a biblioteca git-safe.sh do v3rtech-scripts."
    echo "  Procurei, nesta ordem:"
    echo "    \$V3R_SCRIPTS/lib/git-safe.sh"
    echo "    ${PRJ_RAIZ:-/mnt/trabalho/Projetos}/V3RTECH/v3rtech-scripts/lib/git-safe.sh"
    echo "    /mnt/trabalho/Cloud/Compartilhado/Linux/v3rtech-scripts/lib/git-safe.sh"
    echo "  Traga o v3rtech-scripts (prj.sh -d) ou aponte V3R_SCRIPTS para ele:"
    echo "    V3R_SCRIPTS=/caminho/do/v3rtech-scripts ./sync-all.sh -a"
  } >&2
  return 1 2>/dev/null || exit 1
}

# shellcheck disable=SC1090
source "$_GS_LIB" || { echo "✗ Falhei ao carregar $_GS_LIB" >&2; return 1 2>/dev/null || exit 1; }
unset -f _gs_procura
