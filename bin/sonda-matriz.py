"""Matriz pessoa × linha a partir da saída de bin/medir-acesso.sh.

Uso: sonda-matriz.py <pasta> "<usuarios>" "<paginas>"

A primeira página é a entrada do menu: dela sai a matriz inteira. Das demais
(endereços antigos) sai só o resultado do acesso direto. Imprime apenas as
linhas em que as pessoas diferem.
"""
import re
import sys

pasta, usuarios, paginas = sys.argv[1], sys.argv[2].split(), sys.argv[3].split()
rows, order = {}, []


def put(key, user, value):
    if key not in rows:
        rows[key] = {}
        order.append(key)
    rows[key][user] = value


def parse(user, page, full):
    try:
        txt = open(f"{pasta}/{user}@{page}.txt", encoding="utf-8").read()
    except FileNotFoundError:
        return
    sec = None
    for line in txt.split("\n"):
        if line.startswith("──"):
            sec = line.strip("─ ")
            continue
        if not line.startswith("  ") or sec is None:
            continue
        s = line.strip()
        direto = sec.startswith("acesso direto")
        if not full and not direto:
            continue
        if sec.startswith("REST"):
            m = re.match(r"^(\S+)\s+(\S+)\s+(.*)$", s)
            if m:
                put(f"{m.group(1)} {m.group(2)}", user, m.group(3))
            continue
        if direto and s.startswith(("mensagem:", "diagnostico:")):
            continue  # detalhe da recusa: fica no .txt, não na matriz
        if direto and s.startswith("resultado:"):
            put(f"acesso direto ?page={page}", user, s.split(":", 1)[1].strip())
            continue
        m = re.match(r"^(.*?)\s{2,}(\S.*)$", s)
        if m:
            put((f"[{page}] " if direto else "") + m.group(1), user, m.group(2))
        else:
            put(f"{sec}: {s}", user, "presente")


for u in usuarios:
    for i, p in enumerate(paginas):
        parse(u, p, i == 0)

print("| linha | " + " | ".join(usuarios) + " |")
print("|---" * (len(usuarios) + 1) + "|")
for k in order:
    vals = [rows[k].get(u, "—") for u in usuarios]
    if len(set(vals)) > 1:
        print("| `" + k.replace("|", "\\|") + "` | " + " | ".join(v.replace("|", "\\|") for v in vals) + " |")
