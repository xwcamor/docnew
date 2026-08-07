# Hardening aplicado al sistema

Inventario de fixes de seguridad y bugs aplicados sobre la base, con paths
exactos para que cuando audites o quieras replicar un patrón los encuentres
rápido. Está ordenado por **capa afectada** (defense-in-depth, auth, requests,
services, jobs, queries, etc.), no por severidad.

---

## 1. Defense-in-depth multi-tenant

### `BelongsToTenant` trait — auto-force tenant_id

**Archivo**: [`app/Traits/BelongsToTenant.php`](../app/Traits/BelongsToTenant.php)

Cuando un usuario non-super persiste un modelo con `BelongsToTenant`, el
listener `creating` **siempre** sobrescribe `tenant_id` con el del actor —
incluso si el modelo viene con un `tenant_id` distinto por mass assignment.

```php
// Non-super: SIEMPRE forzar al tenant del actor, ignorando cualquier
// tenant_id mass-assigned. Bloquea cross-tenant writes incluso si un
// FormRequest futuro deja pasar `tenant_id` por error.
$model->tenant_id = $user->tenant_id;
```

**Super** sí puede pasar `tenant_id` distinto (caso legítimo: crear el admin
user de un workspace nuevo). El trait solo autorellena si vino vacío para super.

### Foreign keys en `tenant_id`

Todas las migrations `create_*_table.php` que tienen una columna `tenant_id`
ahora la declaran con FK constraint desde el `Schema::create` original — no
hay migrations de "retrofit" sumando constraints después. Estado consolidado:

| Tabla | Migration | Constraint |
|---|---|---|
| `users` | [`create_users_table.php`](../database/migrations/2025_09_18_093438_create_users_table.php) | `foreignId(...)->constrained()` (RESTRICT) |
| `roles` | [`create_permission_tables.php`](../database/migrations/2025_09_18_093509_create_permission_tables.php) | `foreignId(...)->constrained('tenants')->nullOnDelete()` |
| `customers` | [`create_customers_table.php`](../database/migrations/2026_05_13_223304_create_customers_table.php) | `foreignId(...)->nullable()->index()->constrained('tenants')->nullOnDelete()` |
| `automations` | [`create_automations_table.php`](../database/migrations/2026_05_13_200000_create_automations_table.php) | `foreignId(...)->nullable()->index()->constrained('tenants')->nullOnDelete()` |
| `automation_runs` | [`create_automation_runs_table.php`](../database/migrations/2026_05_13_200100_create_automation_runs_table.php) | `foreignId(...)->nullable()->index()->constrained('tenants')->nullOnDelete()` |
| `subscriptions` | [`create_subscriptions_table.php`](../database/migrations/2026_05_14_120000_create_subscriptions_table.php) | `foreignId(...)->constrained()->cascadeOnDelete()` |

Sin `tenant_countries` (eliminada — era código muerto sin modelo ni uso).

Las cinco migraciones del dominio de DOCUFIZ (`2026_08_07_1003xx`) siguen el mismo
patrón: `companies`, `people`, `work_plans`, `form_templates` y sus catálogos
declaran `tenant_id` con `constrained('tenants')->nullOnDelete()` desde el propio
`Schema::create`.

Patrón para módulos nuevos vía `make:module`: el comando clona
`create_brands_table.php`, que ya trae la FK, así que se hereda sin que nadie
tenga que acordarse.

---

## 2. Validation requests — IDOR protection

### `tenant_id` queda fuera del control del cliente

**Archivos**:
- [`StoreRequest.php` (Users)](../app/Http/Requests/AuthManagement/User/StoreRequest.php)
- [`UpdateRequest.php` (Users)](../app/Http/Requests/AuthManagement/User/UpdateRequest.php)
- [`StoreAutomationRequest.php`](../app/Http/Requests/AutomationManagement/Automation/StoreAutomationRequest.php)

`prepareForValidation()` fuerza `tenant_id` del actor cuando el usuario no
es super. Combinado con el guardrail del trait `BelongsToTenant`, hay dos
capas independientes contra cross-tenant writes.

