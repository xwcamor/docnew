# Plan del sistema nuevo

## Decisión de partida

DOC APP 2 **no es un refactor de la v1**. Es un sistema nuevo en PHP/Laravel construido sobre
TRAFODEX como plataforma SaaS. De la v1 (Rails/MySQL) se migran los datos; el código no se reutiliza.

Lo que se hereda de TRAFODEX y no hay que volver a escribir:

| Del núcleo | Qué aporta |
| --- | --- |
| Multi-tenant por `tenant_id` + traits `BelongsToTenant` | varias empresas en una instalación |
| Spatie Permission + `system_modules` | permisos por módulo, roles `super`/`admin`/`user` |
| `make:module` | un módulo nuevo son ~51 archivos generados, no copiados a mano |
| Componentes Vue (`ResponsiveTable`, `FilterBar`, `ExportDialog`, `SavedViews`, `RecordHistory`) | listados, filtros, exportación e historial ya resueltos |
| `audit_logs` + trait `Auditable` | trazabilidad de cambios |
| `mcamara/laravel-localization` | multi-idioma con URLs por locale |
| Exportadores/importadores + jobs | Excel y Word |
| Sistema de diseño (paleta Fiori, 8 esquemas, modo oscuro) | la UI ya existe |

## Fases

### Fase 0 — Base ✅ terminada

Copiar TRAFODEX, quitar el dominio de transformadores, dejar el núcleo SaaS limpio y con la
identidad de DOC APP.

### Fase 1 — Esquema del dominio ✅ terminada

Las 5 migraciones de `database/migrations/2026_08_07_*`: organización, personas, planes de trabajo,
motor de formatos y evidencias. Ya escritas.

### Fase 2 — Módulos de catálogo ◐ en curso

`companies`, `work_locations`, `workstations`, `work_areas`, `positions`, `nationalities`,
`work_types`. Uno por uno con `make:module`. Son los más simples y validan el flujo completo.

### Fase 3 — Personas ✅ terminada (falta repasar sus pantallas)

`people` con sus vínculos por empresa, roles, biometría y firmas. Incluye la pantalla de alta que
busca primero por documento y, si la persona ya existe, solo crea el vínculo con la empresa nueva.

### Fase 4 — Planes de trabajo ✅ terminada (falta repasar sus pantallas)

`work_plans`, asignación de trabajadores, reglas y registro de aprobaciones.

### Fase 5 — Motor de formatos ◐ en curso

Editor de plantillas, tipos de campo básicos, tipo `upload_only` (la HOJA X que solo se fotografía)
y generación de PDF. Después los tipos compuestos: `person_checklist` (EPP), `tool_checklist` (IHM),
`risk_matrix` (AST/PTF), `question_bank` (PTF).

### Fase 6 — Firmas y biometría ◐ en curso

Verificación facial **en el servidor**, con captura por tiempo de espera: si no reconoce en 30
segundos, toma la foto igual, deja firmar y marca el evento para revisión. Bandeja de revisión para
el supervisor. El umbral se ajusta con las distancias reales que empiece a registrar el sistema.

### Fase 7 — Migración de datos y corte ○ sin empezar

Ver `docs/MIGRACION.md`.

## Lo que se elimina del dominio heredado

Modelos, migraciones, páginas y rutas de: transformadores, muestras, cromatografía, fisicoquímicos
(fiqui), furanos, FPOT, laboratorios, tap changers, motor de reglas de diagnóstico, escalas de
resultado, informes de diagnóstico y automatizaciones asociadas.

Se conservan como referencia hasta que exista un módulo propio equivalente: `Brand` y `Customer`,
que son las plantillas que usa `make:module`.


---

## Dónde estamos (07-08-2026)

| Fase | Estado | Qué falta |
| --- | --- | --- |
| 0 · Base SaaS | ✅ | — |
| 1 · Esquema | ✅ | — |
| 2 · Catálogos | ◐ | faltan los módulos de sedes, puestos, áreas, cargos, nacionalidades y tipos de trabajo |
| 3 · Personas | ✅ | repasar las pantallas heredadas de la plantilla |
| 4 · Planes | ✅ | ídem |
| 5 · Formatos | ◐ | tipos de campo compuestos (matriz de riesgo, checklists) y el editor de plantillas |
| 6 · Firmas | ◐ | reto de vida, enrolamiento desde la app y PDF firmado |
| 7 · Migración | ○ | los 14 pasos de `docs/MIGRACION.md` |

**Lo que ya se puede hacer de punta a punta**: crear el plan del día, añadir la cuadrilla, llenar un
formato con campos o subir la foto de una HOJA X, firmar con la cámara (con captura por tiempo de
espera cuando no reconoce) y resolver la bandeja de firmas pendientes.
