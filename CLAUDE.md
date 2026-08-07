# DOCUFIZ

Documentacion diaria de seguridad en obra. Este proyecto **arranca desde el codigo de TRAFODEX**
como base SaaS; el resto de este archivo son las convenciones heredadas de ese proyecto y siguen
vigentes salvo donde esta seccion diga lo contrario.

## Lo propio de DOCUFIZ

- **Dominio**: planes de trabajo diarios (`work_plans`), personas (`people`), formatos configurables
  (`form_templates` / `form_submissions`) y evidencias de firma (`signature_events`).
- **El dominio de transformadores fue purgado.** Si encuentras una referencia a transformadores,
  muestras, fiqui, furanos, FPOT, Duval, conmutadores, laboratorios o informes de diagnostico, es
  un resto: borrala. Ver `docs/PURGA.md`.
- **`Brand` y `Customer` siguen en el proyecto a proposito** (uno es la plantilla de `make:module`,
  el otro esta entretejido con el nucleo). Ambos estan en `docs/PENDIENTES.md` esperando decision.
- **`work_plans`, no `plans`**: en esta base `plans` son los planes de suscripcion.
- **Firmas**: toda firma genera un `signature_event` con su `evidence_file`. Nunca se guarda un
  texto magico en una columna de archivo, como hacia el sistema viejo con `detected_by_IA`.
- **Reconocimiento facial**: portado de tenkofiz. Si no reconoce en el tiempo configurado, se
  captura la foto igual y la firma queda pendiente de revision. **Nunca se bloquea el trabajo en
  campo.** Ver `docs/BIOMETRIA.md`.
- **Lo que decide una aprobacion lo decide el servidor**, nunca un campo del formulario.
- Antes de dar algo por terminado, actualiza `docs/CHECKLIST.md`, y si algo queda abierto,
  anotalo en `docs/PENDIENTES.md`. Nada se cierra en silencio.

---

# Convenciones heredadas de TRAFODEX

> **Para futuras conversaciones**: este archivo se lee automáticamente al
> trabajar en este directorio. Tiene todo el contexto que se necesita. No
> hace falta que el usuario re-explique decisiones.
>
> **¿Qué falta?** La lista ÚNICA de pendientes abiertos vive en
> [`docs/brain/backlog-decisiones.md`](docs/brain/backlog-decisiones.md).
> Antes estaba desperdigada por este archivo y por `docs/`, y no había forma de
> responder esa pregunta sin releer todo. Los "PENDIENTE" que quedan acá abajo
> son contexto de la decisión, no la lista.
>
> **Segundo cerebro**: el conocimiento ESTABLE del proyecto vive en
> [`docs/brain/`](docs/brain/00-INDICE.md) (índice + notas hub enlazando
> `docs/`; el usuario lo abre como vault de Obsidian). Este CLAUDE.md es la
> memoria de trabajo (estado y pendientes al día). Al cerrar una decisión o
> cambiar una convención: reflejarlo TAMBIÉN en la nota del brain que toque.

---

## Stack

- **Backend**: Laravel 13 + PHP 8.3 + PostgreSQL 16 (con extensión `unaccent`)
- **Frontend**: Inertia.js v2 + Vue 3 (Composition API + `<script setup>`) + Ant Design Vue 4 + Tailwind v4
- **Auth**: Sanctum bearer tokens con abilities
- **Permissions**: Spatie Permission con traits custom
- **Tests**: PHPUnit (Feature + Unit), SQLite in-memory para tests, Postgres para perf
- **Queue**: `database` driver (sin Redis — el usuario no usa Redis a propósito)
- **Storage**: `local` disk (sin S3 — el usuario solo guarda logos, imports, fotos perfil)
- **Build**: Vite + esbuild
- **Dev env**: Windows + Laragon (PHP 8.3 y Node 22 en el PATH — usar `php artisan ...` / `npm ...` pelados, sin rutas)
- **Prod env**: Digital Ocean droplet (todavía no configurado — el usuario quiere ayuda con eso cuando llegue).
  BD blindada paso a paso (VPC, TLS, `pg_hba.conf`, roles mínimos, túnel SSH desde
  la laptop, backups cifrados) en [`docs/DROPLET-POSTGRES-SECURITY.md`](docs/DROPLET-POSTGRES-SECURITY.md)
- **Secretos/seguridad**: ver [`docs/SECURITY.md`](docs/SECURITY.md) (dev+prod: `.env`,
  `APP_KEY`, `.env.encrypted` estilo Rails credentials, cifrado de columnas, qué usan
  las empresas grandes, checklist de deploy). Hoy `.env` plano gitignored; sin columnas cifradas.

---

## Decisiones de diseño (NO sugerir cambiar)

- **NO Redis**: descartado conscientemente. Sub-1ms con índices Postgres ya cubre. Cache de queries = premature.
- **NO S3 ni storage externo**: solo guarda fotos perfil, logos, imports. Disk `local`.
- **NO webhooks (todavía)**: documentado como feature premium futura.
- **NO observers cross-módulo**: feature futura.
- **NO code splitting agresivo del bundle**: 2.7MB es aceptable hasta tener tráfico.
- **`/dev/null` no aplica**: PowerShell — usar `$null`, `$env:VAR`, backtick para continuación de línea.
- **Comandos que el usuario corre en dev**: solo `php artisan serve`, `npm run dev/build`, `php artisan queue:work`. No quiere más.

---

## Módulo master template: `Customers`

> **PROPÓSITO**: Customer es el **patrón de referencia** (la entidad de negocio
> más completa: jerarquía, logo, API REST, clientes asignados). OJO: el scaffold
> `make:module` ya **NO clona Customer** — clona `Brand` (catálogo limpio), porque
> Customer arrastraba demasiada complejidad. Ver "Scaffold disponible" abajo.
> Customer queda como referencia de features avanzadas a copiar a mano.

### Por qué Customer es el master

Customer tiene todo lo que un módulo de negocio multi-tenant necesita:

- `BelongsToTenant` trait → cada workspace ve solo sus registros (con super bypass).
- Rutas con `permission:X.action` por acción (granular).
- `tenant_id` nullable + `HideSuperScope` automático.
- Audit log polimórfico + soft-delete + trash + restore + force-delete.
- Bulk ops auto-async (> 200 registros), undo 60s, duplicate, edit-all batch.
- Exports (CSV streaming + Excel/PDF/Word async) con límites por formato + memory_limit.
- Import 3-layer dedup + preview/commit two-phase.
- Favoritos polimórficos + recent items + saved views + column selector.
- Plan gating vía `FeatureGate` + `config/features.php`.
- Mobile responsive + dark theme + i18n full (es/en).

### Scaffold disponible

```bash
php artisan make:module {Name} --group=BusinessManagement
```

Genera ~50 archivos (controller, service, model, 9 FormRequests, 6 Jobs,
3 Exports, 1 Import, 6 Pages Vue, 13 Components, config + i18n × 2 idiomas,
migration, factory). Auto-registra el módulo en la tabla `system_modules`,
appendea routes, y agrega entries en `config/polymorphic.php` + `config/purge.php`.

**El scaffold clona `Brand`** (catálogo limpio: `name` + `code` + `is_active` +
`sort_order` + `tenant_id`, traits `BelongsToTenantOrGlobal` + `Lockable`), NO
Customer. Se cambió la base porque Customer creció (logo/dirección/jerarquía/
RestrictedToAssignedCustomers) y arrastraba esa complejidad rota a cada módulo.
Customer sigue siendo el **patrón de referencia** para features complejas (API
REST, jerarquía), pero NO la plantilla del scaffold.

