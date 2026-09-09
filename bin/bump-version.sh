#!/usr/bin/env bash
# bump-version.sh — sobe a versão do plugin em TODOS os pontos declarados, e só
# nesses. Nada de varrer o repositório adivinhando: a lista de pontos vem de
# VERSION_POINTS em bin/config.sh, porque a lista muda de projeto para projeto
# de propósito (ex.: o package.json da SPA acompanha num plugin e não acompanha
# noutro — não é descuido, é convenção do projeto).
#
#   bin/bump-version.sh patch          # 1.72.0 → 1.72.1
#   bin/bump-version.sh minor          # 1.72.0 → 1.73.0
#   bin/bump-version.sh major          # 1.72.0 → 2.0.0
#   bin/bump-version.sh 1.73.0         # versão explícita
#   bin/bump-version.sh patch --dry-run
#
# O que este script NUNCA faz: commitar, criar tag, empurrar. Bump é uma coisa;
# publicar é decisão separada e deliberada — quem publica é quem empurra a tag.
#
# ─── VERSION_POINTS — formato ────────────────────────────────────────────────
# Array em bin/config.sh, um item por ponto de versão, caminhos relativos a
# $CODE_DIR. Quatro tipos, um por formato de arquivo já visto na casa:
#
#   header:<arquivo>
#     Cabeçalho de plugin WordPress — a linha "* Version: X.Y.Z" (com ou sem
#     asterisco de docblock). Mesma âncora que build-zip.sh já usa para não
#     confundir com outro "Version:" no corpo do arquivo.
#
#   const:<arquivo>:<NOME_DA_CONSTANTE>
#     define( 'NOME_DA_CONSTANTE', 'X.Y.Z' ); em PHP.
#
#   json:<arquivo>
#     Campo "version" de primeiro nível num JSON (package.json, composer.json).
#
#   npmlock:<arquivo>
#     package-lock.json do npm. O lock NÃO é ponto de versão independente: é
#     derivado do package.json ao lado, e só se realinha quando o npm roda —
#     sem isto ele fica para trás e a diferença aparece depois misturada ao
#     próximo commit que mexer no front (v3rtech-scripts#41). Declare-o SEMPRE
#     que declarar o package.json correspondente.
#     ⚠️ O lock traz a versão em dois lugares (a raiz e o pacote "") e traz
#     também a de CADA dependência. Só os dois primeiros são tocados: no
#     lockfileVersion 3 as dependências vivem em chaves "node_modules/…", que
#     vêm depois — o corte é aí, e o script confere quantos campos encontrou.
#
#   fallback:<arquivo>:<âncora>
#     Literal de versão que aparece como argumento numa chamada de função —
#     caso do V3RLGPD, cujo PluginVersion::resolve( __FILE__, '1.67.3' ) usa a
#     string como valor de reserva quando a leitura do cabeçalho falhar.
#     <âncora> é um texto que aparece só naquela linha (ex.: o nome da chamada).
#
# Exemplo (V3RLGPD):
#   VERSION_POINTS=(
#     "header:src/v3rlgpd.php"
#     "fallback:src/v3rlgpd.php:PluginVersion::resolve"
#     "json:src/admin/package.json"
#     "npmlock:src/admin/package-lock.json"
#   )
#
# Ponto que existe no arquivo mas não deve acompanhar o bump (ex.: a versão de
# SCHEMA de banco, que é coisa diferente de versão de release) simplesmente NÃO
# entra em VERSION_POINTS — não há "exclusão", há declaração do que É.
set -euo pipefail
# BUMP_BIN_DIR: quando definida, sobrepõe a localização de onde este script lê
# config.sh. Existe para o publicar-plugin.sh (utils/), que chama esta MESMA
# cópia canônica para qualquer plugin da casa — sem depender de cada projeto
# ter uma cópia própria de bump-version.sh no seu bin/, que divergiria com o
# tempo. Uso normal (script já dentro do bin/ do projeto): nada muda.
BIN_DIR="${BUMP_BIN_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
source "$BIN_DIR/config.sh"

# ─── Argumentos ───────────────────────────────────────────────────────────────
DRY_RUN=false
ALVO=""
for a in "$@"; do
  case "$a" in
    --dry-run) DRY_RUN=true ;;
    -h|--help) awk 'NR>1 { if (!/^#/) exit; sub(/^# ?/, ""); print }' "$0"; exit 0 ;;
    major|minor|patch) ALVO="$a" ;;
    [0-9]*.[0-9]*.[0-9]*) ALVO="$a" ;;
    *) echo "${C_ERR:-}✗ Argumento desconhecido: $a${C_OFF:-}" >&2; exit 2 ;;
  esac
