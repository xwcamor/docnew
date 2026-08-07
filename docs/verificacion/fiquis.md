# Verificación de trazabilidad — Fisicoquímico (FIQUIS)

Fecha: 2026-06-08. Fuente puente: `TR_APP_Catalogo_Maestro_v2.xlsx` hoja
**"3. Score Físicas"** (columna Fuente = "código+Excel") + `CONDICIONES_DEL_SISTEMA.xlsx`.
Sistema nuevo: `database/seeders/data/fiquis_rules.json`.

## Resultado

**Los 19 umbrales puntuados del sistema viejo se preservan EXACTOS** como la
clase de tensión BAJA (≤69 kV) del sistema nuevo:

| Aceite | rig | ten | acid | wat | pot | ¿coincide? |
|---|---|---|---|---|---|---|
| mineral | 40/35/30 | 25/20/15 | 0.05/0.1/0.2 | 20/30/40 | 0.1/0.5/1 | ✓ exacto |
| silicona | 25/22/20 | (no puntúa) | 0.2/0.4/0.8 | 100/150/200 | 0.2/0.4/0.8 | ✓ exacto |
| vegetal soya | 40/35/30 | 10/8/6 | 0.5/1/1.5 | 450/500/550 | 3/3.5/4 | ✓ exacto |
| vegetal girasol | 40/35/30 | 10/8/6 | 0.5/1/1.5 | 450/500/550 | 3/3.5/4 | ✓ exacto |

Pesos: rig=3, ten=2, acid=1, wat=4, pot=3 — idénticos al viejo.

## Mejoras del sistema nuevo (intencionales, IEEE C57.106)

1. **Clases de tensión.** El viejo usaba UN solo juego por aceite ("según
   tensión", sin separar). El nuevo agrega clases **media (69-230 kV)** y **alta
   (≥230 kV)** refinando rig/ten/acid por IEEE C57.106 (el 1er umbral coincide
   con la tabla "Límites semáforo": rig 40/47/50, ten 25/30/32, acid 0.2/0.15/0.1).
   La clase baja == el juego viejo, así que es retro-compatible.
2. **Dos parámetros nuevos solo-límite** (no puntúan, solo colorean): `rig877`
   (ASTM D877) y `pot100` (ASTM D924 a 100 °C). Aditivos, no estaban en el viejo.

## ⚠ Punto a decidir (no es regla perdida, es consistencia de norma)

El refinamiento por clase de tensión se aplicó a **rig/ten/acid** pero **NO a
agua ni factor de potencia**: `wat` y `pot` quedan idénticos en las 3 clases
(agua 20/30/40, pot 0.1/0.5/1 para mineral).

- IEEE C57.106 **sí** define límites de humedad por clase de tensión
  (35/25/20 ppm para ≤69 / 69-230 / ≥230 kV).
- Consecuencia: para un transformador de ALTA tensión, el sistema nuevo es más
  **permisivo** con la humedad que el límite IEEE (40 ppm = score 3, cuando IEEE
  pone el límite en 20 ppm).
- Respecto al sistema VIEJO no hay regresión (el viejo tampoco separaba agua).
  Pero si la intención es "IEEE C57.106 completo por clase", agua y pot deberían
  separarse también.

**Decisión pendiente del diagnosticador**: ¿dejar agua/pot uniformes (fiel al
código viejo) o separarlos por clase de tensión (IEEE C57.106 completo)?
Cambiar umbrales de seguridad requiere tu visto bueno explícito.

## Nota de parsing

Las filas de Silicona en "Score Físicas" traen filas basura intercaladas
(`2 | ≤30`, `2 | ≤3000`) que confunden un parser automático, pero leídas a mano
coinciden con el JSON. Silicona no puntúa tensión interfacial (fila incompleta
en el original), y el JSON correctamente la omite.