Campos custom: `--fields="price:decimal, stock:integer, sku:string?"` los inyecta
en migration + fillable + casts + FormRequests + factory + lang + Form.vue +
Show.vue (spec-cell) + columns.js. Las **FKs (`references`)** todavía se agregan a
mano (el `--fields` no las soporta aún). El módulo generado trae lock funcionando
(columnas + rutas + trait). `--no-tenant` lo hace catálogo global.

El comando vive en `app/Console/Commands/MakeModuleCommand.php`.
Detalle completo del scaffold en [`docs/CREATE-MODULE.md`](docs/CREATE-MODULE.md).

### Lo que el scaffold NO automatiza (manual post-scaffold)

- Entrada en sidebar: `resources/js/Layouts/AppLayout.vue` + `resources/lang/{es,en}/sidebar.php`
- Permisos en `database/seeders/RolesAndPermissionsSeeder.php`
- Plan features específicos en `config/features.php`
- Columnas custom de la migration (FKs, índices del dominio)
- Si tiene FKs entrantes: array `dependents()` del modelo
- Capa API REST opcional (Resource + ApiController + rutas en `routes/api.php`).
  Los módulos generados son web-only (Inertia) por defecto. Solo Customer
  expone API hoy, como patrón de referencia.

---

## Cómo correr cosas en este proyecto

> `php`/`node`/`npm` están en el PATH (Laragon → Tools → Path). En docs y
> respuestas usar SIEMPRE los comandos pelados (`php artisan ...`, `npm run ...`)
> — el usuario pidió explícitamente NO ver rutas largas de Laragon (2026-07-26).

### Tests
```bash
php artisan test --filter=Customer   # por módulo
php artisan test                     # suite completa
php artisan test --group=performance # perf (skipea sin Postgres)
```

### Build
```bash
npm run build
```

### Migrations
```bash
php artisan migrate
```

### Si esbuild se cuelga (raro en Windows)
```powershell
Get-Process | Where-Object { $_.Name -match 'esbuild|node' } | Stop-Process -Force
```

---

## Convenciones de feedback que el usuario espera

- Sin emojis (a menos que él los pida)
- Honestidad brutal — si pregunta "está al 100?" y NO está, decírselo
- Sin elogios redundantes ("excelente pregunta!")
- Respuestas cortas a preguntas cortas
- Code edits con `Edit` tool, no `Write` completo
- Validation siempre antes de afirmar "está hecho" (build + tests)
- Cuando él dice "haz todo lo que tengas que hacer", actuar autonomous
- Español neutro estricto: NO argentinismos (vos/tenés/podés/verificá/abrí/hacé/querés/usá/cambiá/editá/probá/acá/etc.) en código NI en respuestas
- Git: pushear los cambios SIEMPRE a `main` directo (no dejarlos solo en ramas feature). Pedido explícito del usuario.

---

## Dominio de negocio: TR APP — diagnóstico de transformadores

> Este proyecto, además de ser el saas-base multi-tenant descrito arriba, aloja
> **TR APP**: un sistema de diagnóstico de transformadores eléctricos migrado de
> un sistema viejo en **Ruby on Rails (2019)** a Laravel. El motor de diagnóstico
> se monta SOBRE el saas-base sin modificar lo existente.

### Principio rector (NO romper)

El sistema viejo tenía las condiciones de diagnóstico **"mandrakeadas"** —
clavadas en el código, ~180 métodos casi idénticos en `chromatographical.rb`
(uno por combinación gas × aceite × tipo de trafo). El sistema nuevo las saca a
**datos editables** en tablas. **El código solo tiene fórmulas; todo lo que puede
cambiar (umbrales, pesos, aceites, normas, criterios) vive en datos.** Si te
piden agregar una condición y tu instinto es escribir un `if` nuevo en el motor,
DETENTE: casi siempre es una fila de datos (o una línea en el JSON del seeder).

### Motor de reglas

`tests` → `standards` → `variables` → `rule_sets` (un cuadro por
prueba+aceite+trafo+norma) → `rules` (escalón: score+peso+prioridad) →
`rule_conditions` (variable+operador+valor; varias = AND) → `result_scales`
(semáforo: rango de score → condición+color+rating 0-4).

- Motor: `app/Services/Diagnostics/ChromatographyEngine.php`. Promedia
  `Σ(score×peso) / Σ(peso)` = DGAF, con **peso dinámico** (no castiga gases
  faltantes). Ubica el DGAF en el semáforo.
- Datos de cromas: `database/seeders/data/cromas_rules.json` (editable).
- Seeders: `DiagnosticCatalogSeeder` (catálogos) + `CromasRulesSeeder` (reglas).
- Comando de prueba: `php artisan diagnose:cromas {id}`.

### Datos clave (de la BD real, no inventados)

- IDs de aceite: **1=Mineral, 4=Silicona, 5=Soya, 6=Girasol** (ésteres = futuro).
- Tipos de trafo: 1=Potencia, 2=Distribución, 3=Horno.
- Pesos por gas (mineral/silicona): H2=2, CH4=3, C2H4=3, C2H6=3, CO=1, CO2=1,
  C2H2=5 → total 18. Vegetales: sin CO2 → total 17.
- Semáforo cromas: <1.2 Muy Bueno · 1.2-1.5 Bueno · 1.5-2 Medio · 2-3 Malo · ≥3 Muy Malo.
- Índice de salud (Hitachi, peso dinámico): `HI = Σ(peso×rating)/Σ(peso×4)×100`.
  Escala: >85 Muy Bueno · 70-85 Bueno · 50-70 Medio · 30-50 Malo · ≤30 Muy Malo.

### Estado

> OJO: aunque abajo diga "HECHO", el módulo de diagnóstico NO está cerrado —
> ver "### PENDIENTE / A CLARIFICAR" más abajo (métodos IEEE, i18n, semáforo).

- HECHO: cromatografía completa (catálogos, motor, 234 reglas incl. horno) ·
  furanos (2-FAL + DP Chendong) · fiquis (fisicoquímico IEEE C57.106, por aceite +
  clase de tensión) · factor de potencia (fpot — diagnostica y PONDERA en el HI: `hi_enabled=true`, peso 10) · Índice
  de Salud combinado · Duval (triángulos 1/4/5 + pentágonos, `DuvalService` + UI) ·
  Rogers/Doernenburg (`RatioMethodsService`, datos en `ratio_methods.json`) ·
  **Informe PDF consolidado completo** (ver abajo).
- VERIFICADO contra el viejo: `php artisan verify:legacy` compara el HI cacheado
  del dump viejo vs el motor nuevo. Resultado (2424 trafos reales): **91.6% de
  paridad de HI (±5) y 94.8% de estado** en los 972 con todas las pruebas; el
  resto se explica: 580 nunca diagnosticados por el viejo (HI 0 default), 872
  parciales (el viejo CASTIGABA pruebas faltantes, el nuevo usa peso dinámico —
  diseño deliberado), 61 con muestras posteriores al snapshot viejo. Las ~21
  discrepancias restantes se INVESTIGARON (2026-06): 11 mantienen el mismo
  semáforo (solo difieren puntos); los 3 patrones de las 10 que cambian se
  validaron A MANO contra las tablas de reglas y en TODOS el motor nuevo aplica
  bien la norma y el viejo estaba mal (ej. trafo 801: el viejo daba "Muy Bueno"
  a un 220 kV con rigidez dieléctrica 33 kV — crítica según IEEE C57.106).
  Veredicto: paridad donde el viejo estaba bien; donde difieren, gana el nuevo.
  La verificación está CERRADA — no re-litigar contra el viejo (estaba
  mandrakeado); ante dudas, validar contra las normas en los datos de reglas.
