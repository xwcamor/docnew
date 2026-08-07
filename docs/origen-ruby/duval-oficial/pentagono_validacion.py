#!/usr/bin/env python3
"""
Validacion de los pentagonos de Duval (P1 y P2) de nuestro sistema contra una
implementacion open-source independiente (xDGA, MIT, Carlos Gamez):
https://github.com/engineers-tools/xDGA  (xDGA.CORE/Algorithms/DuvalPentagons)

Por que xDGA: el Excel oficial de Duval que tenemos es SOLO triangulos (sin
pentagonos), y los papers de Duval & Lamarre 2014 estan tras paywall/403. xDGA
es una digitalizacion independiente de la MISMA figura publicada, asi que sirve
de segunda fuente: si dos digitalizaciones independientes coinciden, ambas son
fieles a la figura de Duval.

Resultado (2026-06): P1 98.71% y P2 98.49% de coincidencia de ZONA sobre 200k
mezclas de gases aleatorias. Las discrepancias son slivers finos en bordes
(±1-2 unidades de coordenada cerca del cruce central (0,1.5)/(0,-3) vs (-1,-2),
y la franja delgada PD/S) — ruido de digitalizacion en regiones ambiguas. En el
T3 nuestro poligono es MAS limpio (simple) que el de xDGA (auto-intersectante).

Ejes y formula del punto: CANONICOS (verificado contra 2 ejemplos publicados al
2do decimal). Ejemplo end-to-end: H2=40/C2H6=120/CH4=200/C2H4=40/C2H2=0 -> T1
en ambas implementaciones.

ACTUALIZACION (cierre): se consiguieron los papers PRIMARIOS (Cheim/Duval/Haider
2020 + Duval&Lamarre 2014). Cotejo vertice a vertice: NUESTROS vertices SON los
canonicos; donde diferiamos de xDGA, el paper usa LOS NUESTROS ((0,1.5),(0,-3),
(24.3,-30),(32,-6.1),(-3.5,-3.5)) -> xDGA estaba ligeramente mal, no nosotros. Las
~1.3% de discrepancias de abajo son xDGA. Ver pentagono-validacion.md.

Correr: python3 pentagono_validacion.py
"""
import math, random

# Orden de ejes CANONICO (xDGA constructor: H2, C2H6, CH4, C2H4, C2H2; CCW desde
# H2 arriba). Coincide con duval_zones.json -> pentagons.gas_angles_deg.
ANG = {'h2': 90, 'c2h6': 162, 'ch4': 234, 'c2h4': 306, 'c2h2': 18}
GASES = list(ANG)


def point(gas):
    tot = sum(gas.values())
    if tot <= 0:
        return None
    cp = []
    for g, deg in ANG.items():
        p = gas[g] / tot * 100
        r = math.radians(deg)
        cp.append((p * math.cos(r), p * math.sin(r)))
    # centroide por area con signo (igual que DuvalService::centroid)
    A = cx = cy = 0.0
    n = len(cp)
    for i in range(n):
        x0, y0 = cp[i]
        x1, y1 = cp[(i + 1) % n]
        cr = x0 * y1 - x1 * y0
        A += cr
        cx += (x0 + x1) * cr
        cy += (y0 + y1) * cr
    A /= 2
    if abs(A) < 1e-9:
        return (sum(p[0] for p in cp) / n, sum(p[1] for p in cp) / n)
    return (cx / (6 * A), cy / (6 * A))


def inpoly(pt, poly):
    x, y = pt
    n = len(poly)
    inside = False
    j = n - 1
    for i in range(n):
        xi, yi = poly[i]
        xj, yj = poly[j]
        if ((yi > y) != (yj > y)) and (x < (xj - xi) * (y - yi) / (yj - yi) + xi):
            inside = not inside
        j = i
    return inside


def classify(pt, zones):
    for code, poly in zones:
        if inpoly(pt, poly):
            return code
    return None


