# Verificación de la importación legacy — flujo de diagnóstico e incongruencias

> Generado al revisar el trafo #167 (L240383-01) y el conjunto importado.
> Responde: ¿se importan diagnósticos viejos o los calcula el sistema nuevo?

## 1. El flujo: NO se importa ningún diagnóstico viejo (PROBADO)

Los tres seeders (`LegacyChromatographicalsSeeder`, `LegacyPhysicalsSeeder`,
`LegacyFuranosSeeder`) insertan **solo los valores crudos** (gases / parámetros /
compuestos furánicos) + fecha. El `diag_status` viejo se parsea para alinear las
tuplas pero **NO se inserta**. Las columnas de diagnóstico-cache quedan en NULL.

Prueba directa sobre el trafo #167 (recién seedeado, sin tocar la UI):

```
CACHE en BD (lo que el seeder dejó):
  cromas:  dgaf_score=NULL  dgaf_condition=NULL
  fiqui:   score=NULL  rating=NULL  condition=NULL
  furano:  dp=NULL  rating=NULL  condition=NULL

MOTOR en vivo (lo que se muestra en pantalla):
  cromas live: condition='Muy Bueno'  score=1.0
  fiqui live:  condition='Bueno'      score=1.23
  furano live: condition='Bueno'      dp=694
```

**Conclusión:** el cache está vacío; todo lo que ves (Excellent / Good / DP 694…)
lo calcula el **motor nuevo en vivo** desde las mediciones crudas. No hay
override con diagnósticos viejos. La pantalla NUNCA lee el cache.

## 2. Las incongruencias están en los DATOS de origen, no en el cálculo

El motor calcula bien; lo que trae ruido es la calidad del dump viejo.

### a) Muestras DUPLICADAS (lo más grande)

El dump viejo trae la misma muestra cargada dos veces (mismo trafo + misma fecha,
normalmente una a las `00:00` y otra a las `05:00`, con valores idénticos).

| Tabla | Grupos con duplicado | Filas extra | % aprox |
|-------|----------------------|-------------|---------|
| chromatographicals | 2048 | 2365 | ~18% |
| fiquis             | 1390 | 1425 | ~16% |
| furanos            | 562  | 591  | ~19% |

En furanos, de 562 grupos duplicados **535 tienen valores idénticos** (doble
tipeo) y solo **27 difieren** (ambiguos: dos mediciones distintas el mismo día).

Impacto: ensucia el historial y las tendencias; rara vez cambia el diagnóstico
(se usa la última muestra, y los duplicados idénticos no mueven nada). Los 27 que
difieren sí pueden alterar la "última muestra" del día.

**Propuesta:** deduplicar en el seeder por `transformer_id + DATE(sample_date)`.
Para grupos idénticos, quedarse con uno. Para los que difieren, quedarse con el de
hora más tardía (o el de más campos no-nulos). Pendiente de tu OK.

### b) Salto de 2-FAL (dato real del viejo)

Trafo 167: 2FAL 8 ppb (2023-11-10) → 122 ppb (2024-05-07). Salto x15 en 6 meses.
Está así en el dump viejo (ids 1205 y 1493). El motor calcula el DP correctamente
(8→DP 1031; 122→DP 692). Es calidad de dato de origen, no un bug del motor.
Puede ser un evento real o un error de unidades/carga del viejo.

### c) `rig877 = 0` (no medido cargado como 0)

Las muestras 2018-2022 traen `rig877 = NULL`; las 2023-2025 traen `rig877 = 0`.
Una rigidez dieléctrica D877 de 0 kV es físicamente imposible (sería aceite
conductor) → es claramente "no medido" cargado como 0. La UI lo pinta ROJO como
si fuera catastrófico. `rig877` es parámetro EXTRA (no puntúa), así que no afecta
el diagnóstico, pero confunde visualmente.

**Propuesta:** tratar `rig877 = 0` como NULL (no medido) al importar, o no
colorear el 0. Pendiente de tu OK.

### d) `pot100 ≈ 6.5%` alto pero NO puntúa

El factor de potencia a 100 °C del trafo 167 ronda 6.5% (alto). Hoy el sistema
puntúa `pot` (25 °C ≈ 0.36%) y deja `pot100` como EXTRA sin puntuar. En la
práctica de aceites, el FP a 100 °C suele ser el más diagnóstico. Posible brecha:
quizá `pot100` debería puntuar (o reemplazar a `pot25`). **Decisión de dominio.**

## 3. Qué necesita tu decisión

1. Deduplicar las muestras importadas (estrategia propuesta arriba).
2. `rig877 = 0` → tratar como no-medido.
3. `pot100` → ¿debe puntuar en fiquis?

Ninguna se aplicó: tocan datos de diagnóstico de trafos reales (stakes altos).

---

## ✅ DECISIÓN (2026-06-12) — Trafos "huérfanos" se quedan VISIBLES

La brecha 2424 (nuevo) vs 2377 (viejo) son ~46 trafos vivos colgando de
subestación/cliente borrado, que el viejo OCULTABA. Perfil medido: 36 de 45
tienen muestras de ensayos reales y **3 están EN RIESGO (Malo/Muy Malo)**.

Decisión: NO ocultarlos. Esconder equipos activos con historial de diagnóstico
porque su registro de subestación fue borrado es un artefacto de inventario,
no una razón para dejar de monitorearlos — exactamente así se pierden de vista
trafos en riesgo (el panel de flota existe para lo contrario). El viejo
ocultaba 3 trafos en riesgo; el nuevo los muestra. Cero cambio de código.