done
if [ -z "$ALVO" ]; then
  echo "${C_ERR:-}✗ Uso: bump-version.sh <major|minor|patch|X.Y.Z> [--dry-run]${C_OFF:-}" >&2
  exit 2
fi

if [ "${VERSION_POINTS+set}" != "set" ] || [ "${#VERSION_POINTS[@]}" -eq 0 ]; then
  echo "${C_ERR:-}✗ VERSION_POINTS não está declarado (ou está vazio) em bin/config.sh.${C_OFF:-}" >&2
  echo "  Sem essa declaração não há como saber quais são os pontos de versão" >&2
  echo "  DESTE projeto — a lista muda de plugin para plugin. Veja o cabeçalho" >&2
  echo "  deste script para o formato e um exemplo real (V3RLGPD)." >&2
  exit 2
fi

# ─── Leitura de cada ponto ────────────────────────────────────────────────────
# ler_ponto <declaração> → imprime a versão encontrada, ou nada + retorna 1.
ler_ponto() {
  local decl="$1" tipo arquivo resto caminho
  tipo="${decl%%:*}"; resto="${decl#*:}"

  case "$tipo" in
    header)
      caminho="$CODE_DIR/$resto"
      [ -r "$caminho" ] || return 1
      grep -m1 -E "^[[:space:]]*\*?[[:space:]]*Version:" "$caminho" \
        | sed -E "s/.*Version:[[:space:]]*//" | awk '{print $1}' | tr -d '\r'
      ;;
    const)
      arquivo="${resto%%:*}"; local const="${resto#*:}"
      caminho="$CODE_DIR/$arquivo"
      [ -r "$caminho" ] || return 1
      grep -m1 -E "define\([[:space:]]*'$const'[[:space:]]*,[[:space:]]*'[^']+'" "$caminho" \
        | sed -E "s/.*define\([[:space:]]*'$const'[[:space:]]*,[[:space:]]*'([^']+)'.*/\1/"
      ;;
    json)
      caminho="$CODE_DIR/$resto"
      [ -r "$caminho" ] || return 1
      grep -m1 -E '"version"[[:space:]]*:[[:space:]]*"[^"]+"' "$caminho" \
        | sed -E 's/.*"version"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/'
      ;;
    npmlock)
      caminho="$CODE_DIR/$resto"
      [ -r "$caminho" ] || return 1
      # O formato é conferido AQUI, na leitura, e não só na hora de escrever:
      # a fase de escrita percorre os pontos um a um, então recusar lá deixaria
      # os pontos anteriores já bumpados e o projeto divergente — que é o
      # estado que este script existe para impedir. Devolver vazio faz o script
      # parar na validação, antes de tocar em qualquer arquivo.
      local corte_r
      corte_r="$(grep -n -m1 '"node_modules/' "$caminho" | cut -d: -f1 || true)"
      if [ -n "$corte_r" ]; then corte_r=$(( corte_r - 1 )); else corte_r="$(wc -l < "$caminho")"; fi
      local achados_r
      achados_r="$(head -n "$corte_r" "$caminho" | grep -c -E '"version"[[:space:]]*:' || true)"
      [ "$achados_r" -eq 2 ] || return 1

      # A primeira "version" do lock é a da raiz — a mesma do package.json ao lado.
      grep -m1 -E '"version"[[:space:]]*:[[:space:]]*"[^"]+"' "$caminho" \
        | sed -E 's/.*"version"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/'
      ;;
    fallback)
      arquivo="${resto%%:*}"; local ancora="${resto#*:}"
      caminho="$CODE_DIR/$arquivo"
      [ -r "$caminho" ] || return 1
      grep -F -m1 "$ancora" "$caminho" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | tail -1
      ;;
    *)
      echo "${C_ERR:-}✗ Tipo de ponto desconhecido: '$tipo' (em '$decl')${C_OFF:-}" >&2
      return 1
      ;;
  esac
}