- Migración de datos históricos: HECHA — dumps reales en
  `database/seeders/data/*_legacy.sql` + seeders `Legacy*Seeder` (idempotentes)
  + `DeduplicateLegacySamplesSeeder`; corren en el seed normal.
- UI de edición de reglas: EXISTE (`DiagnosticRulesController` + Pages
  `SystemManagement/DiagnosticRules`): semáforos editables, cuadros de reglas
  por aceite+trafo (sets/editSet) y datasets JSON con restore.
- REGLAS HÍBRIDAS POR TENANT (Fase 3 COMPLETA): cada workspace personaliza sus
  reglas sin tocar el estándar de fábrica (`tenant_id` nullable; null=global).
  Resolvers prefieren tenant→global (motor: `ChromatographyEngine::resolveRuleSet`;
  display: `RuleData`, `ResultScaleResolver`, fiqui overlay — vía
  `auth()->user()?->tenant_id`). El editor es tenant-safe: super edita el GLOBAL;
  el admin del workspace personaliza lo SUYO. Semáforos/datasets/umbrales fiquis/
  etiquetas = override por tenant; los CUADROS de reglas (rule_sets) usan
  COPY-ON-WRITE (admin edita global → se crea su copia tenant; "restaurar" borra
  el override → cae al global). Pesos del HI y params fiqui siguen GLOBALES (solo
  super). Acceso: rutas `role:super|admin`, gateado en `assertCanEditSet`.
