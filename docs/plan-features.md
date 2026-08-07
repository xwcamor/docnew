# Matriz oficial de Plan × Feature

**Qué es esto**: la matriz fuente de verdad de qué features tiene cada plan (free / basic / pro / enterprise) y cómo se gatean.

**Para qué sirve**: decidir si una funcionalidad existe para un tenant según su plan, y verificar consistencia entre middleware, UI y lógica de negocio.

**Cuándo leerlo**: antes de sumar un middleware `plan_feature:X`, antes de ocultar un botón con `canUsePlanFeature()`, o cuando el usuario diga "no veo X y tengo plan Y".

> **Esta es la constitución del SaaS.** Cualquier cambio de gate (middleware, UI condicional, lógica interna) debe ajustarse a esta matriz. Si una feature nueva no está aquí, NO se gatea al azar — primero se agrega a esta tabla con su categoría, default por tier y mecanismo de aplicación.

## Las 3 capas de restricción (embudo, se aplican en orden)

1. **Rol** (`super` / `admin` / `user` / `api`) — QUIÉN es el usuario. Frontera de seguridad. Define acceso macro: core sí/no, papelera sí/no. El rol `api` es para system_users (tokens API por workspace), no se asigna a humanos.
2. **Plan** (`free` / `basic` / `pro` / `enterprise`) — QUÉ pagó el workspace. **Se deriva de la suscripción vigente del tenant** (no existe columna `tenants.plan`). Define capacidades comerciales.
3. **Permiso Spatie** — dentro de lo que el plan ya desbloqueó, QUÉ acción puntual permite el perfil del user.

### Plan ↔ Suscripción

- **`subscriptions` es la única fuente de verdad del plan.** `Tenant::currentPlan()` = `activeSubscription?->plan ?? 'free'`. No hay snapshot que mantener sincronizado.
- **`free` = ausencia de suscripción vigente** (el piso). Un plan pago que vence degrada el tenant a `free` automáticamente — no lo lockea. No se "suscribe" a `free`.
- **El form de Tenants crea la suscripción.** Al crear un workspace en un plan pago, `TenantService::create()` arranca un trial de 14 días. El plan NO se edita desde el form del tenant — se gestiona en el tab Suscripción (create / renew / cancel / suspend).
- **`cancel` suave / `suspend` duro.** `cancel` deja usar hasta `ends_at` (la sub sigue "current"). `suspend` corta el acceso ya, y `Tenant::isSuspended()` hace que `EnforceSubscription` lo bloquee por completo.
- **`EnforceSubscription` solo bloquea tenants suspendidos.** "Sin suscripción" = `free` = usable.

`super` saltea capas 2 y 3. `admin` saltea la 3 (puede todo en no-core) pero SÍ está sujeto a la 2. `user` está sujeto a 2 y 3. Ver [[project-access-model]] para el detalle de roles.

## Reglas de oro

1. **SSOT**: la tabla `plans` (DB, editable desde el módulo Plans). `config/features.php` es fallback. Las feature keys se declaran en `PlanController::featureKeys()`.
2. **Sin chequeos ad-hoc**: gating por plan SOLO via middleware `plan_feature:X` o helper `$tenant->canUseFeature('X')`.
3. **READ generoso, WRITE restrictivo** dentro de lo que el plan permite.
4. **super bypassa todo gate de plan.**
5. **Papelera/restore/force-delete = super only — NO va en planes.** UNDO (toast 60s) = cualquiera con permiso de borrar — tampoco va en planes. Son reglas de ROL.

## Matriz: Plan × Feature