### Email unique por tenant

**Archivos**: mismos que arriba.

Antes: `email` único global → tenant B no podía crear `admin@gmail.com`
si tenant A ya lo tenía, y soft-deleted bloqueaba la restauración.

Ahora:
```php
Rule::unique('users', 'email')
    ->ignore($user?->id)
    ->where(fn($q) => $tenantId === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tenantId))
    ->whereNull('deleted_at');
```

---

## 3. XSS stored en Messages/Inbox

**Sanitizador**: [`app/Support/HtmlSanitizer.php`](../app/Support/HtmlSanitizer.php)

Implementación con `DOMDocument` + whitelist:
- Tags permitidos: `a`, `b`, `strong`, `i`, `em`, `u`, `br`, `p`, `div`,
  `span`, `ul`, `ol`, `li`, `blockquote`, `code`, `pre`, `h1`-`h6`, `hr`.
- Atributos permitidos: solo `href`/`title`/`target`/`rel` en `<a>`.
- Strip total de event handlers (`on*`), `javascript:`, comentarios HTML.
- Para `<a target="_blank">` fuerza `rel="noopener noreferrer"`.

Aplicado en:
- [`StoreMessageRequest.php`](../app/Http/Requests/Communication/Message/StoreMessageRequest.php)
- [`UpdateMessageRequest.php`](../app/Http/Requests/Communication/Message/UpdateMessageRequest.php)
- [`StoreReplyRequest.php`](../app/Http/Requests/Communication/Message/StoreReplyRequest.php)

El `body` y `subject` se sanitizan en `prepareForValidation()` antes de
llegar al servicio. Los componentes Vue siguen usando `v-html` pero ahora
es seguro.

---

## 4. Auth / API tokens

### Sanctum sin default `['*']`

**Archivos**:
- [`TenantController::createToken`](../app/Http/Controllers/SystemManagement/TenantController.php) — abilities `required|array|min:1`.
- [`AuthController::login`](../app/Http/Controllers/AuthManagement/AuthController.php) — abilities obligatorias en el body request.

Antes: tokens nacían con `['*']` y bypaseaban los middleware `ability:customers:read`
etc. Ahora el cliente debe declarar explícitamente qué puede hacer el token.

### Forgot/Reset password con throttle

**Archivo**: [`routes/auth_management.php`](../routes/auth_management.php)

`throttle:5,1` en `POST password/email` y `POST password/reset` — mitiga
enumeración de correos y fuerza bruta.

> **`POST login` no lo tiene.** El formulario de inicio de sesión acepta intentos
> sin límite. Los ajustes `security.max_login_attempts` (5) y
> `security.lockout_minutes` (15) llevan tiempo sembrados sin que nadie los lea.
> Mientras eso siga así, **ningún documento debe prometer que la cuenta se bloquea
> tras N intentos**, porque no ocurre.

### OAuth Google sin tenant hardcoded

**Archivo**: [`GoogleLoginController.php`](../app/Http/Controllers/AuthManagement/Auth/GoogleLoginController.php)

Cuenta auto-creada queda con `tenant_id = null` (antes `tenant_id => 1`
literal). Si un super la activa por error, el usuario no accede a ningún
workspace — primero hay que asignarle un tenant explícito desde el módulo
Users.

### File upload con nombre generado

**Archivo**: [`UserService::storePhoto`](../app/Services/AuthManagement/UserService.php)

Nombre del archivo se genera con `Str::random(12) + extensión validada`
(antes usaba `$file->getClientOriginalName()` que viene del cliente —
permite caracteres raros, doble extensión tipo `shell.php.jpg`, etc.).

---

## 5. Idempotency y atomicity

### Bulk jobs con `ShouldBeUnique`

**Patrón aplicado a todos los jobs de acción masiva** (Customers, Brands,
Companies, People, WorkPlans, FormTemplates, Users, Roles, Automations, Countries,
Languages, Locales, Regions, Settings, SystemModules, Tenants):