- HECHO: etapa 2 de firmas — flujo de aprobación BATCH (ver abajo "Flujo de
  aprobación").
- ABIERTOS (deploy, el bug a debatir del aceite sin reglas, los números a
  confirmar con el laboratorio, el delta del sistema viejo): todos en
  [`docs/brain/backlog-decisiones.md`](docs/brain/backlog-decisiones.md).
- **RESUELTO (2026-06) — aceite sin reglas de DGAF ya NO rompe el HI (opción a).**
  Cuando el aceite no tiene rule_set de cromas, el motor devuelve `hiRating=null`.
  Antes eso EXCLUÍA cromas del HI → daba "100 Excelente" ocultando una muestra que
  el IEEE C57.104 marca peligrosa (bug de seguridad). Ahora `HealthIndexService::
  cromasComponent` **cae al DGA Status IEEE C57.104-2019** (no depende del aceite) y
  lo mapea a rating: Status 1→Muy Bueno · 2→Medio · 3→Malo. Así cromas SÍ cuenta para
  el HI (verificado: trafo 2095, aceite girasol sin reglas, Status 2 → HI 50 en vez
  de excluirse). El caso normal (con reglas) NO se toca (solo entra si `hiRating===null`).
  Coherencia del display: `TransformerShowPayload::cromasVerdict` corta el caso
  'Sin reglas' y muestra el mensaje explícito `cromas.diag.no_rules` (deriva al panel
  IEEE) en vez de fabricar "Estado normal" (se eliminó la contradicción). El semáforo
  de celdas/cards y el índice ahora reflejan el estado real (no falso 100). Tras
  deploy: correr `php artisan diagnose:fleet-cache` para recachear los afectados.
  Relacionado — YA HAY reglas DGA para vegetales (verificado 2026-07-15):
  `cromas_rules.json` incluye `vegetal_soya` y `vegetal_girasol` además de
  mineral/silicona → el respaldo IEEE del HI queda solo para instalaciones con BD
  vieja sin reglas sembradas (correr los seeders lo resuelve).
- NOTA dark theme: RESUELTO (verificado 2026-07-15). El barrido de leaks de gris
  claro se completó: ExportDialog/ImportDialog/GlobalSearch ya no tienen hex claros
  hardcodeados; los 2 que quedan (SavedViews `.sv-manage-item:hover`, ActivityTimeline
  `.activity-diff`) SÍ tienen override dark explícito — no son leaks. ADEMÁS
  (2026-07-15): el fondo SAP `#e9edf2` de `.sap-index/.sap-form/.sap-show` quedaba
  CLARO en dark (losa gris detrás de las tarjetas oscuras) → ahora usa
  `var(--sap-page-bg)` (light `#e9edf2`, dark cae a `--color-page-bg`). Verificado
  con navegador en dark+light (índices, trash). OJO: el overlay del tour de
  onboarding (driver.js) OSCURECE la página entera — si un screenshot se ve
  "lavado" con una tarjetita flotante, es el tour, no un bug de tema.
- POST-DEPLOY legal (LPDP/ANPD): lo técnico ya está implementado (aceptación
  versionada con registro+IP, aviso en portal, derechos ARCO en Mi perfil); lo
  que falta es trámite y redacción legal, detallado en el
  [backlog](docs/brain/backlog-decisiones.md).
- Motores de diagnóstico (todos en `app/Services/Diagnostics/`): `ChromatographyEngine`
  (cromas), `FuranoDiagnosisService`, `FiquisDiagnosisService`, `FpotDiagnosisService`,
  `DuvalService`, `RatioMethodsService`, `HealthIndexService`. Datos editables en
  `database/seeders/data/*.json` (`cromas_rules`, `fiquis_rules`, `duval_zones`,
  `ratio_methods`).

### Dashboard de flota (Power BI para diagnosticadores)

- `DashboardController::tenantFleetDashboard()` + `resources/js/Components/Dashboard/FleetDashboard.vue`.
  TODO con Eloquent normal (`$tx()`) → scope-safe (tenant + clientes asignados);
  super hace bypass del scope y agrega cross-tenant + filtro `f_tenant`.
- Widgets: salud (dona), tipos de falla (Duval), condición IEEE C57.104, vida del
  papel (DP Chendong), matriz de riesgo (condición × clase de tensión, heatmap),
  mayor generación de gas (TDCG ppm/mes), riesgo a corto plazo (pronóstico
  `cromasForecast`), por tipo/marca/país + mapa mundi (`public/maps/world.json`),
  top clientes, por workspace (super). Filtros: cliente/tipo/estado/(tenant).
- **Caché de flota** en `transformers`: `fault_type` (familia Duval), `gassing_rate`
  (TDCG ppm/mes), `paper_dp` (Chendong desde fal), `paper_life_years` (años a DP<200
  extrapolando furanos), `ieee_condition` (1-4). Se
  recalculan en `HealthIndexService::evaluate()` (persist) — NO editar a mano.
  Backfill de datos existentes: **`php artisan diagnose:fleet-cache`**.
  AUTOMÁTICO desde 2026-07-26: guardar en el editor de reglas encola
  `App\Jobs\RecalculateFleetCache` (delay 2 min + ShouldBeUniqueUntilProcessing
  = debounce; super → flota completa, admin → solo su tenant; colores/etiquetas
  NO lo disparan). El comando queda como respaldo manual.
- El pronóstico es extrapolación de tendencia de cromas, NO predicción de falla
  (no hay historial run-to-failure). No prometer "falla el día X".

### NOTAS Duval/fiquis (2026-06, el usuario se fue a dormir)

- HECHO: **PD clickeable en pentágonos/triángulos** — las zonas se ordenaban con PD
  primero (se dibujaba al fondo y S lo tapaba). Ahora `DuvalTab` ordena PD AL FINAL
  del dibujo (`.sort((a,b)=>(a.code==='PD')-(b.code==='PD'))` en tri/pentCharts) →
  PD queda encima y se puede clickear/resaltar. La clasificación no se toca (es server-side).
- HECHO: **test del Triángulo 5** — `test_t5_all_zones` (una muestra por cada zona
  O/S/C/T2/T3/PD/ND), 20 tests verdes.
- HECHO: **sección Duval del PDF dice el resultado** — veredicto corto POR gráfico
  ("Triángulo 1: Descarga parcial", "Pentágono 1: Stray gassing..."): `getReportImages`
  adjunta la zona (`diag`) de cada gráfico y el blade la muestra con `$zoneMeaning`
  (descripción sin el código). El `ReportChartRenderer` (PDF compartido/flota, server-side)
  también manda `zone`. OJO si el usuario dice que "no sale": necesita el build nuevo +
  regenerar el PDF (la captura corre en SU navegador).
- HECHO (2026-07-26) — **eje Y de las tendencias acotado a los LÍMITES** (pedido del
  jefe): `resources/js/utils/charts.js` (`axisFromLimits` + `niceMax`) deriva el tope
  del eje del primer límite POR ENCIMA del dato, en vez de dejar que el cuadro se
  estire. Lo usan `GasTrends` (cromas + detalle por parámetro de fiquis) y
  `MultiAxisTrend` (los 2 cuadros multi-eje de fiquis, que recibían la prop `limits`
  y NO la usaban; ahora acotan cada eje y marcan con línea punteada los límites que
  el dato ya superó). REGLA INVIOLABLE: el eje NUNCA recorta el dato — si un valor
  pasa todos los límites el tope sube hasta él (esconder una medición fuera de norma
  sería un bug de seguridad). De paso se corrigió un bug real: `Math.ceil()` en el
  tope llevaba los parámetros sub-unitarios (acidez 0.02–0.05) a un eje 0–1, o sea
  20× estirado y la curva plana contra el piso.
- HECHO (2026-07-26) — **tendencias de cromas: nombre del gas + límite rotulado**.
  Cada cuadro se titula "Hidrógeno H₂ (ppm)" (nombre corto `cromas.{gas}_short` +
  `s.sym`); el cuadro COMBINADO sigue con símbolos (9 nombres no entran en la
  leyenda). La línea del LÍMITE DE NORMA va rotulada ("Límite 150"). Ese límite
  lo calcula `normLimit()` (utils/charts.js) desde la banda sev 0: su `to` si es
  la primera (menor=mejor) o su `from` si es la última (mayor=mejor) → da el
  MISMO número que la cabecera del PDF y la tarjeta de resumen, no uno propio.
  El eje siempre incluye ese límite (si no, en rigidez con aceite degradado el
  piso quedaba fuera del cuadro).
- Queda el pulido visual de las tendencias de fiquis (subjetivo, verlo con el
  usuario) — anotado en el [backlog](docs/brain/backlog-decisiones.md).

### UI / sidebar / filtros (pedidos 2026-06-23) — TODO HECHO (verificado 2026-07-15)

> Los 4 puntos de esta sección ya están implementados (se hicieron en sesiones
> paralelas; verificado contra el código el 2026-07-15). Referencia:
1. **Colores del sidebar — HECHO**: `--color-shell-bar` definida por esquema en
   `app.css` y `--color-sidebar-bg` derivada automáticamente
   (`color-mix(in srgb, var(--color-shell-bar) 85%, #ffffff)`) → el sidebar/topbar
   siguen el esquema de color del perfil.
2. **Esquemas de color — HECHO**: hay **8** (`sap/slate/emerald/indigo/red/amber/
   teal/contrast`) en `app.css` (`html[data-scheme="..."]`) + `Profile/Show.vue`
   `SCHEMES`. El rojo es suave (`#B23A48`) como se pidió; los otros 2 nuevos:
   ámbar `#B45309` y teal `#0E7490`.
3. **AND / OR en filtros avanzados — HECHO y EVOLUCIONADO**: el piloto global de
   Customers se extendió a los **20 índices** (`show-conjunction` +
   `advanced_conjunction`). Después el builder se rediseñó: hubo una iteración con
   grupos anidados que se SIMPLIFICÓ a **lista plana con conector POR CLÁUSULA**
   (commit `cac3239`, "RENATI"). Backend: `FilterApplier`. Tests:
   `FilterApplierConjunctionTest`.
4. **Iconos de páginas Show/Form — HECHO**: Show y Form usan el icono del sidebar
   de su módulo (verificado: Customers `TeamOutlined`, Brands `TagsOutlined`,
   OilTypes `BgColorsOutlined`). Delete usa `DeleteOutlined`.

### Convenciones UI estandarizadas (2026-07 — seguirlas en módulos nuevos)

- **Franja blanca del título**: índices `.sap-index`, forms `.sap-form`, fichas
  `.show-page sap-show` (clase propia: NO usar `.sap-form` en Show — aplana cards
  y fuerza `.ant-col` a 100%), papeleras `.sap-form trash-page`. Trafos Show queda
  EXCLUIDO (layout propio).
- **Franja blanca al PIE (sticky bottom)**: `.bulk-bar` global en `app.css` — la
  barra de selección masiva de índices y papeleras va DESPUÉS de `</Card>`, hija
  directa del contenedor sap-* (sin wrapper `v-auto-animate`: mata el sticky).
  Los `*BulkBar.vue` / `*TrashBulkBar.vue` NO llevan `<style scoped>`. En forms/
  EditAll la franja equivalente es `FormFooter floating` / `EditAllFooter`.
- **EditAll**: thead sticky `top:44px` en desktop y `top:0` en ≤768px (en móvil
  la tabla es su propio scroll container y el offset del viewport deja una franja
  blanca — bug ya corregido, no reintroducir).
- **Papeleras (Trash)**: `ResponsiveTable :view="'table'"` + `:scroll="{x:'max-content'}"`
  (nunca cards en móvil) + buscador en `.trash-toolbar` (derecha, bajo el título).
- **Validación**: los FormRequests Store/Update usan el trait
  `DerivesAttributesFromLang` (`$attributeNamespace` = archivo de lang del módulo)
  → los mensajes dicen el MISMO label que el form. Fallback global en
  `resources/lang/{es,en}/validation.php → 'attributes'`. En módulos nuevos:
  agregar el trait + namespace.
- **Cabecera de la tabla de ensayos (cromas)**: 3 líneas — NOMBRE corto
  (`cromas.{gas}_short`) · SÍMBOLO (`col.sym`, negrita) · UNIDAD (`col.sub`).
  La norma D3612 y el nombre completo viven en el tooltip. `DiagnosticGrid`
  soporta `col.sym` (línea extra) y aplica `th-3line` (nowrap) para que el
  nombre no se parta a media palabra. Furanos/fpot siguen con 2 líneas.
- **Cabecera de la tabla de fiquis**: 3 líneas — NOMBRE · NORMA ("ASTM D1816")
  · MEDIDA con su condición de ensayo ("kV/2.0 mm", "25 °C. %"). Los nombres y
  ese formato los fijó el usuario (2026-07-26): NO reescribirlos por criterio
  propio ("Número Ácido", no "Acidez"). **Los tres textos son ETIQUETAS: viven
  en `resources/lang/{es,en}/fiquis.php`** (`{key}`, `{key}_astm`, `{key}_head`),
  NO en la base — helper `resources/js/utils/fiquiHeader.js` + `$fqAstm/$fqHead`
  en el blade del PDF, ambos con respaldo a la columna del backend. Cambiarlos
  no necesita migración ni seeder, solo `npm run build`. `{key}_unit` sigue
  siendo la unidad PELADA (ejes de gráficos y tooltips de celda).
  OJO NORMATIVO: en rigidez la condición es la SEPARACIÓN DE ELECTRODOS y
  cambia el valor esperado — D877 es siempre 2.54 mm (fijado por la norma),
  pero D1816 admite 1 mm o 2 mm y los kV no son comparables. Nuestros umbrales
  de `rig` vienen del Ruby viejo SIN registro del gap; están rotulados 2.0 mm
  (pendiente de confirmar con el laboratorio: si miden a 1 mm hay que corregir
  la etiqueta Y los umbrales). El usuario pasó una referencia con "ASTM D1877":
  es errata — la norma es **D877** (su propia captura anterior decía D877); no
  propagar D1877.
- **fiquis: `rig877` y `pot100` NO SUMAN, pero SÍ SUSTITUYEN (2026-07-27)**.
  Miden la misma propiedad que uno que ya puntúa (las dos rigideces; el FP a 25
  y 100 °C): sumarlos llevaría esas dos propiedades del 46% al 63% del índice y
  diluiría acidez/agua/tensión — eso NO cambió. Lo que cambió es el caso en que
  el laboratorio solo corrió el alterno: antes la propiedad se caía del promedio
  (peso dinámico) y un aceite con la rigidez por el piso medida en D877 salía
  igual que uno al que no le midieron nada. Ahora el alterno **ocupa el lugar**
  del principal, con el peso de la propiedad y contra SU norma
  (`FiquisDiagnosisService::measurementFor`). Se creía que el caso no existía
  ("0 muestras con solo el alterno"); era un artefacto de los ceros del sistema
  viejo — al anularlos aparecieron 626 con solo D877 y 104 con solo el FP a
  100 °C. **Con qué bandas puntúa el alterno**: si `tables` le carga
  `[t1,t2,t3]` gradúa 1..4 como cualquier otro; si solo tiene el límite único
  (el caso normal — la norma publica un valor de aceptación, no una gradación)
  se puntúa con las MISMAS tres bandas con las que ya se colorea su celda:
  verde/cumple→1 · ámbar/pegado al límite→3 · rojo/fuera de norma→4. No hay
  score 2 y es a propósito. **Se probó derivar 4 bandas escalando las del
  principal y se DESCARTÓ**: el color y el score se contradecían (un mineral con
  PF a 100 °C de 3 % pinta verde y la derivación le daba "Malo"). Fijado por
  `test_alternate_score_always_agrees_with_the_cell_colour` — no reintroducir.
  Los cuatro campos del par son
  OPCIONALES en el formulario (ten/acid/wat siguen obligatorios): con uno
  alcanza y con ninguno la propiedad no participa. Se probó pedir "al menos uno
  del par" y se DESCARTÓ — 472 ensayos no tienen el factor por ningún método y
  51 no tienen la rigidez; obligar a llenarlo es lo que llenó de ceros la base
  vieja. En esos cuatro campos el **0 se rechaza** (`gt:0` en
  `FiquiController::rangeRules`): una rigidez de 0 kV no existe y un factor de
  exactamente 0.000 % no es medible — es el "no medido" del viejo. En
  acid/ten/wat el 0 sí puede ser real y se acepta.
  OJO: pasar SIEMPRE `Fiqui::FIELDS` al motor, nunca `PARAMS`, o la sustitución
  no ocurre. Detalle en `docs/origen-ruby/diseno/FIQUIS_auditoria.md`.
- LECCIÓN (2026-07-26): se intentó resolver esto con una columna nueva en
  `fiqui_params` y fue un error — eran etiquetas y esos campos ni siquiera son
  editables desde la UI, así que la migración no compraba nada y obligaba al
  usuario a migrar+sembrar. Antes de agregar una columna: preguntarse si el
  texto ya tiene su lugar en los archivos de idioma.
- `FiquiRulesSeeder` refresca la IDENTIDAD del parámetro (dir/unit/astm/mode/
  orden) en cada corrida y NUNCA pisa la CALIBRACIÓN (`weight`, `tol`), que el
  super edita — mismo criterio que `SettingsSeeder`.
- **Botones**: PROHIBIDO `transform` (translate/scale) en `:hover` de `.ant-btn` —
  mueve el botón bajo el cursor y hace parpadear los Tooltip cerca del borde
  (bug ya corregido; el realce es solo `box-shadow`, el movimiento vive en `:active`).

### Colores editables por super — HECHO (verificado 2026-07-15)

> HECHO (verificado contra el código 2026-07-15): `severity.js` ya NO tiene los
> colores clavados — son defaults de fábrica con store reactivo +
> `setDiagnosticColors()`. El backend (`app/Support/Diagnostics/DiagnosticColors`)
> inyecta los colores como prop compartida de Inertia; editor = tarjeta en
> `/system_management/diagnostic-rules` (rutas `colors` update/restore en
> `DiagnosticRulesController`). Quedó con override POR TENANT además del global
> del super. Referencia original de lo pedido:

1. **Colores del semáforo de condición** (Muy Bueno…Muy Malo): el usuario los ve
   "muy fuertes" y quiere que el super los suavice/edite. Hoy en `severity.js`
   → `SEMA_HEX` (Muy Bueno `#1D7044`, Bueno `#5AA82E`, Medio `#E9A23B`, Malo
   `#E2661E`, Muy Malo `#C8281D`). El token (`green/lime/yellow/orange/red`) vive
   en `result_scales.color`; el HEX se mapea en el .js. Para editarlo: sacar el
   mapa token→hex a un dataset/setting y leerlo (fallback al .js actual).
2. **Colores de celda de pruebas** (croma/fiqui/furano/factor): el gradiente de
   severidad de celda (`severity.js` → `STOPS` verde/ámbar/rojo, función
   `sevColor`). Igual: a datos editables por super.
- **Dónde vivirían:** una tarjeta "Colores" en `/system_management/diagnostic-rules`
  (junto a los semáforos), **solo-super / global** (es un estándar visual, no
  per-tenant — como pesos del HI). Patrón: dataset editable + fallback al .js.
- **Relacionado (YA HECHO 2026-07):** el topbar ahora SÍ sigue el esquema
  (`--color-shell-bar` por `data-scheme`) y el sidebar deriva su tinte de esa var.
  Lo de abajo quedó obsoleto: el sidebar ya no es siempre blanco
  (su ítem activo ya sigue el esquema vía tintes de seleccionado).

### PENDIENTE / A CLARIFICAR — el módulo de diagnóstico NO está cerrado (2026-06-22)

> El usuario avisó explícitamente que NO se está cerrando el módulo de
> diagnóstico todavía; faltan aclarar cosas. NO dar por cerrado ni "100%"
> hasta resolver estos puntos. Pendientes abiertos:

- **DOS métodos IEEE coexisten y hay que decidir qué pasa con el viejo:**
  1. `IeeeConditionService` — método "Condición 1–4" (`ieee_c57104_limits.json`,
     edición **1991/2008**: límites fijos por gas, SIN dependencia de O₂/N₂ ni
     edad). Es el "Condición 1 verde" que se ve junto al DGAF. NO alimenta el HI
     (el componente de cromas del HI sale del DGAF/ChromatographyEngine); su
     salida va al cache de flota `ieee_condition` (1–4, dashboard + matriz de
     riesgo) y al gate de `fault_type` (cond≤1 → "normal", si no → Duval).
  2. `IeeeDgaStatusService` — método "DGA Status 1/2/3" (`ieee_c57104_tables123.json`
     + `ieee_c57104_table4.json`, edición **2019**: límites dependen de ratio
     O₂/N₂ ≤0.2/>0.2 + edad del trafo unknown/≤9/9–30/>30; Tabla 4 = tasa de
     generación multi-punto). La **Tabla 1 del PDF/imagen de IEEE C57.104-2019
     (90th percentile por O₂/N₂ + edad) corresponde a ESTE método** (verificado:
     coincide al dígito con `table1` del JSON).
  - DECISIÓN TOMADA (2026-06-22): el usuario eligió **consolidar en el 2019** y
    jubilar el "Condición 1–4" (1991) de TODO lo que se ve. HECHO en esta sesión
    (ver abajo "RESUELTO"). El `IeeeConditionService` (1991) SIGUE vivo SOLO como
    backend interno del cache de flota `ieee_condition` + gate `fault_type`
    (motor verificado, no tocado). Migrar ESO al DGA Status 2019 es lo que QUEDA.
- **RESUELTO (2026-06-22) — IEEE unificado en 2019 en la capa visible:** se quitó
  de la UI/PDF la columna "Condición 1–4" por muestra (`CromasTab`), la sección
  IEEE del drawer (`CromasExplainDrawer`) y la nota `ieee_worse` del veredicto
  (`CromasDiagnosis`, `useDiagnosisVerdict`, PDF). El toggle de límites y el popup
  comparativo (`CromatografiaSection`/`CromasLimitsModal`) ahora usan **IEEE
  C57.104-2019** vía `cromasLimitsIeee()`/`cromasNorms()` reescritos: Tabla 1
  (p90, verde/ámbar) + Tabla 2 (p95, ámbar/rojo), por columna O₂/N₂ + edad
  (`ieee2019Bucket()`). Así se usan LAS DOS tablas. El panel "DGA Status 1/2/3"
  es la lectura IEEE oficial. `ieee_c57104_limits.json` (1991) YA NO se lee en la
  capa visible (solo lo usa el cache de flota). Tests verdes (89 de diagnóstico).
- **RESUELTO (2026-06) — IEEE consolidado 100% en 2019:** se migró el cache de flota.
  `HealthIndexService::fleetDiagnostics` ahora calcula `ieee_condition` con
  `IeeeDgaStatusService` (2019, Status 1/2/3) en vez de `IeeeConditionService`
  (1991, Cond 1–4). El campo se sigue llamando `ieee_condition` (sin migración de
  BD) pero guarda el Status 2019 (1–3). El gate `fault_type` (status≤1 → normal)
  sin cambio de lógica. Display actualizado: gráfico de barras del dashboard (1..3),
  i18n `dashboard.fleet.ieee_*` → "Status N", `ComparisonMatrix` (color 1=verde/
  2=ámbar/3=rojo, label `comparison.ieee_cond` → "Status :n"), header de exports
  `h_ieee` → "IEEE Status". **La matriz de riesgo del dashboard NO usaba
  `ieee_condition`** (usa `health_rating`), así que no se tocó.
  VALIDADO sobre 2210 trafos reales (antes/después): distrib 1991 C1=1003/C2=408/
  C3=449/C4=350 → 2019 S1=1189/S2=292/S3=729; **20% cambian gate normal↔falla**
  (128 C1→S2/S3, 314 C2/3/4→S1); de los 350 C4, 342→S3 (los peores se mantienen).
  Tras deploy: correr `php artisan diagnose:fleet-cache` UNA vez para recachear.
  **BORRADO el 1991 (2026-06):** `IeeeConditionService`, su test, `ieee_c57104_limits.json`,
  y todo su cableado (tarjeta editable en DiagnosticRules: DATASETS/links/i18n,
  entry en DiagnosticDatasetsSeeder). Ya no existe método 1991 en el código. La
  fila huérfana `ieee_c57104_limits` en `diagnostic_datasets` (instalaciones
  viejas) es inofensiva — nada la lee ni el editor la lista.
- **Por qué "Condición 1" NO mostraba scores/pesos (NO era bug):** el método IEEE
  clasifica por LÍMITE (peor gas + TDCG), NO es el ponderado DGAF (Σ score×peso).
  El semáforo con scores/pesos es el DGAF (IEC 60599).
- **IEEE 2019 = dato normativo, NO se "tunea":** los valores salen de la norma
  publicada (`ieee_c57104_tables123.json` + `table4`, cruzados con el Ruby viejo y
  el PDF `tabla_4_ieee.pdf`). Debe quedar GLOBAL/solo-super (no personalizable por
  tenant, como pesos del HI y params fiqui). Si IEEE revisa la norma a futuro =
  editar los números del JSON/dataset (o vía super), CERO código. El DGAF (IEC) SÍ
  es calibración propia (Excel "condiciones del sistema") → ese sí lleva score+peso
  y es el editable/personalizable.
- **Duval — coordenadas canónicas (P1/P2/combinado + T4/T5):** El Triángulo 1 es
  100% canónico (clasifica por inecuaciones IEC 60599: cortes C2H2=4/13/15,
  C2H4=20/50, CH4=98; ver `_doc` anidado de `triangles.T1` en `duval_zones.json`).
  T3 usa cortes del Excel OFICIAL de Duval (extraídos col `yy1`; silicona reproduce
  9/16/46 al dígito) — incluye Midel. T2 (LTC) extraído del Excel oficial y validado
  con su punto ejemplo (→T3). **Pentágonos P1/P2/combinado: VERIFICADOS contra la
  FUENTE PRIMARIA (2026-06) — CERRADO.** Se consiguieron los papers originales
  (Cheim/Duval/Haider 2020 "Combined Duval Pentagons", Energies 13,2859; y Duval&
  Lamarre 2014, IEEE EI Magazine; PDFs en `docs/origen-ruby/fuentes-originales/`).
  Cotejo vértice a vértice: **NUESTROS vértices SON los canónicos** — cumbres, fórmula
  del centroide y todos los puntos de zona coinciden al dígito con las Figs 3/4/8/9
  del paper. En los puntos donde diferíamos de xDGA, el paper usa LOS NUESTROS, no los
  de xDGA: `(0,1.5)`,`(0,-3)`,`(24.3,-30)`,`(32,-6.1)`,`(-3.5,-3.5)`. **Por qué xDGA
  difería (RESUELTO):** el paper ORIGINAL Duval&Lamarre 2014 publica las coordenadas
  REDONDEADAS ((-1,-2),(24,-30),(38,12),(1,-32)) = las de xDGA; el paper 2020 las
  REFINÓ a las nuestras. O sea xDGA implementó el Duval 2014 y nosotros el Duval 2020
  (la última versión) → ya NO hay "ajuste fino del ~1%" pendiente, usamos la más nueva.
  Validados los **5 casos reales del paper (Tabla 1**, fallas confirmadas por inspección)
  en P1, P2 y Combinado: 4/5 exactos, el Caso 4 es el que el propio paper declara
  borderline C/T3-H. Centroide validado contra los ejemplos de AMBOS papers (2014 y 2020)
  al 2º decimal. **FIX hallado en el cotejo:** la zona PD del pentágono era inalcanzable
  (S, simplificado, la tapaba por orden de evaluación) → PD ahora se evalúa PRIMERO en
  P1/P2/combine; y el centroide degenerado de un solo gas ahora da V/3 (canónico).
- **Duval T4/T5: CANÓNICOS (2026-06) — CERRADO.** Eran lo último portado del Ruby.
  Ahora clasifican por INECUACIONES (`classify` en `duval_zones.json`) extraídas del
  Excel OFICIAL de Duval (hojas 'Triangle 4/5 LTF Mineral Oils') y verificadas contra
  xDGA: T4 cortes %C2H6=1/24/30/46·%CH4=2/15/36·%H2=9/15 (zonas O/S/C/PD/ND); T5 cortes
  %C2H4=1/10/35/49/70·%C2H6=2/12/14/15/30/54 (zonas O/S/C/T2/T3/PD/ND). Cobertura
  completa (0 NA en el barrido del simplex) y los ejemplos del Excel dan T4→S y T5→C.
  Antes nuestros polígonos tenían huecos (~18% de puntos sin zona); ahora 0. Los
  polígonos (`zones`) quedan SOLO para dibujar; la zona sale de `classify`. Gating
  T4(si T1∈PD/T1/T2)/T5(si T1∈T2/T3) sin cambios. Test:
  `test_t4_t5_canonical_from_official_excel`. **Ya NO queda nada de Duval portado del
  Ruby sin segunda fuente** — todo (T1/T2/T3/T4/T5 + pentágonos) es canónico y testeado.

### Notas de diagnóstico (investigado 2026-06 — NO re-litigar)

- **Etiquetas de condición ancladas al RATING (0-4), no a la palabra**: la palabra
  ("Excelente"…"Crítico") es solo presentación, editable por idioma desde el dataset
  `condition_labels` (BD vía RuleData, fallback JSON). Fuente única: `ConditionLabel`
  (`for(rating)`, `forCondition(palabra canónica)`, `forKey(clave i18n)`,
  `i18nOverrides()`). El override de las 5 líneas `diagnostics.cond_*` se inyecta en
  `HandleInertiaRequests::loadTranslations()` (todo Vue) + se usa en PDF/share/exports/
  buscador. En el editor de reglas la columna CONDICIÓN es auto-derivada del rating
  (read-only), NO texto libre. Editor de las 5 etiquetas: tarjeta en DiagnosticRules.
- **Dirección de ratings (es correcto, no es bug)**: en las PRUEBAS el valor mide
  GRAVEDAD (sube = peor) → rating 4→0 al subir el score (bajo=verde, alto=rojo). En el
  HI el valor mide SALUD 0-100 (sube = mejor) → rating 0→4 al subir. El rating siempre
  significa lo mismo (4=mejor, 0=peor); solo se invierte el EJE del valor. La fórmula
  del HI consume el rating, no el score → coherente. Si alguien edita las bandas con el
  rating en dirección equivocada, el cálculo SÍ se rompe → "Restaurar semáforos" lo arregla.
- **`diagnostics.cell_alert_sev` (Filtro de alertas leves en celdas, %)**: filtra SOLO
  el ámbar de "acercándose al límite" en las tablas de muestras de cromas/fiquis/furanos.
  Con 0 = muestra todos los ámbar + todos los rojos. El ROJO (fuera de norma) NUNCA se
  oculta, sin importar el %. `severity.js::cellAlertBg`.
- **fpot NO tiene alerta de celda** (mejora opcional pendiente): su tabla es un solo
  valor + temperatura, sin grilla de parámetros con límite individual → no hay celdas
  que filtrar. Si se quisiera, colorear la celda de valor por severidad del semáforo.


### Informe PDF del transformador (completo — no rediseñar sin pedido explícito)

- Generación: `TransformerController::report()` + blade
  `business_management/transformers/pdf/report.blade.php` (dompdf). Los gráficos
  llegan del front como PNGs (echarts `getDataURL`, instancias ocultas en
  `Show.vue`); el resto se arma server-side.
- Tiene: carátula-dashboard (gauge GD del HI + veredicto por prueba + QR),
  límites normativos por gas/parámetro con celdas en rojo al exceder, un gráfico
  de tendencia POR GAS (el combinado es solo pantalla), Duval, Rogers/Doernenburg,
  metodología (norma desde el `rule_set` resuelto — datos), secciones solo si la
  prueba tiene muestras, numeración dinámica.
- Footer: 100% canvas dompdf (`page_text`/`page_line`) en `_report_footer.blade.php`.
  NO volver a `position:fixed` — dejaba que las filas de tablas lo pisaran.
- OJO Blade: NO usar `@php(...)` inline en este blade — el regex de Blade lo
  empareja con cualquier `@endphp` posterior y rompe la compilación. Closures en
  el bloque `@php` principal.
- Símbolos: dompdf/Helvetica no tiene ≤ ≥ – etc. → todo texto dinámico pasa por
  el helper `$safe()`.
- Verificación: código HMAC + QR → portal público `/verify/{code}`
  (`ReportVerifyController`, valida contra audit log `report_generated`).
- Firmas: bloque DATA-DRIVEN de N firmantes por workspace (tabla `report_signers`:
  `relation` + cargo + usuario o externo + orden; gestor en "Mi workspace"
  `/workspace`). NO hay slot automático del que emite ("Emitido por" se eliminó:
  el que genera queda solo en el audit log). `relation` = lista cerrada TRADUCIBLE
  (prepared/reviewed/approved/authorized/verified/endorsed → "Aprobado por"/
  "Approved by"…); el cargo es texto libre. Regla de firma UNIFORME (no la toca el
  check de aprobación): la IMAGEN se estampa solo si el usuario tiene firma cargada
  Y activó auto-firma (`users.auto_sign_reports`, consentimiento auditado); sin
  eso, sale solo relación + nombre + cargo (constancia). Externos = línea a mano.
- Cada emisión queda auditada (`report_generated`: códigos, HI, firmantes,
  auto_signed por slot).

#### El informe en Word (.docx) editable — 2026-07-26

El MISMO informe que el PDF, en Word. Se baja del menú del botón "Exportar
informe" (`Dropdown.Button`: clic principal = PDF/enviar a aprobación, la flecha
= Word). Solo para UN trafo — la flota no lo ofrece.

