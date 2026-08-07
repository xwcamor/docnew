# Normas IEC vs IEEE en el sistema viejo (Ruby) — análisis y cómo escala el nuevo

> Respuesta a: "el antiguo programador hizo 2 normas IEC y IEEE… capaz estuvo
> mandrakeando y esto se pudo escalar mejor… ¿la tabla de condiciones tiene que
> ver?". Sí: tiene todo que ver. Acá está el análisis.

## 1. Qué hizo el Ruby con las dos normas

El viejo trataba **IEC 60599** e **IEEE C57.104** como **dos features paralelas y
separadas**, cada una con su propio árbol de controllers/vistas y sus límites
**clavados en el código** (mandrakeados). No había una abstracción de "norma";
había dos copias de casi todo.

### IEC 60599 (método de ratios + Duval)
- Controllers: `chromatographical_management/iec_graphs_controller.rb`,
  `duval_management/*` (triángulos y pentágonos).
- Es el método **gráfico/de relaciones de gases**: Duval (ya portado) + curvas de
  tendencia de gas. La "norma" acá es la geometría de las zonas.

### IEEE C57.104 (método de límites de concentración)
- Modelo `IeeeDiag` + `IeeeDiagDetail`, controller `ieee_diags_controller.rb`.
- Es el método de **Condición 1–4**: clasifica el transformador según los ppm de
  cada gas contra una tabla de límites (Tabla 1) y según la **tasa de generación**
  sobre varias muestras (Tabla 4). Por eso `IeeeDiag` exige seleccionar 3–6 ensayos.
- Los límites están **hardcodeados** en cadenas `if/elsif` enormes:
  - `app/models/ieee_diag.rb` → Tabla 4 (tasas: `if @period >= 4 && @period <= 9 … @gas = 100`).
  - `app/models/chromatographical.rb` → `str_diag_status` / `str_diag_status_detail`
    (líneas ~429–820): los límites de concentración por gas, **duplicados varias
    veces** con números distintos (por aceite / tipo de trafo). El mismo patrón
    mandrake que tenían las cromas (~180 métodos casi iguales).

**Conclusión**: sí, estuvo mandrakeando. Cada combinación norma × gas × aceite ×
tipo era un bloque de código nuevo. Agregar un aceite o ajustar un límite obligaba
a tocar el código en muchos lugares.

## 2. Cómo el sistema nuevo ya lo escala (la "tabla de condiciones")

El motor nuevo ya tiene la abstracción que faltaba. La cadena es:

```
tests → standards → variables → rule_sets → rules → rule_conditions → result_scales
```

- **`standards`** ES la norma. Ya están sembradas las dos:
  `iec_60599` (2015) e `ieee_c57_104` (2019) — ver `DiagnosticCatalogSeeder`.
- Un `rule_set` es "un cuadro" para (prueba + aceite + trafo + **norma**). O sea:
  la misma prueba bajo IEC y bajo IEEE son dos `rule_set` distintos apuntando a
  distinto `standard_id`. **Cero código nuevo**; es una fila.
- Los **límites** viven en `rule_conditions` (variable + operador + valor). Cambiar
  un umbral = editar un dato, no recompilar.

Esto es exactamente lo que el viejo no tenía. La estructura ya soporta N normas sin
mandrake.

## 3. Estado real hoy (honestidad)

- **Cromas**: las `rule_sets` actuales apuntan **solo a `iec_60599`** y usan el
  método de **scoring ponderado (DGAF)** de las "condiciones del sistema" (el Excel
  que aportaste), no el método IEEE de Condición 1–4. La fila `ieee_c57_104` existe
  en `standards` pero **todavía no tiene reglas** → el método IEEE de límites de
  concentración **no está implementado**.
- **Los límites no se ven** porque:
  - En cromas, los umbrales están dentro de `rule_conditions` (manejan el score)
    pero **no se muestran** en la UI; y la **tabla de límites IEEE C57.104** (la
    clásica que recordás) no está portada.
  - En fiquis, los límites (bandas `[t1,t2,t3]`) **sí existen** en
    `fiquis_rules.json`, pero la UI solo muestra el valor + semáforo, no la banda.

## 4. Recomendación

1. **Portar IEEE C57.104 como DATOS** (no mandrake): cargar la tabla de límites de
   concentración por gas (Condición 1–4) como `rule_set` con `standard_id =
   ieee_c57_104`, reutilizando el motor. Queda como una segunda norma seleccionable,
   sin tocar el código del motor. Usar la **tabla canónica de la norma 2019**, no los
   números mandrakeados del Ruby (que tenían versiones inconsistentes).
2. **Surfacear los límites en la UI** (cromas y fiquis): mostrar, junto a cada valor,
   la banda/umbral que aplica y dónde cae. En tendencias, dibujar las líneas de
   límite por gas.
3. **Duval con coordenadas canónicas IEC 60599** (reglas, no polígonos digitalizados).

### Reglas canónicas del Triángulo 1 (las "puntos cardinales" que buscabas)

Las zonas se definen por **líneas de porcentaje fijo** (esto ES la norma, vértices
derivables exactamente):

| Zona | Definición (en % de CH₄ / C₂H₄ / C₂H₂) |
|---|---|
| **PD** | %CH₄ ≥ 98 |
| **T1** | %C₂H₂ < 4 y %C₂H₄ < 20 (y no PD) |
| **T2** | %C₂H₂ < 4 y 20 ≤ %C₂H₄ < 50 |
| **T3** | %C₂H₂ < 15 y %C₂H₄ ≥ 50 |
| **D1** | %C₂H₄ < 23 y %C₂H₂ ≥ 13 |
| **D2** | 23 ≤ %C₂H₄ < 40 y %C₂H₂ ≥ 13 |
| **DT** | el resto (%C₂H₂ ≥ 4 no cubierto por D1/D2/T3) |

Líneas de corte: C₂H₂ = 4, 13, 15, 29 · C₂H₄ = 20, 23, 40, 50 · CH₄ = 98.

> Los **pentágonos** (Duval 1/2) tienen coordenadas publicadas en el paper de Duval
> & Lamarre 2014; conviene tomarlas de esa fuente verificada en vez de las
> digitalizadas del viejo. Pendiente de conseguir la fuente exacta.
