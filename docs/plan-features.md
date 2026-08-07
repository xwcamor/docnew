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

`super` saltea las capas 2 y 3. `admin` saltea la 3 (tiene todos los permisos) pero SÍ está sujeto a la 2. Un usuario con perfil está sujeto a las tres. Detalle en [`ACCESS-MODEL.md`](ACCESS-MODEL.md).

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
| **Importar + masivas** | | | | | |
| `imports` | ✗ | ✗ | ✓ | ✓ | GATE — `plan_feature:imports`, pero **solo en Usuarios**. En los módulos de negocio la importación va gateada por `bulk_operations` |
| `bulk_operations` | ✗ | ✗ | ✓ | ✓ | GATE — `plan_feature:bulk_operations` en las masivas **y en las importaciones** de `routes/business_management.php` |
| `edit_all` | ✗ | ✗ | ✓ | ✓ | **SOLO EN LA INTERFAZ** — `canUsePlanFeature('edit_all')` esconde la opción del menú, pero la ruta `.../edit_all` no lleva middleware de plan. Quien conozca la URL entra |
| **Visibilidad de datos** | | | | | |
| `audit_log_view` | ✗ | ✓ | ✓ | ✓ | **DECLARADA, SIN GATE** — `/audit_logs` está protegido solo por `role:super\|admin`. Marcarla o desmarcarla no cambia nada |
| `saved_views` | ✗ | ✓ | ✓ | ✓ | GATE — `plan_feature:saved_views` en `routes/saved_views.php` |
| **Automatización** | | | | | |
| `automations` | ✗ | ✗ | ✓ | ✓ | GATE — `plan_feature:automations` + sidebar hide |
| **Acceso programático** | | | | | |
| `api_access` | ✗ | ✗ | ✗ | ✓ | GATE — `plan_feature:api_access` en routes/api.php |
| **Declaradas, sin gate efectivo** | | | | | |
| `scheduled_exports` | ✗ | ✗ | ✗ | ✓ | Pendiente |
| `export_webhook_delivery` | ✗ | ✗ | ✗ | ✓ | Pendiente |
| `export_email_delivery` | ✗ | ✗ | ✗ | ✓ | Pendiente |
| `branded_exports` | ✗ | ✗ | ✗ | ✓ | Pendiente — se pensó para usar el logo del tenant al armar el PDF |
| `extended_retention` | ✗ | ✗ | ✗ | ✓ | Pendiente — se pensó para alargar la retención en `app:purge-soft-deleted` |
| `higher_export_rate_limit` | ✗ | ✗ | ✗ | ✓ | Pendiente — el throttle de exportación es fijo hoy |
| `customer_scoped_users` | ✗ | ✗ | ✗ | ✓ | Pendiente — la restricción por cartera asignada existe en el código, pero no la gatea el plan |
| **Herencia muerta** | | | | | |
| `report_sharing` | ✗ | ✗ | ✓ | ✓ | **Ya no significa nada.** Gateaba compartir un informe de diagnóstico con un cliente externo por token; ese portal se fue con la purga. Quitar de `PlansSeeder` y de `config/features.php` |

> **Nota:** el nivel de soporte ya NO es una feature booleana (`priority_support`). Es la columna `plans.support_level` con 3 niveles (community / email / priority) — ver fila "Límites numéricos".

> **Declarar no es gatear.** Menos de la mitad de las features de
> `config/features.php` tienen middleware de verdad; el resto están en la tabla y
> en el formulario de planes sin que ninguna ruta las consulte. Marcarlas en un
> plan no cambia nada. Antes de prometerle una a un cliente, comprobar que la
> columna de la derecha dice `GATE`, y no "pendiente" ni "solo en la interfaz".
>
> Comprobarlo cuesta un grep: `grep -rn "plan_feature:" routes/`.

## La lógica de los tiers

- **free** — "pruébalo". CRUD completo de los módulos no-core (crea, edita, **borra**) para que pruebe y le guste, pero tope de 50 registros/módulo. Sin exports avanzados, sin vistas guardadas, sin equipo. Embudo de conversión.
- **basic** — profesional solo. CRUD completo + exports Excel/PDF/Word + vistas guardadas. **Un solo usuario**, igual que free: `max_users` es 1 en los dos.
- **pro** — basic + **Equipos de trabajo** (Usuarios/Perfiles), masivas, importar, edición masiva, automatizaciones. 10 usuarios, 50.000 registros.

Para DOCUFIZ el escalón que importa es **basic → pro**: un plan de trabajo lo
arma un supervisor y lo firma una cuadrilla entera, así que sin
`team_management` no hay a quién dar de alta. Una obra real empieza en `pro`.
- **enterprise** — pro + API REST + todo ilimitado + soporte prioritario.

## Decisiones de diseño

- **`team_management` reemplazó a `custom_roles`.** Antes `custom_roles` solo gateaba la creación de roles. Ahora `team_management` gatea **Users + Roles completos** (módulos enteros) — coherente con "free/basic = operación de 1 persona, no necesitan equipo".
- **`imports` separado de `bulk_operations`.** Mismos tiers hoy (pro+), pero son toggles independientes en el form de Planes — el super puede diferenciarlos a futuro.
- **El core (`system_management/*`) no se gatea por plan** — es super only por ROL. Los planes solo gobiernan módulos no-core.
- **El cliente nunca ve la palabra "core"** — para él, los módulos que ve SON el sistema.
- **La firma no se gatea por plan, nunca.** Firmar un formato y revisar una firma pendiente son permisos (`form_submissions.sign`, `signature_events.review`), no features. Un documento de seguridad no puede quedar sin firmar porque venció una suscripción.

## Cómo se aplica una feature nueva

1. Decidir categoría (GATE / LIMIT / LÓGICA) y default por tier — agregar a esta matriz.
2. Agregar a `PlanController::featureKeys()` (solo si es booleana — los atributos descriptivos como `support_level` van como columna dedicada, no aquí).
3. Agregar valor por tier en `PlansSeeder` (los 4 planes) + `config/features.php` (fallback).
4. Agregar label i18n en `resources/lang/{es,en}/plans.php` como `feature_{camelCase}`.
5. **Aplicar el gate de verdad**: middleware `plan_feature:X` en la ruta, o `$tenant->canUseFeature('X')` en el servicio. Esconder el botón en Vue **no es un gate** — es cortesía. La mitad de las features de esta matriz se quedaron en este paso.
6. Re-sembrar planes (`php artisan db:seed --class=PlansSeeder`) o actualizar en BD si ya está en producción.

---

## Documentación relacionada

- [`ACCESS-MODEL.md`](ACCESS-MODEL.md) — las tres capas juntas, con los huecos de gateo por módulo
- [`PERMISSIONS.md`](PERMISSIONS.md) — capa de roles + permisos (anterior al plan)
- [`USAGE.md`](USAGE.md) — cómo se gestionan suscripciones y planes en la UI
- [`CREATE-MODULE.md`](CREATE-MODULE.md#58-plan-gating-si-el-módulo-es-premium) — cómo gatear un módulo nuevo por plan
- [`AUTOMATIONS.md`](AUTOMATIONS.md) — ejemplo de feature gated por plan (`automations` requiere `pro+`)
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — decisión de derivar plan de suscripción en runtime
- [`../README.md`](../README.md) — resumen de los 4 tiers
