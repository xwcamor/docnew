# Guía de desarrollo

Todo lo que necesitas para trabajar en este proyecto en tu PC. Setup inicial, comandos del día a día, scaffolds, troubleshooting.

> **Antes de leer esto**, revisa el [README.md](README.md) general para entender qué es el sistema y cómo está estructurado.

---

## 1. Setup inicial (PC nueva)

### 1.1. Requisitos

- **PHP 8.3** con extensiones: `pdo_pgsql`, `mbstring`, `xml`, `bcmath`, `zip`, `intl`, `fileinfo`, `gd`
- **PostgreSQL 16** con extensión `unaccent` instalada
- **Composer 2**
- **Node.js 22** + npm
- **Git**

En Windows + Laragon, todo viene preinstalado salvo Postgres. Detalle en [`docs/INSTALL-TOOLS.md`](docs/INSTALL-TOOLS.md).

> **PATH**: todos los comandos de esta guía asumen que `php`, `composer`, `node`
> y `npm` están en el PATH de la terminal. Con Laragon: menú → **Tools → Path →
> Add Laragon to Path** (una sola vez) y reabrir la terminal. Verifica con
> `php -v` y `node -v`. Si `php -v` muestra otra versión, ajusta la versión
> activa desde el menú de Laragon (PHP → Version).

### 1.2. Clonar e instalar

```powershell
git clone <url-del-repo> trafodex
cd trafodex

composer install
npm install
```

> `vendor/` y `node_modules/` no están en el repo (los excluye `.gitignore`).

### 1.3. Crear la base de datos

```sql
-- En psql
CREATE DATABASE trafodex;
\c trafodex
CREATE EXTENSION IF NOT EXISTS unaccent;
```

### 1.4. Configurar `.env`

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

Edita `.env`:

```env
APP_NAME="Base App"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=trafodex
DB_USERNAME=postgres
DB_PASSWORD=<tu-password-de-postgres>

# Mail — en dev usar log para ver emails en storage/logs/laravel.log.
# Para probar con SMTP real (Gmail App Password, Mailgun, SES, Postmark)
# ver docs/MAIL-SETUP.md con la guia paso a paso de cada proveedor.
MAIL_MAILER=log
MAIL_FROM_ADDRESS=noreply@localhost
MAIL_FROM_NAME="${APP_NAME}"

# Locale
APP_LOCALE=es
APP_FALLBACK_LOCALE=en
APP_TIMEZONE=UTC

# Queue (database driver — no necesita Redis)
QUEUE_CONNECTION=database

# Filesystem
FILESYSTEM_DISK=local
```

Lista completa de variables en [`docs/ENV.md`](docs/ENV.md).

### 1.5. Primera siembra (`setup:project`)

```powershell
php artisan setup:project
```

Esto hace `migrate:fresh --seed` que crea TODA la data:
- 14 idiomas (es/en + 12 más)
- 18 locales
- 50+ países
- 5 regiones
- 4 planes (free/basic/pro/enterprise)
- 23 settings globales
- Roles del sistema + permissions
- 4 workspaces demo (Empresa 1, 2, Independiente, Estudio Pérez)
- Suscripciones demo (cada uno con su plan)
- 9 usuarios demo con credenciales

> ⚠️ `setup:project` **drop la BD y la recrea desde cero**. Está bloqueado en `APP_ENV=production` por seguridad. Solo úsalo en dev.

### 1.6. Symlink de storage

```powershell
php artisan storage:link
```

**Importante**: sin este symlink las fotos de usuario y logos de workspace devuelven 404. Es one-shot, idempotente.

### 1.7. Compilar assets

```powershell
npm run build
```

### 1.8. Arrancar

Necesitas 2-3 terminales:

**Terminal 1 — Vite dev server** (con HMR):
```powershell
npm run dev
```

**Terminal 2 — Laravel**:
```powershell
php artisan serve
```

**Terminal 3 (opcional) — Queue worker**:
```powershell
php artisan queue:work
```

Sin queue worker en dev, los exports/emails se encolan en la tabla `jobs` pero no se procesan. Para verlos correr, arrancalo.

### 1.9. Login