```php
class BulkCustomersActionJob implements ShouldQueue, ShouldBeUnique
{
    public int $uniqueFor = 1800;  // TTL = timeout del job

    public function uniqueId(): string
    {
        $idsHash = md5(implode(',', array_map('intval', $this->ids)));
        return "bulk:customers:{$this->userId}:{$this->action}:{$idsHash}";
    }
}
```

Retries del worker no duplican Downloads ni entries de audit log.

### Bulk services en `DB::transaction`

11 services. Cada `bulkDelete/bulkSetActive/bulkRestore` envuelto en
`DB::transaction(function() use(...) { ... })` — rollback parcial si
un delete falla mid-foreach.

### AutomationService — single save

**Archivo**: [`AutomationService.php`](../app/Services/AutomationManagement/AutomationService.php)

Antes: `save()` para crear + `save()` para setear `next_run_at`. Si el
segundo fallaba (lock contention, conexión caída), automation quedaba con
`next_run_at = null` y el scheduler la ignoraba para siempre.

Ahora: `next_run_at` se calcula en memoria antes del único `save()`.

### Welcome email tras commit

**Archivo**: [`UserService.php`](../app/Services/AuthManagement/UserService.php)

`DB::afterCommit(fn() => $user->notify(...))`. Si el caller envuelve `create()`
en una transacción y hace rollback, el welcome NO se envía a un user fantasma.

### `forceDelete` con `lockForUpdate`

**Archivo**: [`UserController::forceDelete`](../app/Http/Controllers/AuthManagement/UserController.php)

Re-fetch del user dentro de la transacción con `lockForUpdate()` — elimina
race entre force-delete + restore concurrente. Si entre el preview y el
lock alguien restauró el user, `onlyTrashed()->firstOrFail()` aborta sin daño.

---

## 6. Automations — cron y timezone

**Archivo**: [`app/Models/Automation.php::computeNextRunAt`](../app/Models/Automation.php)

Antes: `CronExpression::getNextRunDate($from)` con `$from` en UTC →
"9:00 daily" disparaba a las 9:00 UTC = 3 AM en México.

Ahora: convierte `$from` a la TZ de `trigger_config.timezone` antes de
pasar al cron parser, y vuelve a UTC para persistir.

---

## 7. Performance — exports masivos

**Patrón aplicado a todos los jobs de Excel/PDF/Word** (`Generate*ExcelJob`,
`Generate*PdfJob`, `Generate*WordJob` — hoy son unos 48 archivos):

Antes:
```php
$customers = $this->buildQuery()->get();   // hidrata TODO en memoria
```

Ahora:
```php
$query  = $this->buildQuery();
$count  = (clone $query)->count();
$cursor = $query->cursor();
```

Eloquent models se hidratan on-the-fly. Memoria ~10× menor con datasets de
80k+ filas. Constructores de Export aceptan `?int $count = null` como tercer
argumento. Templates Blade usan `$totalCount` (no `$customers->count()`).

CSV ya usaba `chunkById(1000)` (streaming desde antes).

---

## 8. SQL — LIKE wildcards

**Helper**: [`app/Support/LikeQuery.php`](../app/Support/LikeQuery.php)

`LikeQuery::contains('50%')` → `'%50\%%'` con `ESCAPE '\\'` en la query.
Los caracteres `%` y `_` del input usuario actúan como literales (antes
actuaban como comodines SQL).

Aplicado en 13 modelos: los del core (`Country`, `Language`, `Locale`, `Region`,
`Setting`, `SystemModule`, `Tenant`, `Customer`, `Brand`) y los del dominio de
obra (`Company`, `Person`, `WorkPlan`, `FormTemplate`).

Aquí `unaccent` no es un adorno: en obra se busca a una persona por su apellido
escrito como salga (`Rodriguez`, `Rodríguez`, `RODRIGUEZ`) y a una empresa por un
nombre con tildes. Sin la extensión, media búsqueda no encuentra nada.

Patrón uniforme:
```php
$qq->orWhereRaw(
    "unaccent(lower({$tbl}.name)) LIKE unaccent(lower(?)) ESCAPE '\\'",
    [LikeQuery::contains((string) $name)],
);
```

---

## 9. UX / hygiene