Replica la maqueta del PDF: misma paleta (`#354A5F` banda, `#C8281D` regla,
`#F2F5F8` celda-etiqueta), cuerpo 9.5pt, portada con el anillo del índice y una
**página por sección numerada** (Cliente · Equipo · Metodología · DGA · Calidad
de aceite · Furanos · Factor de potencia · Conclusiones). Si se cambia el diseño
del PDF hay que reflejarlo en `TransformerReportWord`.

**Dos diferencias, y solo dos**: no lleva QR ni código de verificación, y no
estampa NINGUNA imagen de firma (ni de quien tiene auto-firma activada) — deja
la línea para firmar a mano. Razón: el código prueba autenticidad contra el
audit log y un archivo editable no puede sostener esa promesa.

Los TEXTOS no se redactan en el generador: son las mismas claves de idioma que
usa el blade (`method_cromas`, `method_fiquis`, `report.generated`…), para que
los dos informes digan lo mismo. Los gráficos van en rejilla de 2 por fila a
210pt, replicando el `.charts td { width:50% }` del PDF.

OJO OOXML: `w:sz` va en MEDIOS puntos ENTEROS. Un `'size' => 6.8` escribe
`w:sz="13.6"` y deja el .docx inválido (Word lo tolera, LibreOffice lo rechaza
de plano). Solo múltiplos de 0.5.