| Funcionalidad | free | basic | pro | enterprise | Categoría / Aplicado en |
|---|:--:|:--:|:--:|:--:|---|
| **Límites numéricos** | | | | | |
| `max_records_per_module` | 50 | 5.000 | 50.000 | ∞ (-1) | LIMIT — check al crear en `store()` |
| `max_users` | 1 | 1 | 10 | ∞ (-1) | LIMIT — `Tenant::canCreateUser()` en `UserController::store` |
| `export_rate_limit` (/min) | 1 | 3 | 10 | 50 | LIMIT — throttle dinámico en exports |
| `support_level` | community | email | email | priority | ATRIBUTO — columna `plans.support_level`, 3 niveles. Descriptivo, sin gate técnico. |
| **Equipos de trabajo** | | | | | |
| `team_management` | ✗ | ✗ | ✓ | ✓ | GATE — `plan_feature:team_management` gatea **Users + Roles completos** (rutas + sidebar). free/basic son operación de 1 persona. |
| **Exports** | | | | | |
| `export_csv` | ✓ | ✓ | ✓ | ✓ | Libre (streaming). Sin middleware. |
| `export_excel` | ✗ | ✓ | ✓ | ✓ | GATE — `plan_feature:export_excel` |
| `export_pdf` | ✗ | ✓ | ✓ | ✓ | GATE — `plan_feature:export_pdf` |
| `export_word` | ✗ | ✓ | ✓ | ✓ | GATE — `plan_feature:export_word` |
| `branded_exports` | ✗ | ✗ | ✗ | ✓ | LÓGICA — usa logo del tenant al armar PDF/Excel |
| **Importar + masivas** | | | | | |
| `imports` | ✗ | ✗ | ✓ | ✓ | GATE — `plan_feature:imports` en rutas import |
| `bulk_operations` | ✗ | ✗ | ✓ | ✓ | GATE — `plan_feature:bulk_operations` en bulk_* |
| `edit_all` | ✗ | ✗ | ✓ | ✓ | GATE — edición masiva inline |
| **Visibilidad de datos** | | | | | |
| `audit_log_view` | ✗ | ✓ | ✓ | ✓ | GATE — `plan_feature:audit_log_view` en /audit_logs |
| `saved_views` | ✗ | ✓ | ✓ | ✓ | GATE — `plan_feature:saved_views` en /saved-views |
| **Automatización** | | | | | |
| `automations` | ✗ | ✗ | ✓ | ✓ | GATE — `plan_feature:automations` + sidebar hide |
| **Acceso programático** | | | | | |
| `api_access` | ✗ | ✗ | ✗ | ✓ | GATE — `plan_feature:api_access` en routes/api.php |
| **Features futuras (declaradas, sin gate efectivo)** | | | | | |
| `scheduled_exports` | ✗ | ✗ | ✗ | ✓ | Pendiente |
| `export_webhook_delivery` | ✗ | ✗ | ✗ | ✓ | Pendiente |
| `export_email_delivery` | ✗ | ✗ | ✗ | ✓ | Pendiente |
| **Lógica interna** | | | | | |
| `extended_retention` | ✗ | ✗ | ✗ | ✓ | LÓGICA — `app:purge-soft-deleted` (30d vs 7d) |
| `higher_export_rate_limit` | ✗ | ✗ | ✗ | ✓ | LÓGICA — throttle dinámico |

> **Nota:** el nivel de soporte ya NO es una feature booleana (`priority_support`). Es la columna `plans.support_level` con 3 niveles (community / email / priority) — ver fila "Límites numéricos".

## La lógica de los tiers

- **free** — "pruébalo". CRUD completo de los módulos no-core (crea, edita, **borra**) para que pruebe y le guste, pero tope de 50 registros/módulo. Sin exports avanzados, sin vistas guardadas, sin equipo. Embudo de conversión.
- **basic** — profesional solo. CRUD completo + exports Excel/PDF/Word + vistas guardadas + audit. Sin equipo (1 usuario).
- **pro** — basic + **Equipos de trabajo** (Users/Roles), bulk, import, edit-all, automatizaciones. 10 usuarios, 50.000 registros.
- **enterprise** — pro + API REST + todo ilimitado + soporte prioritario.

## Decisiones de diseño

- **`team_management` reemplazó a `custom_roles`.** Antes `custom_roles` solo gateaba la creación de roles. Ahora `team_management` gatea **Users + Roles completos** (módulos enteros) — coherente con "free/basic = operación de 1 persona, no necesitan equipo".
- **`imports` separado de `bulk_operations`.** Mismos tiers hoy (pro+), pero son toggles independientes en el form de Planes — el super puede diferenciarlos a futuro.
- **El core (`system_management/*`) no se gatea por plan** — es super only por ROL. Los planes solo gobiernan módulos no-core.
- **El customer nunca ve la palabra "core"** — para él, los módulos que ve SON el sistema.

## Cómo se aplica una feature nueva

1. Decidir categoría (GATE / LIMIT / LÓGICA) y default por tier — agregar a esta matriz.
2. Agregar a `PlanController::featureKeys()` (solo si es booleana — los atributos descriptivos como `support_level` van como columna dedicada, no aquí).
3. Agregar valor por tier en `PlansSeeder` (los 4 planes) + `config/features.php` (fallback).
4. Agregar label i18n en `resources/lang/{es,en}/plans.php` como `feature_{camelCase}`.
5. Aplicar el gate: middleware `plan_feature:X` en rutas, o check `$tenant->canUseFeature('X')` / `maxRecordsPerModule()`.
6. Re-seedear planes (`php artisan db:seed --class=PlansSeeder`) o backfill en DB si está en producción.

---

## Documentación relacionada

- [`PERMISSIONS.md`](PERMISSIONS.md) — capa de roles + permisos (anterior al plan)
- [`USAGE.md`](USAGE.md) — cómo se gestionan suscripciones y planes en la UI
- [`CREATE-MODULE.md`](CREATE-MODULE.md#58-plan-gating-si-el-módulo-es-premium) — cómo gatear un módulo nuevo por plan
- [`AUTOMATIONS.md`](AUTOMATIONS.md) — ejemplo de feature gated por plan (`automations` requiere `pro+`)
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — decisión de derivar plan de suscripción en runtime
- [`../README.md`](../README.md) — resumen de los 4 tiers
