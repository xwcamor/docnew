# Verificación de trazabilidad — Furanos

Fecha: 2026-06-08. Fuente: `CONDICIONES_DEL_SISTEMA.xlsx` hoja **"Furano"** +
código viejo `furano.rb`. Sistema nuevo: `FuranoDiagnosisService` +
escala sembrada en `DiagnosticCatalogSeeder`.

## ⚠⚠ HALLAZGO PRINCIPAL — el sistema nuevo DIFIERE del viejo (intencional, pero hay que aprobarlo)

A diferencia de cromas y fiquis (donde todo coincide), furanos fue **reconstruido
sobre la fórmula de Chendong**, departiendo a propósito del `furano.rb` viejo.
Esto cambia diagnósticos de forma material. Está documentado en el seeder
(`DiagnosticCatalogSeeder`, líneas ~125-135), pero requiere tu visto bueno.

### 1. Estimación del DP (grado de polimerización)

| 2-FAL (ppm) | Viejo (tabla 3 puntos) | Nuevo (Chendong) | Diferencia |
|---|---|---|---|
| 0.13 | DP 600 | DP 685 | +85 |
| 0.20 | DP 500 | DP 631 | +131 |
| 0.50 | **DP 200 (fin de vida)** | **DP 517 (sano)** | **+317** |

Fórmula nueva: `DP = (1.51 − log10(FAL_ppm)) / 0.0035` (Chendong 1992).
A 0.5 ppm el viejo declaraba el papel en **fin de vida** (DP 200); el nuevo lo
da **sano** (DP 517). El nuevo es mucho más **optimista** sobre el envejecimiento.

### 2. Semáforo furano (ppm → rating del Índice de Salud)

| Furano (ppm) | Viejo (HIFj) | Nuevo (Chendong) |
|---|---|---|
| < 0.1 | Muy Bueno (4) | Muy Bueno (4) |
| 0.1 – 0.25 | Bueno (3) | Bueno (3) *(hasta 0.5)* |
| 0.25 – 0.5 | Medio (2) | Bueno (3) |
| 0.5 – 1.0 | Malo (1) | Medio (2) *(hasta 2.0)* |
| 1.0 | **Muy Malo (0)** | **Medio (2)** |
| ≥ 1.0 | Muy Malo (0) | Malo (1) hasta 6, luego Muy Malo |

El viejo **saturaba**: todo ≥1 ppm = Muy Malo. El nuevo extiende la escala
(Muy Malo recién en ≥6 ppm). Resultado: el nuevo es **2 ratings más indulgente**
a 1 ppm.

## Por qué se hizo así (rationale documentado)

El `furano.rb` viejo saturaba la escala (cualquier valor ≥1 ppm caía en el peor
nivel), lo que en la práctica volvía la prueba poco discriminante. El sistema
nuevo se ancla a la relación furano→DP de **Chendong** (norma publicada y
citada), que es continua y distingue mejor los grados intermedios.

## Lo que SÍ se preservó

- Conversión `fal` ppb → ppm (÷1000).
- El 2-FAL como furano que diagnostica.
- La advertencia de papel `upgraded` (termoestabilizado): 2-FAL subestima la
  degradación en ese papel.

## DECISIÓN PENDIENTE (catastrophic stakes)

Esto NO es un bug ni una regla perdida: es un **cambio de metodología deliberado**.
Pero como subdiagnostica el envejecimiento del papel respecto al sistema viejo,
hay que decidir explícitamente:

- **(a)** Mantener Chendong (más preciso académicamente, más optimista). ó
- **(b)** Volver a los umbrales del `furano.rb` viejo (más conservador, satura). ó
- **(c)** Híbrido: Chendong para el DP, pero semáforo conservador.

Recomendación: validar contra transformadores reales con DP medido por
laboratorio antes de fijar. Mientras tanto, dejar el aviso visible en el informe
de que el DP por 2-FAL es una estimación (ya está).

---

## ✅ DECISIÓN TOMADA (2026-06-12) — Híbrido conservador

Se adoptó el esquema **híbrido**:

- **Veredicto (semáforo / rating del HI)**: bandas **CONSERVADORAS del sistema
  viejo** (furano.rb): <0.1 Muy Bueno · 0.1–0.25 Bueno · 0.25–0.5 Medio ·
  0.5–1.0 Malo · **≥1.0 ppm Muy Malo**. Razón: la empresa certificó diagnósticos
  por años con este criterio; Chendong suavizaba 16 trafos críticos reales
  (sub-diagnóstico inaceptable).
- **DP estimado**: sigue calculándose con **Chendong** (CIGRE TB 494 /
  IEEE C57.91) como dato informativo en UI y reportes. No afecta el veredicto.

Implementación: solo datos (`DiagnosticCatalogSeeder` → `result_scales` del
test furanos). El motor no cambió. Impacto medido al recalcular: de 1,064
trafos con furanos, **29 bajaron de banda** (quedaron correctamente marcados
más graves). Editable desde datos si en el futuro se decide adoptar Chendong
pleno — que sea decisión explícita, no accidente de migración.