| URL | Para qué |
|---|---|
| `http://localhost:8000/es/login` | Login del sistema |
| `http://localhost:8000/docs` | Documentación API (requiere login super/admin) |

**Credenciales demo** (password de todos: `123456`):

| Email | Rol | Workspace | Plan |
|---|---|---|---|
| `super@example.com` | super | — (plataforma) | — |
| `joe@example.com` | admin | Empresa 1 | enterprise |
| `jose@example.com` | Customer Editor (custom) | Empresa 1 | enterprise |
| `pedro@example.com` | Customer Viewer (custom) | Empresa 1 | enterprise |
| `yugi@example.com` | admin | Empresa 2 | pro |
| `luis@example.com` | Customer Editor | Empresa 2 | pro |
| `ana@example.com` | Customer Viewer | Empresa 2 | pro |
| `independiente@example.com` | admin | Independiente | basic |
| `juanperez@example.com` | admin | Estudio Pérez | free |

---

## 2. Comandos del día a día

### Arrancar el entorno

```powershell
# Terminal 1
npm run dev

# Terminal 2
php artisan serve

# Terminal 3 (si tocas exports/emails/automations)
php artisan queue:work
```

### Tests

```powershell
# Suite completa (~30s)
php artisan test

# Un módulo específico
php artisan test --filter=Customer

# Performance (skipea sin Postgres)
php artisan test --group=performance
```

### Build de assets

```powershell
npm run build
```

### Migraciones

```powershell
# Aplicar migraciones nuevas
php artisan migrate

# Rollback de la última
php artisan migrate:rollback --step=1

# Reset TOTAL (DEV ONLY — destructivo)
php artisan setup:project
```

### Caché de flota (`diagnose:fleet-cache`) — cuándo SÍ y cuándo NO

El dashboard lee columnas **cacheadas** en `transformers` (índice de salud,
tipo de falla, condición IEEE, vida del papel…). Ese caché se actualiza SOLO
en la operación normal: cada muestra que se registra o edita recalcula su
trafo al instante.

Al **editar reglas en el editor** (`/system_management/diagnostic-rules`:
semáforos, pesos del HI, cuadros, umbrales fiquis) el recálculo también es
automático: al guardar se encola el job `RecalculateFleetCache` (corre a los
2 minutos, agrupando varios guardados seguidos en UN recálculo). Scope: el
super recalcula la flota completa; un admin de workspace solo sus trafos.
Requiere `queue:work` corriendo (el mismo de exports/emails). Colores y
etiquetas NO lo disparan (son presentación, no diagnóstico).

```powershell
# Después de setup:project → NO hace falta (ya recalcula al final)
# Después de registrar/editar muestras → NO hace falta (automático)
# Después de editar reglas en el editor → NO hace falta (job automático en queue)
# Solo tras re-sembrar seeders de reglas por consola (o si el queue no corría):
php artisan diagnose:fleet-cache
```

No es un comando de rutina ni un cron — quedó como respaldo manual. En
producción igual: ver la tabla "¿Qué comando EXTRA correr según lo que
cambió el deploy?" en [`docs/DEPLOY.md`](docs/DEPLOY.md).

### Settings y permisos

```powershell
# Re-sembrar settings (idempotente)
php artisan db:seed --class=SettingsSeeder

# Re-sembrar permisos tras agregar uno nuevo
php artisan db:seed --class=RolesAndPermissionsSeeder

# Limpiar cache de Spatie tras cambios manuales
php artisan permission:cache-reset
```

### Ver schedules + ejecutar manualmente

```powershell
# Lista de schedules activos
php artisan schedule:list

# Correr un comando schedulado manualmente
php artisan app:cleanup-expired-downloads --dry-run
php artisan app:purge-soft-deleted --dry-run
php artisan automations:tick
php artisan subscriptions:check-expirations --dry-run
```

### Cache + optimizaciones

```powershell
# Limpiar TODO el cache (config, route, view, event)
php artisan optimize:clear

# Regenerar API docs después de tocar la API
php artisan scribe:generate
```

---

## 3. Crear módulos nuevos con el scaffold

```powershell
php artisan make:module Product --group=BusinessManagement
```