- Generador: `app/Exports/BusinessManagement/Transformers/TransformerReportWord.php`
  (PhpWord, misma convención que el resto de los exports Word).
- Entrada: `TransformerReportService::word()`, que reusa el MISMO payload del PDF
  (no se desincronizan) + `TransformerController::reportWord()`.
- Se audita como `report_draft_generated`, evento aparte de `report_generated`:
  mezclarlos ensuciaría el rastro de los informes que sí salieron a un cliente.
- OJO PhpWord: `addImage()` NO acepta data-URI. Los gráficos del navegador se
  vuelcan a un PNG temporal y se borran al guardar (y `tempnam()` crea el
  archivo: hay que borrar la semilla o quedan huérfanos de 0 bytes).

#### Flujo de aprobación (etapa 2 de firmas) — modelo BATCH

- Opt-in POR TENANT: `tenants.require_report_approval` (toggle en "Mi workspace").
  El toggle SOLO fuerza el flujo de solicitudes (notif app+correo); NO controla
  si salen firmas (eso es la regla uniforme de arriba). `notify_approval_by_email`
  agrega el canal correo (respeta `notifications.email_enabled`).
- Unidad que se aprueba = la SOLICITUD (`report_requests`), que agrupa N informes
  (`report_instances`, uno por trafo con snapshot+códigos). Un trafo suelto = 1;
  una flota = N. Los firmantes (`report_approvals`, uno por firmante interno)
  aprueban la solicitud UNA vez → se aprueban los N informes de golpe (no 40
  aprobaciones, una). Motor: `app/Services/Reports/ReportApprovalService.php`.