# aplicar_ponto <declaração> <nova_versão>
aplicar_ponto() {
  local decl="$1" novo="$2" tipo resto arquivo caminho
  tipo="${decl%%:*}"; resto="${decl#*:}"

  case "$tipo" in
    header)
      caminho="$CODE_DIR/$resto"
      sed -i -E "0,/(^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*)[^[:space:]]+/s//\1$novo/" "$caminho"
      ;;
    const)
      arquivo="${resto%%:*}"; local const="${resto#*:}"
      caminho="$CODE_DIR/$arquivo"
      sed -i -E "s/(define\([[:space:]]*'$const'[[:space:]]*,[[:space:]]*')[^']+(')/\1$novo\2/" "$caminho"
      ;;
    json)
      caminho="$CODE_DIR/$resto"
      sed -i -E "0,/(\"version\"[[:space:]]*:[[:space:]]*\")[^\"]+(\")/s//\1$novo\2/" "$caminho"
      ;;
    npmlock)
      caminho="$CODE_DIR/$resto"
      # Corta na primeira chave "node_modules/": dali para baixo cada bloco traz
      # a versão de UMA DEPENDÊNCIA, e reescrevê-las mentiria sobre o que está
      # instalado. Lock sem dependência nenhuma: o corte é o fim do arquivo.
      local corte
      # grep -m1 em vez de "grep | head -1": com pipefail, o head fecha o cano,
      # o grep morre de SIGPIPE e o script aborta em silêncio no meio do bump.
      # É o mesmo motivo de o tipo fallback já usar -m1.
      corte="$(grep -n -m1 '"node_modules/' "$caminho" | cut -d: -f1 || true)"
      if [ -n "$corte" ]; then corte=$(( corte - 1 )); else corte="$(wc -l < "$caminho")"; fi

      # O lock traz a versão em DOIS lugares (a raiz e o pacote ""). Número
      # diferente disso significa que o formato mudou, e aí reescrever em
      # silêncio é pior que parar — o arquivo descreve o que está instalado.
      local achados
      achados="$(head -n "$corte" "$caminho" | grep -c -E '"version"[[:space:]]*:' || true)"
      if [ "$achados" -ne 2 ]; then
        echo "${C_ERR:-}✗ $resto: esperava 2 campos de versão antes das dependências, achei $achados.${C_OFF:-}" >&2
        echo "  O formato do lock mudou — confira o arquivo antes de seguir." >&2
        return 1
      fi

      sed -i -E "1,${corte}s/(\"version\"[[:space:]]*:[[:space:]]*\")[^\"]+(\")/\\1$novo\\2/" "$caminho"
      ;;
    fallback)
      arquivo="${resto%%:*}"; local ancora="${resto#*:}"
      caminho="$CODE_DIR/$arquivo"
      local linha; linha="$(grep -F -n -m1 "$ancora" "$caminho" | cut -d: -f1)"
      [ -n "$linha" ] || { echo "${C_ERR:-}✗ Âncora '$ancora' não encontrada em $arquivo.${C_OFF:-}" >&2; return 1; }
      # Aspas simples OU duplas: o tipo `fallback` nasceu para PHP ('1.2.3'),
      # e há projeto em Python (version="1.2.3") — o V3RSigner, que precisou
      # divergir por isso. O grupo capturado devolve a MESMA aspa que estava
      # lá, para não trocar o estilo do arquivo.
      sed -i -E "${linha}s/(['\"])[0-9]+\.[0-9]+\.[0-9]+\1/\1$novo\1/" "$caminho"
      ;;
  esac
}

# ─── (1) Lê o estado atual de cada ponto ──────────────────────────────────────
declare -a ATUAIS=()
FALTANDO=false
for decl in "${VERSION_POINTS[@]}"; do
  arquivo_rel="${decl#*:}"; arquivo_rel="${arquivo_rel%%:*}"
  v="$(ler_ponto "$decl" || true)"
  if [ -z "$v" ]; then
    echo "${C_ERR:-}✗ Não consegui ler a versão em '$decl' (arquivo $CODE_DIR/$arquivo_rel).${C_OFF:-}" >&2
    FALTANDO=true
  fi
  ATUAIS+=("$v")
done
$FALTANDO && exit 1

# ─── (2) Recusa se já estiverem divergentes ANTES do bump ────────────────────
# Divergência prévia é defeito a investigar, não coisa para o script alinhar
# em silêncio — quem descobre isso é quem decide o valor certo, não o script.
PRIMEIRA="${ATUAIS[0]}"
DIVERGENTE=false
for v in "${ATUAIS[@]}"; do
  [ "$v" != "$PRIMEIRA" ] && DIVERGENTE=true
