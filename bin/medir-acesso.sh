#!/usr/bin/env bash
# Roda a sonda de acesso por pessoa para todos os perfis e endereços de um
# produto, gravando um arquivo por (pessoa, endereço).
#
#   bin/medir-acesso.sh <produto> <pasta-de-saida>
#
# <produto> tem bin/sondas/<produto>.env (USERS, PAGES e V3R_PROBE_*) e,
# opcionalmente, bin/sondas/<produto>.php (adaptador). Somente leitura — ver o
# cabeçalho de bin/sonda-acesso-por-pessoa.php.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
prod=${1:?produto}
out=${2:?pasta de saída}
ctr=${CTR:-dev-wp}

# shellcheck source=/dev/null
source "$here/sondas/$prod.env"
mkdir -p "$out"

docker cp "$here/sonda-acesso-por-pessoa.php" "$ctr:/tmp/sonda-acesso-por-pessoa.php"

envs=()
for v in PLUGIN SLUG NS SCREENS CAPS NAV_CLASS SURFACES SKIP SKIP_CB ADMIN_INIT; do
	n="V3R_PROBE_$v"
	if [ -n "${!n:-}" ]; then
		envs+=(-e "$n=${!n}")
	fi
done
if [ -f "$here/sondas/$prod.php" ]; then
	docker cp "$here/sondas/$prod.php" "$ctr:/tmp/sonda-adaptador-$prod.php"
	envs+=(-e "V3R_PROBE_ADAPTER=/tmp/sonda-adaptador-$prod.php")
fi

for u in $USERS; do
	for p in ${PAGES:-$V3R_PROBE_SLUG}; do
		docker exec "${envs[@]}" -e "V3R_PROBE_LOGIN=$u" -e "V3R_PROBE_PAGE=$p" "$ctr" \
			php -d display_errors=stderr -d error_reporting=E_ERROR /tmp/sonda-acesso-por-pessoa.php \
			> "$out/$u@$p.txt" 2> "$out/$u@$p.err" || echo "FALHOU: $u@$p (ver $out/$u@$p.err)"
	done
done

python3 "$here/sonda-matriz.py" "$out" "$USERS" "${PAGES:-$V3R_PROBE_SLUG}" > "$out/matriz.md"
echo "matriz: $out/matriz.md ($(wc -l < "$out/matriz.md") linhas)"
