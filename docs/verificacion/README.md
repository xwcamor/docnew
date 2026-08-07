# Verificación de trazabilidad: sistema viejo (Ruby) → sistema nuevo (Laravel)

Auditoría regla-por-regla de que el motor nuevo reproduce el viejo. Método:
diff programático entre el catálogo puente (`docs/origen-ruby`) y los datos del
sistema nuevo (`database/seeders/data/*.json`). Reproducible (scripts en cada doc).

## Estado por módulo

| Módulo | Resultado | Detalle |
|---|---|---|
| **Cromas** | ✅ EXACTO | 198/198 reglas idénticas, 0 perdidas. +36 reglas nuevas (Horno, IEC A.4). [cromas.md](cromas.md) |
| **Fiquis** | ✅ EXACTO + 1 decisión | 19 umbrales viejos idénticos (clase baja). Mejora: clases de tensión IEEE C57.106. Decisión: agua/pot no se separan por clase. [fiquis.md](fiquis.md) |
| **Furanos** | ⚠ DIVERGE (intencional) | DP y semáforo reconstruidos sobre Chendong; más optimista que el viejo. Requiere visto bueno. [furanos.md](furanos.md) |
| Duval, IEEE C57.104, Key Gas | ➕ NUEVOS | No tienen contraparte en el viejo (agregados desde norma). Verificar aparte. |

### Lo único que requiere tu decisión
1. **Furanos**: ¿Chendong (nuevo, optimista) o umbrales del `furano.rb` viejo
   (conservador)? — cambia diagnósticos de envejecimiento de papel.
2. **Fiquis agua/pot**: ¿uniformes (fiel al viejo) o por clase de tensión (IEEE
   C57.106 completo)?
3. **Cromas Horno bandas 2-6**: validar contra trafos de horno reales (escalón 1
   ya verificado contra IEC A.4).

---

## ¿Es escalable? (verificado en código, no de palabra)

### Las reglas van por tipo de aceite. ¿Si creo otro aceite se rompe todo?
**No.** El motor resuelve las reglas por `oil_type_id`
(`ChromatographyEngine::resolveRuleSet`). Cada aceite tiene su propio juego de
reglas, aislado de los demás. Si agregás un aceite nuevo:
- Los aceites existentes **no se tocan** (cada uno es independiente).
- El aceite nuevo simplemente **no tiene reglas todavía** → el motor devuelve
  "—" (sin diagnóstico), **no se cae**. Degradación elegante.
- Para que diagnostique, cargás su juego de reglas (DATOS), sin tocar código.

### Fiquis: ¿si agrego otra columna (variable) en el futuro?
Hay que separar tres capas — NO todas son auto-servicio hoy:

| Capa | ¿Escala por datos? | Estado UI |
|---|---|---|
| Motor de scoring | ✅ itera `foreach (param_order)`, no hardcodea la lista | — |
| Umbrales de un parámetro existente | ✅ son datos (`params`/`tables`) | ✅ editor `DataEdit.vue` (solo números) |
| Agregar una variable MEDIDA nueva (ej. color) | ❌ no es solo datos | ❌ NO hay UI |

El motor es data-driven, pero la tabla de muestras `fiquis` usa **columnas
fijas** (`rig`, `ten`, `acid`, `wat`, `pot`, `pot100`, `rig877`). Agregar una
variable medida nueva (color, etc.) hoy requiere: (1) migración → columna nueva,
(2) campo en el formulario de carga, (3) fila en `params`/`tables` del JSON,
(4) umbrales. Precedente: `pot100` y `rig877` se agregaron con **migración +
código** (`2026_06_07_130000_add_extra_params_to_fiquis_table.php`), NO desde el
módulo.

El editor `DataEdit.vue` **solo ajusta los números** de los parámetros
existentes — no tiene "agregar/quitar variable". (Cromas sí tiene editor de
reglas/condiciones en `SetEdit.vue` porque vive en tablas `rule_sets`;
fiquis/furanos/ieee viven en JSON con el editor simple.)

### Conclusión honesta
- **Aceites nuevos**: escala por datos (cada aceite es un juego aislado; uno sin
  reglas devuelve "—", no rompe nada).
- **Umbrales**: escalan por datos y se editan desde la UI.
- **Variable medida nueva (columna)**: hoy es tarea de **desarrollador**
  (migración + formulario + config), NO auto-servicio. Para que un
  diagnosticador agregue "color" solo desde el módulo haría falta (a) un botón
  "agregar parámetro" en el editor y (b) cambiar el almacenamiento de muestras de
  columnas fijas a estructura flexible (JSON/clave-valor) — decisión de
  arquitectura pendiente.
