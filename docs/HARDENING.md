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

Patrón estándar para módulos nuevos vía `make:module`: el template clona
`create_customers_table.php` que ya trae el FK, así que se hereda
automáticamente. NO hay que recordar agregarlo a mano.

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
enumeración de emails y brute-force.

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

**Patrón aplicado a 10 jobs** (Customers, Users, Roles, Automations,
Countries, Languages, Locales, Regions, Settings, SystemModules, Tenants):

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

**Patrón aplicado a 33 jobs Excel/PDF/Word** (todos los `Generate*ExcelJob`,
`Generate*PdfJob`, `Generate*WordJob`):

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

Aplicado en 8 modelos: `Customer`, `Country`, `Language`, `Locale`,
`Region`, `Setting`, `SystemModule`, `Tenant`. 17 queries totales.

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

Estado a 2026-05-18:

- **`enforceMorphMap`**: no aplicado. Refactor futuro de namespaces puede
  romper polymorphic FKs históricos (audit_log, user_favorites).
- **`UserController` route binding tenant check**: User usa `BelongsToTenant`
  trait que filtra reads via global scope — los writes ya están protegidos
  por el FormRequest. Verificar caso por caso si aparece un endpoint que
  haga lookup directo sin scope.
- **`mimes:` vs `mimetypes:`** en uploads — sigue siendo validación por
  extensión, no por contenido. El nombre del archivo ya se sanitiza, así
  que el riesgo es bajo.