# ── NUESTRO (duval_zones.json) ──────────────────────────────────────────────
OURS_P1 = [
    ('T1', [(0, 1.5), (0, -3), (-6, -4), (-22.5, -32.4), (-23.5, -32.4), (-35, 3.1)]),
    ('S',  [(0, 1.5), (-35, 3.1), (-38, 12.4), (0, 40)]),
    ('D1', [(0, 40), (0, 1.5), (4, 16), (32, -6.1), (38, 12.4)]),
    ('D2', [(32, -6.1), (4, 16), (0, 1.5), (0, -3), (24.3, -30)]),
    ('T3', [(0, -3), (24.3, -30), (23.5, -32.4), (1, -32.4), (-6, -4)]),
    ('T2', [(-6, -4), (1, -32.4), (-22.5, -32.4)]),
    ('PD', [(0, 33), (0, 24.5), (-1, 24.5), (-1, 33)]),
]
OURS_P2 = [
    ('O',    [(0, 1.5), (0, -3), (-6, -4), (-11, -8), (-21.5, -32.4), (-23.5, -32.4), (-35, 3.1)]),
    ('S',    [(0, 1.5), (-35, 3.1), (-38, 12.4), (0, 40)]),
    ('D1',   [(0, 40), (0, 1.5), (4, 16), (32, -6.1), (38, 12.4)]),
    ('D2',   [(32, -6.1), (4, 16), (0, 1.5), (0, -3), (24.3, -30)]),
    ('T3-H', [(0, -3), (24.3, -30), (23.5, -32.4), (2.5, -32.4), (-3.5, -3.5)]),
    ('C',    [(-6, -4), (-11, -8), (-21.5, -32.4), (2.5, -32.4), (-3.5, -3.5)]),
    ('PD',   [(0, 33), (0, 24.5), (-1, 24.5), (-1, 33)]),
]

# ── xDGA (C#, MIT) — copiado verbatim de DuvalPentagon{One,Two}Rule.cs ───────
XDGA_P1 = [
    ('PD', [(0, 33), (-1, 33), (-1, 24.5), (0, 24.5)]),
    ('D1', [(0, 40), (38, 12.4), (32, -6.0), (4, 16), (0, 1.5)]),
    ('D2', [(4, 16), (32, -6.0), (24, -30), (-1, -2)]),
    ('T3', [(-1, -2), (-6, -4), (1, -32.4), (-23.3, -32.4), (24, -30)]),
    ('T2', [(1, -32.4), (-22.5, -32.4), (-6, -4)]),
    ('T1', [(0, 1.5), (-35, 3.0), (-23.3, -32.4), (-22.5, -32.4), (-6, -4), (-1, -2)]),
    ('S',  [(0, 1.5), (0, 24.5), (-1, 24.5), (-1, 33), (0, 33), (0, 40), (-38, 12.4), (-35, 3.0)]),
]
XDGA_P2 = [
    ('PD',   [(0, 33), (-1, 33), (-1, 24.5), (0, 24.5)]),
    ('D1',   [(0, 40), (38, 12.4), (32, -6.0), (4, 16), (0, 1.5)]),
    ('D2',   [(4, 16), (32, -6.0), (24, -30), (-1, -2)]),
    ('S',    [(0, 1.5), (0, 24.5), (-1, 24.5), (-1, 33), (0, 33), (0, 40), (-38, 12.4), (-35, 3.0)]),
    ('T3-H', [(-1, -2), (-3.5, -3.0), (2.5, -32.4), (23.3, -32.4), (24, -30)]),
    ('C',    [(2.5, -32.4), (-3.5, -3.0), (-11, -8), (-21.5, -32.4)]),
    ('O',    [(-21.5, -32.4), (-11, -8), (-3.5, -3.0), (-1, -2), (0, 1.5), (-35, 3.0), (-23.3, -32.4)]),
]


def compare(ours, xdga, n=200000, seed=42):
    random.seed(seed)
    agree = both = 0
    mism = {}
    for _ in range(n):
        g = {k: random.random() ** random.choice([0.5, 1, 2, 3]) for k in GASES}
        for k in GASES:
            if random.random() < 0.25:
                g[k] = 0
        if sum(g.values()) == 0:
            continue
        pt = point(g)
        a, b = classify(pt, ours), classify(pt, xdga)
        if a is None and b is None:
            continue
        both += 1
        if a == b:
            agree += 1
        else:
            key = f"{a} vs {b}"
            mism[key] = mism.get(key, 0) + 1
    print(f"  coinciden: {agree}/{both} ({100 * agree / both:.2f}%)")
    for k, v in sorted(mism.items(), key=lambda x: -x[1])[:8]:
        print(f"    {k}: {v} ({100 * v / both:.2f}%)")


if __name__ == '__main__':
    print("Pentagono 1 (ours vs xDGA):")
    compare(OURS_P1, XDGA_P1)
    print("Pentagono 2 (ours vs xDGA):")
    compare(OURS_P2, XDGA_P2, seed=7)
    print("Ejemplo publicado H2=40/C2H6=120/CH4=200/C2H4=40/C2H2=0 (esperado T1):")
    pt = point({'h2': 40, 'c2h6': 120, 'ch4': 200, 'c2h4': 40, 'c2h2': 0})
    print(f"  centroide ({pt[0]:.2f},{pt[1]:.2f}) -> ours={classify(pt, OURS_P1)} xdga={classify(pt, XDGA_P1)}")