- Triggers (con toggle ON): botón "Enviar a aprobación" en `Show.vue`
  (`transformers.send_for_approval`) para 1 trafo; y `ReportShareController::store`
  deriva el "compartir" (single o flota) a una solicitud con los datos del envío.
- Estados: in_review → approved (emitido) | rejected. Mientras está en revisión el
  PDF sale marcado "EN REVISIÓN" sin firmas (`reviewState='in_review'`, lo ven los
  aprobadores en `approvals.draft`). Al emitirse, las firmas vienen de quienes
  aprobaron (`TransformerReportService::approvedSigners`).
- AUTO-COMPARTIR: si la solicitud nació de un "compartir" (lleva destinatario), al
  emitirse el flujo crea el `ReportShare` y manda el enlace solo al cliente
  (`ReportApprovalService::autoShare`). La descarga del portal (`PublicShareController::pdf`,
  single y flota) está gateada: con aprobación exigida solo sirve trafos con
  informe aprobado; sin aprobar → 403 (pendiente). La tabla de flota (datos, sin
  firmas) no se gatea.
- Bandeja "Aprobaciones" (`/approvals`, menú visible solo a firmantes): 2 pestañas
  (Pendientes/Completados) con tarjetas por solicitud (label, progreso, trafos con
  "ver borrador"). i18n en `resources/lang/{es,en}/approvals.php` (incl. `relation`).