done
if $DIVERGENTE; then
  echo "${C_ERR:-}✗ Os pontos de versão já estão divergentes ANTES do bump — não vou mexer em nada.${C_OFF:-}" >&2
  echo "  Ponto                                              Versão" >&2
  for i in "${!VERSION_POINTS[@]}"; do
    printf '  %-50s %s\n' "${VERSION_POINTS[$i]}" "${ATUAIS[$i]}" >&2
  done
  echo >&2
  echo "  Alinhe manualmente qual versão é a certa e rode de novo." >&2
  exit 1
fi
ATUAL="$PRIMEIRA"

# ─── (3) Calcula a nova versão ────────────────────────────────────────────────
if [[ "$ALVO" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  NOVA="$ALVO"
else
  IFS='.' read -r MA MI PA <<< "$ATUAL"
  case "$ALVO" in
    major) NOVA="$((MA + 1)).0.0" ;;
    minor) NOVA="$MA.$((MI + 1)).0" ;;
    patch) NOVA="$MA.$MI.$((PA + 1))" ;;
  esac
fi

if [ "$NOVA" = "$ATUAL" ]; then
  echo "${C_WARN:-}• A versão calculada ($NOVA) é igual à atual — nada a fazer.${C_OFF:-}"
  exit 0
fi

echo "${C_INFO:-}▶ $ATUAL → $NOVA${C_OFF:-} (${#VERSION_POINTS[@]} ponto(s))"
for i in "${!VERSION_POINTS[@]}"; do
  printf '  %-50s %s → %s\n' "${VERSION_POINTS[$i]}" "${ATUAIS[$i]}" "$NOVA"
done

if $DRY_RUN; then
  echo "${C_WARN:-}• --dry-run: nada foi alterado.${C_OFF:-}"
  exit 0
fi

# ─── (4) Aplica em todos os pontos ────────────────────────────────────────────
for decl in "${VERSION_POINTS[@]}"; do
  aplicar_ponto "$decl" "$NOVA"
done

# Confere que todos convergiram para a nova versão — rede de segurança contra
# bug de regex nesta própria ferramenta, não só contra o descuido de quem edita
# à mão.
declare -a DEPOIS_LIST=()
PROBLEMA=false
for decl in "${VERSION_POINTS[@]}"; do
  v="$(ler_ponto "$decl" || true)"
  DEPOIS_LIST+=("$v")
  [ "$v" != "$NOVA" ] && PROBLEMA=true
done
if $PROBLEMA; then
  echo "${C_ERR:-}✗ Depois de aplicar, nem todos os pontos convergiram para $NOVA:${C_OFF:-}" >&2
  for i in "${!VERSION_POINTS[@]}"; do
    printf '  %-50s %s\n' "${VERSION_POINTS[$i]}" "${DEPOIS_LIST[$i]}" >&2
  done
  echo "  Chame o Claude — isso é defeito na ferramenta, não no projeto." >&2
  exit 1
fi
echo "${C_OK:-}✓${C_OFF:-} Todos os pontos em $NOVA."

# ─── (5) Validação do projeto, se houver ──────────────────────────────────────
# Mesmo espírito do git-safe.sh: valida antes de considerar pronto. Aqui não há
# merge para travar — só o aviso, para quem vai commitar decidir com os olhos
# abertos.
if [ -x "$CODE_DIR/vendor/bin/phpunit" ]; then
  echo "${C_INFO:-}▶ Rodando a suíte do projeto…${C_OFF:-}"
  if ( cd "$CODE_DIR" && php -d extension=iconv vendor/bin/phpunit ) > /tmp/.bump_validate.$$ 2>&1; then
    echo "${C_OK:-}✓${C_OFF:-} Suíte passou."
    rm -f "/tmp/.bump_validate.$$"
  else
    tail -30 "/tmp/.bump_validate.$$" | sed 's/^/    /' >&2
    rm -f "/tmp/.bump_validate.$$"
    echo "${C_WARN:-}⚠ A suíte falhou depois do bump.${C_OFF:-} A versão já foi alterada nos arquivos;" >&2
    echo "  confira se a falha é da mudança de versão ou já existia antes." >&2
    exit 1
  fi
fi

echo
echo "${C_OK:-}✓ Versão em $NOVA em todos os pontos.${C_OFF:-} Nada foi commitado, nem tag criada — isso é com você."
