# Motor de diagnóstico

← [Índice](00-INDICE.md)

## El principio rector (NO romper)

El sistema viejo (Ruby 2019) tenía las condiciones "mandrakeadas": ~180 métodos
casi idénticos en `chromatographical.rb`, uno por combinación gas × aceite ×
tipo de trafo. El sistema nuevo las saca a **datos editables**: el código solo
tiene fórmulas; todo lo que puede cambiar (umbrales, pesos, aceites, normas,
criterios) vive en tablas o JSON de seeders. Si el instinto ante un pedido es
escribir un `if` nuevo en el motor: **detenerse** — casi siempre es una fila de
datos.

## La cadena de datos

`tests` → `standards` → `variables` → `rule_sets` (un cuadro por
prueba+aceite+trafo+norma) → `rules` (escalón: score+peso+prioridad) →
`rule_conditions` (variable+operador+valor; varias = AND) → `result_scales`
(semáforo: rango de score → condición+color+rating 0-4).

## Servicios (todos en `app/Services/Diagnostics/`)

| Servicio | Prueba | Datos editables |
|---|---|---|
| `ChromatographyEngine` | Cromatografía (DGAF, IEC 60599) | `cromas_rules.json` (234 reglas: mineral, silicona, vegetal_soya, vegetal_girasol; incl. horno) |
| `FiquisDiagnosisService` | Fisicoquímico (IEEE C57.106) | `fiquis_rules.json` (por aceite + clase de tensión; modos `scored` y `limit`+tol) |
| `FuranoDiagnosisService` | Furanos (2-FAL + DP Chendong) | reglas en BD |
| `FpotDiagnosisService` | Factor de potencia | habilitado en el HI (`hi_enabled=true`), peso 10 |
| `DuvalService` | Triángulos 1/4/5 + Pentágonos | `duval_zones.json` (canónico, ver abajo) |
| `RatioMethodsService` | Rogers / Doernenburg | `ratio_methods.json` |
| `IeeeDgaStatusService` | DGA Status 1/2/3 (IEEE C57.104-2019) | `ieee_c57104_tables123.json` + `table4` — **normativo, solo-super, no se tunea** |
| `HealthIndexService` | Índice de salud combinado (Hitachi) | pesos por prueba en `tests.hi_weight` (solo-super) |

Comando de prueba: `php artisan diagnose:cromas {id}`.
Recacheo de flota: `php artisan diagnose:fleet-cache`.

## Datos clave (de la BD real)

- **Aceites**: 1=Mineral, 4=Silicona, 5=Soya, 6=Girasol (ésteres = futuro).
- **Tipos de trafo**: 1=Potencia, 2=Distribución, 3=Horno.
- **Pesos por gas** (mineral/silicona): H2=2, CH4=3, C2H4=3, C2H6=3, CO=1,
  CO2=1, C2H2=5 → total 18. Vegetales: sin CO2 → total 17.
- **DGAF** = Σ(score×peso) / Σ(peso), con peso dinámico (no castiga gases
  faltantes). Semáforo: <1.2 Muy Bueno · 1.2–1.5 Bueno · 1.5–2 Medio ·
  2–3 Malo · ≥3 Muy Malo.
- **HI** (peso dinámico) = Σ(peso×rating)/Σ(peso×4)×100. Escala: >85 Muy Bueno ·
  70–85 Bueno · 50–70 Medio · 30–50 Malo · ≤30 Muy Malo.
- **Pesos del HI por prueba** (alineados 2026-07-16 a los criterios K de la
  metodología Hitachi, `Health_Index.pdf` → tabla "Formulación del índice de
  salud"): cromas **10** · fiquis **8** · furanos **6** · fpot **10**. Antes:
  10/6/5/5 sin fuente. Tras cambiar pesos en una instalación existente: correr
  `php artisan diagnose:fleet-cache`.
- **Dirección de ratings** (correcto, no es bug): en las pruebas el valor mide
  gravedad (sube=peor, rating 4→0); en el HI mide salud (sube=mejor, 0→4). El
  rating siempre significa lo mismo (4=mejor); solo se invierte el eje del valor.

## Decisiones cerradas (no re-litigar)

- **IEEE consolidado en 2019**: el "Condición 1–4" (edición 1991) se ELIMINÓ del
  código. `ieee_condition` en el cache de flota guarda el DGA Status 2019 (1–3).
  Tabla 1 (p90) + Tabla 2 (p95) por columna O₂/N₂ + edad.
- **Duval 100% canónico**: T1 por inecuaciones IEC 60599; T2/T3/T4/T5 del Excel
  oficial de Duval; pentágonos P1/P2/combinado cotejados vértice a vértice con
  los papers (Cheim/Duval/Haider 2020 y Duval&Lamarre 2014 — PDFs en la
  carpeta `origen-ruby/fuentes-originales/`, ver [procedencia](../origen-ruby/README.md)). xDGA implementa el
  2014; nosotros el 2020 (más nuevo).
- **Verificación contra el viejo CERRADA**: `php artisan verify:legacy` — 91.6%
  paridad de HI en los comparables; las discrepancias se investigaron a mano y
  en todas el motor nuevo aplica bien la norma (el viejo estaba mal). Ante
  dudas, validar contra las normas, no contra el Ruby.
- **Aceite sin reglas DGA no rompe el HI**: cae al DGA Status IEEE C57.104-2019
  (Status 1→Muy Bueno, 2→Medio, 3→Malo) en vez de excluir cromas (que daba
  falsos "100 Excelente").
- **Etiquetas de condición ancladas al rating** (0-4), no a la palabra; la
  palabra es presentación editable (dataset `condition_labels`, fuente única
  `ConditionLabel`).

## Personalización por tenant (Fase 3, completa)

Reglas híbridas: `tenant_id` nullable (null = global de fábrica). Resolvers
prefieren tenant→global. El super edita el GLOBAL; el admin del workspace
personaliza lo SUYO. Los cuadros de reglas usan copy-on-write ("restaurar"
borra el override). Pesos del HI, params fiqui e IEEE 2019 = solo global.
Editor en `/system_management/diagnostic-rules` (semáforos, datasets, colores
del semáforo y de celdas — con override por tenant).

## Trampas conocidas

- `fiquis_rules.json` modo `limit` lleva `tol` (±10%, heredado literal del
  Ruby): SOLO colorea la banda ámbar, no puntúa. El límite de norma es el valor
  base (ej. rigidez D877 mineral = 25 kV; el 27.5 es el borde de alerta).
- `diagnostics.cell_alert_sev` filtra solo el ámbar "acercándose al límite" en
  celdas; el rojo nunca se oculta.
- El pronóstico de flota es extrapolación de tendencia, NO predicción de falla.