- NOTA v1 (deuda técnica menor): el PDF del informe aprobado se RENDERIZA en vivo
  al descargar (con los códigos+firmas aprobados), NO desde un snapshot congelado
  byte-a-byte. `report_instances.snapshot` guarda un resumen (HI, condición, fecha)
  para auditar QUÉ se aprobó. Si cambian los datos del trafo DESPUÉS de aprobar y
  ANTES de descargar, el PDF reflejaría lo nuevo (ventana de aprobación corta; no
  crítico). Congelar el render completo = endurecimiento futuro.

### API del laboratorio (entrada de resultados) — 2026-07-29

> Detalle completo, ejemplos y códigos de error: [`docs/API-LABORATORIO.md`](docs/API-LABORATORIO.md).

El sistema de laboratorio deja de escribir en nuestra base y manda los
resultados por API. Tres endpoints bajo `/api/v1`, siguiendo el patrón de
`CustomerApiController`: `GET transformers/lookup` (`transformers:read`),
`POST transformers` (`transformers:write`), `POST lab-results` (`lab:write`).
Piezas: `LabResultApiController` + `app/Services/Lab/` (`LabResultService`
ingesta, `LabTransformerService` búsqueda/alta) + `config/lab_integration.php`
(mapa analito→columna: agregar un parámetro es una LÍNEA de config, no un `if`).

- **Idempotencia** (`EnforceIdempotency` + tabla `idempotency_keys`): cabecera
  `Idempotency-Key` obligatoria en `lab-results`. El reintento devuelve 200 con
  la respuesta original; misma clave con otro cuerpo = 409. La respuesta se
  guarda DENTRO de la transacción de los datos, y una petición fallida LIBERA su
  clave (para que el laboratorio corrija y reintente con la misma).
- **Segunda red**: índice único PARCIAL `(tenant_id, laboratory_id,
  report_number)` en las 4 tablas de muestras, con `WHERE report_number IS NOT
  NULL AND deleted_at IS NULL` — las ~20.000 filas históricas sin número quedan
  fuera. Si hubiera duplicados previos la migración avisa y salta la tabla.
- **Tras insertar corre `HealthIndexService::evaluate()`** (índice de salud +
  caché de flota del trafo). El job `RecalculateFleetCache` NO: ése es para
  cuando cambian las REGLAS.
- **Desajustes de modelo resueltos** (el porqué, en el doc): 20 tipos de equipo
  vs 3 → se rechaza lo que no se sabe diagnosticar (nunca "mayor a 3 →
  Potencia"); 3 tensiones vs 1 → se toma el MÁXIMO (define la clase de tensión
  del IEEE C57.106); fases entero→texto por tabla, sin redondeo; subestación
  obligatoria sin API de jerarquía → se acepta id o nombre y el 422 devuelve
  `available_substations` (NO se fabrica el nodo "-" del sistema viejo).
- **`methods` del laboratorio → columna**: `rig` con norma D877 va a `rig877`,
  `pot` a 100 °C va a `pot100`, y el bloque crudo se guarda en `fiquis.methods`
  (columna nueva). Cierra HACIA ADELANTE el pendiente del gap de rigidez sin
  registrar; lo histórico sigue igual.
- **Gating**: alcanza con `auth:sanctum` + abilities + `plan_feature:api_access`.
  NO se agregó feature de plan `lab_integration` (como pedía el doc del
  laboratorio): la fuente de verdad son las filas de `plans`, y una clave nueva
  daría 402 hasta editarlas todas. Tampoco permiso Spatie (el rol `api` no tiene
  permisos y ninguna ruta de la API usa `permission:`) ni toggle por empresa (el
  token ya es el interruptor, y solo el super lo crea).
- FUERA DE ALCANCE por ahora: el PDF firmado del laboratorio
  (`sample_documents`) y el webhook de vuelta (premium futuro).

### Procedencia y fuente de verdad

- **Documentación de origen**: [`docs/origen-ruby/`](docs/origen-ruby/README.md)
  — archivos fuente originales (Excel/Word/PDF/SQL), docs de diseño y trazabilidad
  de dónde salió cada regla.
- **Código Ruby viejo** (fuente de verdad real para extraer reglas):
  https://github.com/xwcamor/trapp — modelo clave `app/models/chromatographical.rb`.
  Al portar pruebas nuevas (fiquis, furanos…), extraer las reglas reales de ahí,
  como se hizo con cromas.
