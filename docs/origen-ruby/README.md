# Origen del sistema — de Ruby on Rails a Laravel

Esta carpeta documenta **de dónde salió** el sistema de diagnóstico de
transformadores (TR APP) y **cómo se creó**. No es código: es la trazabilidad
del proyecto, para que cualquiera entienda en qué se basó cada regla y cada
decisión.

El sistema viejo era una app **Ruby on Rails (2019)** que tenía las reglas de
diagnóstico "mandrakeadas" (clavadas en el código, ~180 métodos repetidos en
`chromatographical.rb`). El sistema nuevo (este repo, Laravel 13) reconstruye
ese motor con las reglas como **datos editables** en tablas.

- Repo del sistema viejo (Ruby, fuente de verdad real): https://github.com/xwcamor/trapp
- Modelo clave del viejo: `app/models/chromatographical.rb` (2822 líneas).

---

## Estructura

```
docs/origen-ruby/
  README.md                  <- este archivo
  fuentes-originales/        <- archivos que se usaron para construir el sistema
  diseno/                    <- documentos de diseño generados a partir de ellos
  imagenes/                  <- capturas / tablas de norma (se agregan a mano)
```

---

## `fuentes-originales/` — los insumos crudos

Estos son los archivos que el usuario aportó al inicio del proyecto. De aquí
salieron las reglas y los umbrales que hoy viven en
`database/seeders/data/cromas_rules.json`.

| Archivo | Qué es | Para qué se usó |
|---|---|---|
| `CONDICIONES_DEL_SISTEMA.xlsx` | Extracción cruda de las condiciones del código Ruby viejo (score por gas, por aceite y tipo de trafo). Hojas: Mineral, Silicona, Ésteres, Vegetal Soya/Girasol, Furano, Índice Salud, F.Potencia, Diagnósticos. | Fuente directa de los umbrales de cromatografía cargados en el seeder. |
| `Health_Index.pdf` | Metodología del Índice de Salud (Hitachi Energy). | Base de la fórmula HI = Σ(peso×rating) / Σ(peso×4) × 100 con peso dinámico. |
| `LIMITES_Aceite_Vegetal.docx` | Límites para aceite vegetal. | Referencia de los rangos de los aceites vegetales (soya/girasol). |
| `Interpretacion_CO_y_CO2.xls` | Interpretación de CO y CO₂. | Referencia para el análisis de monóxido/dióxido de carbono. |
| `IEC_60599_Table_A2_A4_gas_concentration.xlsx` | Tablas **IEC 60599 A.2 y A.4** (rangos del 90 % de valores típicos de gases). A.2 = transformadores de potencia; A.4 = ejemplos por subtipo (Furnace/Distribution/Submersible). | Fuente normativa de los umbrales del escalón 1 (condición "normal") en aceite mineral. La fila **Furnace** de la A.4 es la base del tipo Horno. |
| `DuvalTriangles1_729Mar2016.xls` | Hoja de cálculo del **Triángulo 1 de Duval** (Mar 2016): proyección baricéntrica de %CH₄/%C₂H₄/%C₂H₂ y clasificación por zona (PD/T1/T2/T3/DT/D1/D2). | Referencia/validación del Triángulo 1 (`DuvalService` + `duval_zones.json`). El T1 ya es canónico (inecuaciones IEC 60599: cortes C₂H₂=4/13/15, C₂H₄=20/50). Sirve para contrastar resultados. |
| `tr_app_development_schema.sql` | **Esquema** de la base de datos del sistema viejo Ruby (solo `CREATE TABLE`, sin datos). 58 tablas reales + 25 vistas basura. | Mapeo tabla-vieja → tabla-nueva (ver `diseno/TR_APP_Plan_Migracion.docx`). |

> Nota de seguridad: `tr_app_development_schema.sql` es **solo estructura** (DDL).
> No contiene `INSERT`, ni contraseñas, ni datos de clientes. La columna
> `real_password` (texto plano) del sistema viejo se descarta en la migración;
> Laravel usa hash bcrypt.

---

## `diseno/` — los documentos de diseño

Generados a partir de las fuentes de arriba + lectura del código Ruby. Fijan el
plan y las decisiones antes de escribir código.

| Archivo | Contenido |
|---|---|
| `LEEME_PRIMERO.md` | Cómo usar el paquete de contexto y el estado del proyecto. |
| `TR_APP_Plan_Migracion.docx` | Mapa de ruta: las 5 pruebas, problemas del viejo, mapeo tabla por tabla, plan por fases. |
| `TR_APP_Arquitectura_Tecnica.docx` | BD, motor de reglas, escalabilidad, normas, estado de implementación. |
| `TR_APP_Decisiones_Diseno.docx` | Las decisiones fijadas (pesos HI, IDs de aceite, peso dinámico, etc.). |
| `TR_APP_Catalogo_Maestro_v2.xlsx` | Detalle completo: 198 reglas, normas, IEEE Tabla 4, factor de potencia, HI, 20 criterios Hitachi. |
| `TR_APP_Catalogo_Condiciones.xlsx` | Extracción intermedia de condiciones. |

---

## `imagenes/` — referencias visuales

Carpeta para capturas del sistema viejo y reportes. Las tablas IEC 60599 A.2/A.4
ya están versionadas en formato editable en
`fuentes-originales/IEC_60599_Table_A2_A4_gas_concentration.xlsx` (antes solo
existían como imágenes pegadas en el chat).

Estas dos tablas son la fuente normativa de los umbrales del **escalón 1**
(condición "normal") de cada gas en aceite mineral:

- **mineral/potencia** (de Table A.2, "All transformers" + "No OLTC"): H₂≤150,
  CH₄≤130, C₂H₄≤280, C₂H₆≤90, CO≤600, CO₂≤14000, C₂H₂≤20.
- **mineral/distribución** (de Table A.4, fila "Distribution"): H₂≤100, CO≤200,
  CO₂≤5000, CH₄≤50, C₂H₆≤50, C₂H₄≤50, C₂H₂≤5.
- **mineral/horno** (de Table A.4, fila "Furnace"): H₂≤200, CH₄≤150, C₂H₄≤200,
  CO≤800, CO₂≤6000 y **sin acetileno** (la nota "a" de la A.4 dice que para el
  OLTC no hay valor significativo de acetileno — por eso el horno excluye C₂H₂).

---

## Hallazgos de coherencia (verificados contra el código Ruby)

1. **Las 198 reglas de cromas** del repo coinciden exactamente con
   `CONDICIONES_DEL_SISTEMA.xlsx` y con el catálogo maestro, para mineral
   (potencia/distribución), silicona y vegetales (soya/girasol).
2. **Horno mineral**: el código Ruby (`chromatographical.rb`, "CALCULOS DE
   TRANSFORMADOR DE HORNO") sí lo diagnosticaba (`transformer_type_id=3`), pero
   la extracción a Excel lo omitió. Se reincorporó al seeder (36 reglas, sin
   acetileno, peso dinámico). Ver `tests/Feature/Diagnostics/ChromatographyHornoTest.php`.
3. **Índice de salud viejo**: dividía por un denominador fijo (bug). El nuevo
   usa peso dinámico: solo entran las pruebas con datos.
