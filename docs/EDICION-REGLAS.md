# Diseño: UI de edición de reglas de diagnóstico

> **Estado**: IMPLEMENTADO (el diseño de este documento se construyó — editor en
> `/system_management/diagnostic-rules`, con overrides por tenant además del
> global). Se conserva como documento de diseño/razonamiento.
> El recálculo de la caché de flota tras guardar es AUTOMÁTICO (job
> `RecalculateFleetCache` en queue, scoped por quien edita); el comando
> `php artisan diagnose:fleet-cache` queda como respaldo manual
> (ver `docs/DEPLOY.md`).

## Objetivo

Editar desde la interfaz los **datos que gobiernan el motor de diagnóstico**
(umbrales, colores, condiciones, pesos), que hoy solo se cambian editando JSON /
seeders. Es la materialización del principio del proyecto: *"el código solo tiene
fórmulas; todo lo que puede cambiar vive en datos"* — pero hoy esos datos no
tienen UI.

**Quién**: super (y opcionalmente admin con permiso). Es una pantalla **peligrosa**
(cambia cómo se diagnostica TODO), así que: permiso dedicado + auditoría + validación.

## Qué es editable (recapitulación del modelo)

```
tests        (prueba: code, name, hi_weight, hi_enabled)
standards    (norma: code, name)
variables    (gas/parámetro: code, name, unit, kind)
rule_sets    (un "cuadro" por test+aceite+trafo+norma: label, total_weight, is_active)
rules        (escalón: variable, score, weight, priority, result_label)
rule_conditions (variable, operator, value; varias = AND)
result_scales (semáforo: score_from/to, condition_label, color, hi_rating, sort_order)
```

De más simple/valioso a más complejo/riesgoso:
1. **Semáforo** (`result_scales`): las bandas score→condición/color/rating. Es lo que
   más cambia (lo que estuvimos editando a mano para furanos/cromas).
2. **Pesos del Índice de Salud** (`tests.hi_weight`, `tests.hi_enabled`).
3. **Pesos por gas** (`variables` / `rules.weight`).
4. **Cuadros de reglas** (`rules` + `rule_conditions` por `rule_set`): la matriz de
   ~234 reglas de cromas, fiquis, etc. Lo más potente y delicado.

## Fases

### Fase 1 — Editor del semáforo + pesos del HI (núcleo, bounded)
- Pantalla "Reglas de diagnóstico" → por prueba (cromas/furanos/fiquis/fpot/HI):
  - Tabla editable de **bandas** (`result_scales`): desde/hasta, condición, color
    (selector de los 5 tokens), rating 0-4, orden.
  - Edición de **hi_weight** y **hi_enabled** de cada prueba (cuánto pesa en el HI).
- **Validación** antes de guardar:
  - Bandas ordenadas y **contiguas** (sin huecos ni solapes); primera `from=null`
    (−∞), última `to=null` (+∞).
  - rating ∈ {0..4}; color ∈ tokens válidos; condición no vacía.
- **Auditoría** de cada cambio (quién, antes/después) — reusar el trait Auditable.
- **Preview opcional**: ingresar un valor de prueba y ver en qué banda cae con los
  cambios sin guardar (ej. "2-FAL = 1.5 ppm → Medio").
- **Restaurar defaults**: botón para volver a los valores del seeder/JSON.

Con esto, lo que hicimos a mano (mover bandas de furanos, cambiar colores) se hace
desde la UI, con validación y auditoría.

### Fase 2 — Editor de cuadros de reglas (`rules` + `rule_conditions`) ✅ HECHA
- Lista de `rule_sets` (`/system_management/diagnostic-rules/sets`) con filtro por
  prueba y buscador: muestra prueba + aceite + trafo + norma + cantidad de reglas.
- Editor de un cuadro (`/sets/{ruleSet}`): meta del cuadro (etiqueta, peso total,
  activo) + tabla de **reglas** (variable, condiciones operador+valor en AND, score,
  peso, prioridad, etiqueta, activa). Agregar/editar/eliminar reglas y condiciones.
- Guardado = **reemplazo total** en transacción (borra reglas+condiciones del cuadro
  y recrea), validado server-side (operadores válidos, variables existentes, ≥1
  condición por regla) y **auditado** (foto antes/después).
- Controller: `DiagnosticRulesController@sets/editSet/updateSet`. Páginas Vue:
  `DiagnosticRules/Sets.vue`, `DiagnosticRules/SetEdit.vue`. Tests en
  `DiagnosticRulesTest` (list/edit/update/validación).
- Pendiente (no bloqueante): no se fuerza que los pesos sumen `total_weight` (el
  motor usa **peso dinámico**, así que la suma exacta no es requisito).

### Fase 3 — Catálogos y avanzado
- `variables` (pesos por gas, unidades), `standards`, crear `rule_sets` nuevos.
- Duplicar un cuadro a otro aceite/trafo. Import/export del cuadro (JSON).

## Dónde vive / navegación

- Módulo nuevo **"Reglas de diagnóstico"** en *System Management* (donde viven las
  cosas de configuración), visible solo para super (o con permiso
  `diagnostic_rules.view/edit`).
- Sidebar: entrada manual (como todo módulo).

## Seguridad y consistencia

- **Permiso dedicado** (`diagnostic_rules.edit`); por defecto solo super.
- **Auditoría** completa (es config crítica).
- **Validación fuerte** server-side (no romper el motor con bandas inconsistentes).
- **Relación con los seeders/JSON**: los JSON (`cromas_rules.json`, etc.) siguen
  siendo los **defaults de fábrica** (fresh install). La UI edita la **BD viva**.
  Editar en UI hace que la BD diverja del JSON — está bien (la BD es la fuente de
  verdad post-install). "Restaurar defaults" re-aplica el seeder.
- **Caché**: si el motor cachea reglas, invalidar al guardar (hoy no cachea —
  decisión "NO Redis", lee de BD con índices).

## Preguntas abiertas (para definir antes de Fase 1)

1. **Acceso**: ¿solo super, o admin con permiso? (sugiero: super, y permiso
   opcional para admin del tenant — aunque las reglas son **globales**, no
   per-tenant: un cambio afecta a TODOS los workspaces. ⚠️ esto hay que decidirlo:
   ¿las reglas se vuelven per-tenant o siguen globales y solo super las toca?)
2. **Per-tenant vs global**: hoy `result_scales`/`rules` son **globales** (sin
   tenant). Si un cliente quiere sus propios umbrales, habría que hacerlas
   per-tenant (cambio de modelo grande). Para Fase 1 propongo **mantenerlas
   globales y solo super** las edita.
3. **Preview en vivo**: ¿lo querés en Fase 1 o alcanza con validación?
4. **Restaurar defaults**: ¿por prueba, o global?

## Recomendación

Arrancar por la **Fase 1** (editor de semáforo + pesos del HI): es lo más usado,
acotado, con validación y auditoría, y resuelve el 80% de los ajustes reales sin
meterse en la matriz densa de reglas. Las Fases 2-3 cuando haga falta tocar los
cuadros.
