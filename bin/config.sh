#!/usr/bin/env bash
# V3RCore — configuração compartilhada dos scripts de bin/.
# Gerado por utils/setup-new-project.sh em 25/08/2026 (forma: single-repo).
#
# Ponto único de verdade dos caminhos e dos repositórios. Todo script de bin/
# começa por aqui — nada de caminho escrito à mão em script nenhum.

# ─── Caminhos ────────────────────────────────────────────────────────────────
# Derivados da posição DESTE arquivo, nunca hardcoded: sobrevive a mover ou
# renomear a pasta, e funciona igual nas quatro máquinas.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"      # …/V3RTECH/V3RCore/Code/bin
# A raiz do repositório sai do GIT, e não de contar níveis: assim o bin/ pode
# mudar de profundidade sem que os caminhos passem a apontar para o lugar errado
# EM SILÊNCIO — foi essa a quebra que a leitura do código não pegou na migração.
# O dirname é a saída de emergência para quando o git não responder.
PROJECT_DIR="$(git -C "$SCRIPT_DIR" rev-parse --show-toplevel 2>/dev/null || dirname "$SCRIPT_DIR")"
ROOT="$(cd "$PROJECT_DIR/.." && pwd)"                           # …/V3RCore (container)

CODE_DIR="$ROOT/Code"        # repositório do código (nosso, editável)
MANUAL_DIR="$ROOT/Manual"    # clone do repo do manual (edita direto ali)
PORTAL_DIR="$ROOT/Portal"    # site institucional do projeto

# ─── Repositórios (owner/slug) ───────────────────────────────────────────────
GH_OWNER="V3RTECH-DF"
CODE_REPO="V3RTECH-DF/V3RCore-Code"
PROJECT_REPO=""
MANUAL_REPO=""
PORTAL_REPO=""
ISSUES_REPO="V3RTECH-DF/V3RCore-Code"   # onde vive o backlog vivo (fonte de verdade)

# URLs completas, para quem clona (pull-code.sh, publish-manual.sh --clone).
CODE_REMOTE="${CODE_REPO:+https://github.com/$CODE_REPO.git}"
PROJECT_REMOTE="${PROJECT_REPO:+https://github.com/$PROJECT_REPO.git}"
MANUAL_REMOTE="${MANUAL_REPO:+https://github.com/$MANUAL_REPO.git}"
PORTAL_REMOTE="${PORTAL_REPO:+https://github.com/$PORTAL_REPO.git}"

MAIN_BRANCH="main"

# ─── Manual (GitHub Pages) ───────────────────────────────────────────────────
# Domínio próprio do manual. VAZIO = publica na URL padrão do Pages
# (https://v3rtech-df.github.io// — o caminho preserva a CAIXA
# do nome do repositório) e o publish-manual.sh NÃO mexe no CNAME. Preencher só quando o domínio existir de fato e o DNS apontar:
# gravar um CNAME para domínio que não resolve tira o manual do ar.
# Depois de preencher, rode uma vez:
#   gh api -X PUT repos/$MANUAL_REPO/pages -f cname='o.dominio.aqui'
MANUAL_CUSTOM_DOMAIN=""

# ─── Segredos ────────────────────────────────────────────────────────────────
# Fonte única: v3rtech-scripts/lib/secrets.sh. Ela carrega o cofre da máquina
# (~/.config/v3rtech/secrets.env), deriva o nome da variável do token a partir
# do DONO do repositório e define o credential helper inline.
#
# Por que não é aqui e não lê o .envrc: cada config.sh lendo por conta própria
# foi o que fez 9 dos 11 projetos pararem de autenticar de uma vez quando o
# prj.sh mudou onde gera o arquivo — com o sintoma chegando como "a autenticação
# do GitHub CLI expirou", longe da causa. O .envrc volta a ser o que é:
# conveniência do terminal, não fonte de verdade.
V3R_SECRETS="${V3R_SECRETS:-$HOME/.config/v3rtech/secrets.env}"
for _c in "${V3R_SCRIPTS:-}" \
          "${PRJ_RAIZ:-/mnt/trabalho/Projetos}/V3RTECH/v3rtech-scripts" \
          "/mnt/trabalho/Cloud/Compartilhado/Linux/v3rtech-scripts"; do
  [ -n "$_c" ] && [ -r "$_c/lib/secrets.sh" ] && { . "$_c/lib/secrets.sh"; break; }
done
unset _c
if command -v v3r_carrega_token >/dev/null 2>&1; then
  v3r_carrega_token "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)" || true
else
  echo "AVISO: não achei lib/secrets.sh do v3rtech-scripts — sem token." >&2
fi

# ─── Cores (desligadas quando a saída não é terminal) ────────────────────────
if [ -t 1 ]; then
  C_OK=$'\e[32m'; C_WARN=$'\e[33m'; C_ERR=$'\e[31m'; C_INFO=$'\e[36m'; C_OFF=$'\e[0m'
else
  C_OK=""; C_WARN=""; C_ERR=""; C_INFO=""; C_OFF=""
fi

exigir_token() {
  if [ -z "${GH_TOKEN:-}" ]; then
    echo "${C_ERR:-}✗ Sem token para $GH_OWNER${C_OFF:-} — esperado em $V3R_SECRETS" >&2
    echo "  Numa máquina nova ele chega pelo Nextcloud; rode 'prj.sh -r' para apontá-lo." >&2
    return 1
  fi
}