Genera ~51 archivos (controller + service + model + FormRequests + Jobs + Vue pages + Components + migration + factory + lang en 3 idiomas + config). Auto-registra el módulo en `system_modules`, appendea rutas, agrega entries en `polymorphic.php` y `purge.php`.

Los campos base son `name` (required) + `description` (text nullable). Las columnas del dominio (price, stock, FKs) se agregan a mano después del scaffold.

**El scaffold NO toca el sidebar** — eso lo haces manual con icon + traducciones.

> **Guía completa**: [`docs/CREATE-MODULE.md`](docs/CREATE-MODULE.md) — pasos detallados, qué se genera, qué hacer post-scaffold (sidebar, permisos, plan features, campos del dominio), idempotencia + rollback.

---

## 4. Crons en desarrollo

En dev tu PC **no necesita el cron del SO**. Para probar un schedule manualmente:

```powershell
# Disparar TODOS los schedules vencidos (lo que haría el cron del SO)
php artisan schedule:run

# Disparar uno específico
php artisan app:cleanup-expired-downloads
php artisan automations:tick
```

Detalle de los 4 schedules activos en [`docs/CRONS-AND-SETTINGS.md`](docs/CRONS-AND-SETTINGS.md#2-los-crons-que-corren-en-producción).

---

## 5. Settings — editar comportamiento sin redeploy

23 settings globales editables desde la UI: Sidebar (super) → System Management → **Configuración**.

Para usar un setting en código:

```php
use App\Models\Setting;

$grace = Setting::getInt('downloads.grace_hours', 24);
$flag  = Setting::getBool('features.audit_log_enabled', true);
$str   = Setting::get('app.support_email', 'soporte@example.com');
```

Para agregar un setting nuevo:
1. Agregar la entrada en [`SettingsSeeder.php`](database/seeders/SettingsSeeder.php) con tipo, default, descripción
2. Correr `php artisan db:seed --class=SettingsSeeder` (idempotente)
3. Leer el setting desde código con `Setting::get(...)` con fallback al hardcoded

Detalle: [`docs/CRONS-AND-SETTINGS.md`](docs/CRONS-AND-SETTINGS.md#3-settings-disponibles-23-keys-en-9-grupos).

---

## 6. Workflows de cambio común

### Agregar un campo a Customers (o cualquier módulo)

1. Crear migration: `php artisan make:migration add_phone_to_customers_table`
2. Editar la migration, agregar la columna
3. Agregar al `$fillable` del Model
4. Agregar al `Form.vue` (input + binding al `useForm`)
5. Agregar al `Show.vue` (DescriptionsItem)
6. Agregar al `columns.js` si se muestra en el listado
7. Agregar validación en `StoreRequest.php` y `UpdateRequest.php`
8. Agregar al `Factory.php` para tests
9. Agregar al `lang/{es,en}/customers.php` con label
10. `php artisan migrate` + `php artisan test --filter=Customer`

### Cambiar el rol/permiso de un usuario

1. Sidebar → Usuarios → click en el usuario → Edit
2. Cambiar el campo "Perfil" (es el rol)
3. Guardar

### Apagar emails globalmente (mantenimiento)

Sidebar (super) → Configuración → buscar `notifications.email_enabled` → value `false` → guardar.

Hay que reiniciar queue workers si están corriendo (`php artisan queue:restart`).

---

## 7. Troubleshooting

### "La página no carga / pantalla en blanco"

Lo más común:
1. Storage symlink faltante → `php artisan storage:link`
2. Cache stale tras cambios → `php artisan optimize:clear`
3. Browser cache → Ctrl+Shift+R (hard refresh)
4. Vite dev server no corre → arrancar `npm run dev`

### "El email no llega"

En dev, `MAIL_MAILER=log` → los emails se escriben en `storage/logs/laravel.log`. Buscá ahí.

Si está configurado SMTP y aún así no llega:
- Setting `notifications.email_enabled` puede estar en `false` → activar
- Queue worker no corre → arrancar `php artisan queue:work`

### "Error 500 al crear un user"

Verifica que país, locale e idioma estén seedeados:
```powershell
php artisan tinker
>>> App\Models\Locale::count()  // Debería ser > 0
>>> App\Models\Country::count()
```

Si están vacíos: `php artisan db:seed --class=LocalesSeeder` + `CountriesSeeder`.

### "Las imágenes no cargan"

Verifica symlink:
```powershell
Test-Path public/storage
```
Si es `False`, ejecuta `php artisan storage:link`.

### "Tests fallan localmente"

Los tests usan SQLite in-memory. Si fallan:
- `composer dump-autoload -o` por si hay clases nuevas sin cargar
- `php artisan config:clear` por cache de config

### Errores más detallados

[`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md).

---

## 8. Estructura del repo (resumen)

```
app/
  Console/Commands/    Comandos artisan (make:module, cleanup, purge, etc.)
  Http/
    Controllers/       Por grupo: AuthManagement, BusinessManagement, SystemManagement, etc.
    Middleware/        Custom middleware (EnforcePlanFeature, MaintenanceMode, EnforceSubscription)
    Requests/          FormRequests por módulo
  Models/              Eloquent models
  Services/            Lógica de negocio (1 service por módulo)
  Traits/              Auditable, BelongsToTenant, HasFavorites, HasDependents
  Rules/               UniqueNormalizedName (7 módulos), UniqueNormalizedAcrossTenants
  Jobs/                Por grupo, por módulo (exports + bulk)
  Providers/           AppServiceProvider, SettingsServiceProvider

resources/
  js/
    Pages/             Páginas Inertia (Index, Show, Form, Delete, Trash, EditAll por módulo)
    Components/        Componentes Vue por módulo + Common/
    Composables/       useAuth, useViewport, useDateFormat, useModuleFilters, etc.
    Layouts/           AppLayout (logged-in), AuthLayout (login)
    Plugins/i18n.js    Plugin Vue para $t()
  lang/
    {es,en}/*.php      27 archivos cada uno, mismas keys
  views/               Solo PDFs (Dompdf), emails (Blade), maintenance

config/
  features.php         Matriz plan × feature
  polymorphic.php      Allowlist de modelos polimórficos
  purge.php            Configuración del cron de purga
  permission.php       Spatie config
  laravellocalization.php  Locales soportados (es/en)

database/
  migrations/          27 migraciones consolidadas (sin add_/rename_/drop_)
  seeders/             1 por catálogo + Database master seeder
  factories/           1 por modelo

routes/
  auth_management.php  Login, profile, logout
  user_management.php  Users + Roles
  system_management.php  Catálogos super
  business_management.php  Customers + futuros módulos de negocio
  communication.php    Inbox + Messages
  automation_management.php
  api.php              API REST con Sanctum

docs/                  Documentación técnica detallada (linkeada desde los READMEs)
```

Detalle completo en [`docs/STRUCTURE.md`](docs/STRUCTURE.md).

---

## 9. Recordatorio antes de cada commit

> **Esto es una lista de cosas que tú revisas antes de tipear `git commit`, no tareas pendientes del proyecto.** Sirve como pequeño "auto-code-review" para evitar commitear roto.

Cada vez que vayas a hacer commit, repasa mentalmente:

- **Tests pasan**: `php artisan test` → 453 passing
- **Build verde**: `npm run build` → termina OK sin errores
- **Sin argentinismos** en comentarios y strings (usar `tú`, `tienes`, `puedes` — nunca `vos`, `tenés`, `podés`)
- **Settings al día**: si tocaste un Setting, el `SettingsSeeder` lo refleja con el default + descripción
- **Permisos al día**: si agregaste un permiso, está en `RolesAndPermissionsSeeder`
- **Sidebar al día**: si creaste un módulo nuevo con `make:module`, el sidebar lo muestra (`AppLayout.vue` + `lang/{es,en}/sidebar.php`)
- **Rutas válidas**: `php artisan route:list` no tira error (siempre que toques `routes/*.php`)

> No es obligatorio que el commit pase todos los puntos — depende del cambio. Es una lista para no olvidarse de las cosas comunes que se quedan colgadas.

---

## 10. Cuando vayas a producción

Toda la guía de deploy + hardening está en **[README-PROD.md](README-PROD.md)**.

Para entender los conceptos (4 capas de cron, los 23 settings, downloads cleanup, etc.) lee [`docs/CRONS-AND-SETTINGS.md`](docs/CRONS-AND-SETTINGS.md) ANTES de deployar.