- **Click derecho global** bloqueado excepto en inputs editables — ver
  [`app.js`](../resources/js/app.js).
- **Popconfirm > Tooltip z-index** — `.ant-popover { z-index: 1080 }` en
  [`app.css`](../resources/css/app.css).
- **Tabs estilo Fiori** removidos del shell — más responsive.
- **Validaciones Laravel** en idioma activo — creados
  [`resources/lang/{es,en}/validation.php`](../resources/lang/es/validation.php)
  (antes Laravel caía a sus defaults en inglés).
- **Help icons en formularios** — todos los `FormItem` tienen `tooltip` con
  clave `*_help` traducida.
- **Argentinismos** — cero matches en `.php`, `.vue`, `.js` (96 ocurrencias
  corregidas: 31 visibles + 65 en comentarios).

---

## 10. Pendiente / no resuelto

- **`enforceMorphMap`**: no aplicado. Un cambio futuro de namespaces puede
  romper las FK polimórficas históricas (`audit_logs`, `user_favorites`, y ahora
  también `signature_events.signable` y los comentarios).
- **`UserController` route binding tenant check**: User usa `BelongsToTenant`
  trait que filtra reads via global scope — los writes ya están protegidos
  por el FormRequest. Verificar caso por caso si aparece un endpoint que
  haga lookup directo sin scope.
- **`mimes:` vs `mimetypes:`** en las subidas — sigue siendo validación por
  extensión, no por contenido. El nombre del archivo ya se regenera al guardar,
  así que el riesgo es bajo.
- **Las columnas de bloqueo faltan en cinco tablas.** El trait `Lockable` está en
  `Company`, `Person`, `WorkPlan`, `FormTemplate` y `Brand`, y sus rutas
  `lock`/`unlock` existen, pero la única migración que crea `locked_at`,
  `locked_by` y `lock_scope` lo hace solo sobre `customers`. Detalle en
  [`ACCESS-MODEL.md` §6.5](ACCESS-MODEL.md#65-lock-por-registro-congelar--trait-lockable).
- **`export`/`import` sin gate en los módulos de obra.** Las rutas de exportación
  de `companies`, `people`, `work_plans` y `form_templates` exigen `.view` en
  lugar de `.export`, así que un perfil de solo lectura puede sacar el listado
  completo de personas con sus documentos de identidad. `customers` y `brands` sí
  lo tienen bien; el arreglo es copiar ese middleware.

---

## 11. Lo que hay que endurecer en el dominio nuevo

Todo lo anterior es de la base heredada. Estas tres son propias de DOCUFIZ, y
están ya implementadas — se apuntan aquí porque son las que no hay que aflojar.

### La decisión de la firma se toma en el servidor

`SignatureService` recalcula la distancia euclidiana contra la biometría enrolada
y persiste `match_distance` y `threshold_used` junto al evento. El navegador manda
el descriptor y la foto; nunca manda un veredicto.

Es la corrección del agujero más grave del sistema anterior, donde el navegador
enviaba `is_approved=1` en un campo oculto y bastaba abrir las herramientas de
desarrollo para firmar como cualquiera.

### Verificación 1:1, nunca 1:N

La persona escribe su documento y el servidor devuelve **solo los descriptores de
esa persona**. En ningún momento el navegador tiene la biometría de toda la
plantilla. La ruta lleva además `throttle:30,1`, para que no se pueda usar como
oráculo de "qué documentos existen".

### Las evidencias no salen por URL

Se sirven por `field_work/evidence/{evidence_file}`, bajo
`permission:signature_events.review`. En el PDF se incrustan como data-uri
leyéndolas del disco. Nunca hay un enlace a una cara que se pueda reenviar.

> **Cuidado al borrar.** Varias filas de `evidence_files` pueden apuntar al mismo
> archivo, porque la misma persona firmando varios formatos del mismo plan y el
> mismo día reutiliza la foto. Borrar una fila **no** puede borrar el archivo sin
> comprobar antes que nadie más lo usa. Hoy no hay ninguna pantalla que borre
> evidencias; cuando la haya, esa comprobación es obligatoria.
