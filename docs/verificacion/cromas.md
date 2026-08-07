# Verificación de trazabilidad — Cromatografía (DGA)

> Objetivo: comprobar, con evidencia, que **todas** las reglas de cromatografía
> del sistema viejo (Ruby) están en el sistema nuevo y que **ningún umbral se
> alteró** en la migración.

Fecha: 2026-06-08 · Método reproducible (script abajo).

---

## Fuentes (cadena de procedencia)

| Eslabón | Archivo | Rol |
|---|---|---|
| Original (código) | `chromatographical.rb` (repo viejo `xwcamor/trapp`, ~180 métodos) | Fuente de verdad real |
| Original (datos) | `docs/origen-ruby/fuentes-originales/CONDICIONES_DEL_SISTEMA.xlsx` | Extracción cruda por aceite |
| Norma | `IEC_60599_Table_A2_A4_gas_concentration.xlsx` (A.2 potencia, A.4 Furnace) | Umbrales del escalón normal |
| Puente (reconciliado) | `TR_APP_Catalogo_Maestro_v2.xlsx` → hoja **"2. Score Cromas"** (198 escalones, columna Fuente = "código+Excel") | Reglas viejas ya verificadas código-vs-Excel |
| Sistema nuevo | `database/seeders/data/cromas_rules.json` (234 reglas) | Lo que corre hoy |

El catálogo puente ya trae una hoja **"9. Diferencias cod vs Excel"** que lista
las decisiones intencionales (IDs de aceite reales 1/4/5/6, F.Potencia
comentado, ésteres = plan futuro, IEEE Tabla 4 agregada).

---

## Resultado del diff exhaustivo (banda por banda)

Se comparó cada regla `(aceite, trafo, gas, score)` en sus 4 valores
(`desde`, `hasta`, `score`, `peso`) entre el JSON nuevo y la hoja "Score Cromas".

```
Excel "Score Cromas":  198 reglas
JSON nuevo:            234 reglas
─────────────────────────────────────────────
COINCIDEN EXACTAS:     198 / 198   (desde, hasta, score, peso)
MISMATCH:                0
Reglas perdidas
  (solo en Excel):       0
Reglas agregadas
  (solo en JSON):       36  → todas mineral/horno
```

**Conclusión: no se perdió ni se alteró ninguna regla del sistema viejo.**
Las 36 reglas nuevas son el tipo **Horno** (6 gases × 6 escalones), agregado
desde IEC 60599 Tabla A.4 (fila Furnace) — extensión documentada, no estaba en
el viejo.

### Desglose 234 = 198 + 36

| Aceite / Trafo | Escalones | Peso total | Gases |
|---|---|---|---|
| mineral / potencia | 42 | 18 | 7 (con C2H2) |
| mineral / distribución | 42 | 18 | 7 |
| silicona | 42 | 18 | 7 |
| vegetal soya / distribución | 36 | 17 | 6 (sin CO2) |
| vegetal girasol / distribución | 36 | 17 | 6 (sin CO2) |
| **subtotal original** | **198** | | = Excel exacto |
| mineral / horno *(nuevo)* | 36 | 13 | 6 (sin C2H2) |
| **total nuevo** | **234** | | |

### Escalón normal (score 1) vs IEC — verificado contra "4. Límites semáforo"

Los 20 umbrales del escalón normal (el corte normal/anormal, el más crítico)
coinciden exactamente: mineral potencia (H2=150, CH4=130, C2H4=280, C2H6=90,
CO=600, CO2=14000, C2H2=20), distribución (100/50/50/50/200/5000/5) y horno
(200/150/200/150/800/6000, sin C2H2).

---

## Caveat (lo único no cubierto por el catálogo viejo)

Las bandas **2 a 6 del tipo Horno** son construcción del sistema nuevo (el viejo
no tenía Horno). Su escalón 1 está verificado contra IEC A.4, pero los escalones
de severidad 2-6 no tienen contraparte en el catálogo original. **Recomendación:
validarlos contra trafos de horno reales** (ya está en pendientes). No afectan la
decisión normal/anormal, solo la magnitud del DGAF dentro de "anormal".

---

## Cómo reproducir

```python
import openpyxl, json
wb = openpyxl.load_workbook('docs/origen-ruby/diseno/TR_APP_Catalogo_Maestro_v2.xlsx', data_only=True)
ws = wb['2. Score Cromas']
oilmap={'Mineral':'mineral','Silicona':'silicona','Vegetal Soya':'vegetal_soya','Vegetal Girasol':'vegetal_girasol'}
trafomap={'Distribución':'distribucion','Potencia':'potencia','Horno':'horno',None:None}
num=lambda x: None if x in (None,'') else float(x)
excel=[]
for r in ws.iter_rows(min_row=4, values_only=True):
    oil,trafo,gas,desde,_,hasta,_,score,peso,_=(list(r)+[None]*10)[:10]
    if oil in oilmap:
        excel.append((oilmap[oil], trafomap.get(trafo), str(gas).lower(), num(desde), num(hasta), int(score), float(peso)))
new=json.load(open('database/seeders/data/cromas_rules.json'))
nf=lambda v:0.0 if v is None else float(v)
N={(x['oil'],x['trafo'],x['gas'],int(x['score'])):x for x in new}
mm=[k for (o,t,g,df,dt,s,w) in excel
    for k in [(o,t,g,s)]
    if k not in N or nf(N[k]['from'])!=nf(df) or N[k]['to']!=dt or float(N[k]['weight'])!=w]
print("mismatches:", mm)   # -> []
```
