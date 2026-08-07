# Crons, Schedulers y Settings — guía completa

**Qué es esto**: explica cómo se conectan las tres capas que controlan el comportamiento del sistema en tiempo real: **cron del SO**, **scheduler de Laravel**, y los 23 **settings de la BD**.

**Para qué sirve**: saber cuándo tocar un setting (no requiere redeploy), cuándo tocar el scheduler (requiere deploy) y cuándo el cron del SO (requiere acceso al server). Esencial antes de cualquier cambio operativo en producción.

**Cuándo leerlo**: antes del primer deploy a producción, antes de cambiar un setting, o cuando alguna tarea automática no esté disparando como esperabas.

---

## 1. Las cuatro capas (lectura obligada)

```
┌─ Capa 1: CRON del sistema operativo ─────────────────────┐
│  Un único cron job en el server Linux:                    │
│      * * * * * php artisan schedule:run                   │
│  → cada minuto dispara el "scheduler" de Laravel          │
│  → en dev (tu PC) no hace falta                           │
│  → en PROD es OBLIGATORIO, sin esto nada agendado corre  │
└───────────────────────────────────────────────────────────┘
                          ↓
┌─ Capa 2: SCHEDULE de Laravel ────────────────────────────┐
│  En `routes/console.php` + `bootstrap/app.php` defines    │
│  el calendario: qué comando corre cada cuánto.            │
│  → Es CÓDIGO. Cambiarlo = deploy.                         │
└───────────────────────────────────────────────────────────┘
                          ↓
┌─ Capa 3: COMANDOS Artisan ───────────────────────────────┐
│  Scripts PHP en `app/Console/Commands/`. El scheduler los │
│  dispara cuando le toca.                                  │
│  → Son CÓDIGO. Cambiarlos = deploy.                       │
└───────────────────────────────────────────────────────────┘
                          ↓
┌─ Capa 4: SETTINGS (tabla `settings` en BD) ──────────────┐
│  Los comandos LEEN valores de esta tabla para tunear su   │
│  comportamiento. Editable desde la UI por super.          │
│  → Es DATA. Cambiarlo = editar desde UI, NO deploy.       │
└───────────────────────────────────────────────────────────┘
```

**Regla práctica para decidir dónde poner una nueva configuración**:

| Pregunta | Va en |
|---|---|
| ¿A qué hora corre algo? | Schedule (código) |
| ¿Cuántas horas / minutos / unidades? | Setting (DB) |
| ¿Activado / desactivado? | Setting (DB) |
| ¿Backup de la BD? | Cron del SO directo, no Laravel |
| ¿Lista hardcoded que rara vez cambia? | `config/*.php` (no Setting) |

---

## 2. Los crons que corren en producción

### A. Crons del sistema operativo (`crontab` en Linux)

Son 3 entradas. Las dos últimas son **independientes de Laravel** — no las mueve nadie a la BD.

```cron
# Laravel scheduler — dispara TODOS los schedules internos de Laravel.
# Sin este, nada del scheduler corre. Es la pieza más crítica del cron.
* * * * * cd /var/www/trafodex && php artisan schedule:run >> /dev/null 2>&1

# Backup BD diario a las 02:00 (independiente de Laravel)
0 2 * * * postgres pg_dump baseapp | gzip > /var/backups/baseapp-$(date +\%Y\%m\%d).sql.gz

# Limpieza de backups viejos (más de 14 días)
5 2 * * * find /var/backups/baseapp-*.sql.gz -mtime +14 -delete
```

### B. Supervisor (queue worker)

No es cron, es un proceso persistente que procesa los Jobs del queue. Necesario para que los exports, emails y automations se ejecuten.

```ini
; /etc/supervisor/conf.d/baseapp-queue.conf
[program:baseapp-queue]
command=php /var/www/trafodex/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=2
user=deploy
```

### C. Schedules de Laravel (lo que el scheduler dispara automáticamente)

Definidos en [`routes/console.php`](../routes/console.php) y [`bootstrap/app.php`](../bootstrap/app.php). Los verás listados con:

```bash
php artisan schedule:list
```

| Comando | Frecuencia | Para qué | Vive en |
|---|---|---|---|
| `app:cleanup-expired-downloads` | cada hora | Borra archivos de exports expirados o descargados (>24h) | `routes/console.php` |
| `app:purge-soft-deleted` | diario 03:00/04:00 | Borra registros soft-deleted antiguos | `routes/console.php` + `bootstrap/app.php` |
| `subscriptions:check-expirations` | diario 03:00 | Expira subs vencidas + envía email "tu plan vence en 7 días" | `bootstrap/app.php` |
| `automations:tick` | cada minuto | Busca automations con `next_run_at <= now()` y las despacha al queue | `bootstrap/app.php` |

> **Nota**: `app:purge-soft-deleted` aparece dos veces (03:00 en `routes/console.php` y 04:00 en `bootstrap/app.php`). Hay que decidir cuál queda ANTES de producción — anotado en el [backlog](brain/backlog-decisiones.md).

---

## 3. Settings disponibles (23 keys en 9 grupos)

Todos los settings están sembrados por [`SettingsSeeder.php`](../database/seeders/SettingsSeeder.php). Idempotente: correr `php artisan db:seed --class=SettingsSeeder` no duplica.

Los lee el código vía `\App\Models\Setting::get($key, $default)`, `getBool($key, $default)` o `getInt($key, $default)`. El modelo tiene cache request-scoped para no spamear queries.

### Grupo `app`

| Key | Tipo | Default | Para qué |
|---|---|---|---|
| `app.maintenance_mode` | bool | false | Bloquea acceso al sistema (excepto super). Muestra página 503. |
| `app.support_email` | string | `soporte@example.com` | Email mostrado al usuario para contacto. |
| `app.name` | string | `Application Name` | Nombre comercial mostrado en login, header, emails, título del browser. |
| `app.logo_url` | string | (vacío) | URL del logo de marca. Si vacío se muestra solo el nombre. |
| `app.default_locale` | string | `es` | Idioma asignado a usuarios nuevos (es / en). |

### Grupo `features` (toggles globales)

| Key | Tipo | Default | Para qué |
|---|---|---|---|
| `features.audit_log_enabled` | bool | true | Si false, los modelos con trait Auditable no escriben en `audit_logs`. |
| `features.subscription_enforcement_enabled` | bool | false | Bloquea tenants sin sub activa con página 403. Activar SOLO cuando billing esté listo. |
| `features.google_login_enabled` | bool | false | Si false, oculta el botón "Continuar con Google" del login. Requiere credenciales OAuth en `.env`. |

### Grupo `bulk`

| Key | Tipo | Default | Para qué |
|---|---|---|---|
| `bulk.async_threshold` | int | 200 | Si una bulk excede N registros, se manda a queue. |

### Grupo `exports` (límites globales por formato)

| Key | Tipo | Default | Para qué |
|---|---|---|---|
| `exports.max_csv_rows` | int | 0 | 0 = sin límite (streaming). |
| `exports.max_excel_rows` | int | 25000 | Más filas requieren CSV. |
| `exports.max_pdf_rows` | int | 5000 | Render PDF es costoso. |
| `exports.max_word_rows` | int | 10000 | DOCX en RAM. |

### Grupo `downloads` (vida útil de los archivos exportados)

| Key | Tipo | Default | Para qué |
|---|---|---|---|
| `downloads.expire_after_hours` | int | 24 | Cuántas horas vive un export desde que se crea. Tras eso, el archivo se borra y queda solo el registro. |
| `downloads.grace_hours` | int | 24 | Tras una descarga, cuántas horas adicionales se mantiene el archivo (por si el user quiere bajarlo otra vez). |

### Grupo `notifications`

| Key | Tipo | Default | Para qué |
|---|---|---|---|
| `notifications.poll_interval_seconds` | int | 4 | Cada cuántos segundos el frontend pregunta al backend si hay notificaciones nuevas (la campana del header). Clampeado en cliente a [1, 60]. |
| `notifications.email_enabled` | bool | true | Si false, las notificaciones se muestran solo en el bell, no envían email. |

### Grupo `security`

| Key | Tipo | Default | Para qué |
|---|---|---|---|
| `security.session_lifetime_minutes` | int | 120 | Inactividad antes de cerrar sesión. |
| `security.max_login_attempts` | int | 5 | Intentos fallidos antes de lockout. |
| `security.lockout_minutes` | int | 15 | Duración del lockout. |

### Grupo `email` — NO existe en Settings

Las credenciales SMTP y el sender (FROM) viven **siempre en `.env`**:
- `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` (secretos, jamás en BD)
- `MAIL_FROM_NAME`, `MAIL_FROM_ADDRESS` (cambian poco, no justifican setting)

Si necesitas multi-tenant whitelabel (cada workspace con su propio remitente), eso se implementa con un Setting por-tenant en el futuro, no global.

### Grupo `uploads`

| Key | Tipo | Default | Para qué |
|---|---|---|---|
| `uploads.user_photo_max_mb` | int | 2 | Tamaño máximo de la foto de perfil. |
| `uploads.tenant_logo_max_mb` | int | 2 | Tamaño máximo del logo del workspace. |

### Grupo `audit`

| Key | Tipo | Default | Para qué |
|---|---|---|---|
| `audit.retention_days` | int | 365 | Días que se conservan los audit logs antes del purge. |

---

## 4. Cómo conectar un Setting nuevo al código

Patrón estándar — 3 pasos:

### 1) Agregar la entrada en `SettingsSeeder.php`

```php
['key' => 'tu_grupo.tu_key', 'name' => 'Etiqueta visible', 'type' => 'int', 'value' => '24', 'group' => 'tu_grupo', 'description' => 'Para qué sirve.'],
```

Correr: `php artisan db:seed --class=SettingsSeeder`. Idempotente.

### 2) Leerlo desde el código

```php
use App\Models\Setting;

// Con fallback si el setting no existe o está inactive:
$horas = Setting::getInt('tu_grupo.tu_key', 24);
$flag  = Setting::getBool('tu_grupo.tu_key', false);
$str   = Setting::get('tu_grupo.tu_key', 'default');
```

### 3) (Opcional) Pasarlo al frontend

Si el frontend lo necesita, agregarlo a `HandleInertiaRequests::share()`:

```php
'tuKey' => fn () => \App\Models\Setting::getInt('tu_grupo.tu_key', 24),
```

Y en Vue: `page.props.tuKey`.

---

## 5. Wire-ups actuales (qué setting controla qué)

Todos los settings están **conectados al código** salvo los 2 marcados explícitamente como "futuro".

### Conectados (live ya)

| Setting | Lo lee | Efecto |
|---|---|---|
| `app.maintenance_mode` | `MaintenanceMode` middleware | Bloquea acceso con página 503 |
| `app.support_email` | Páginas de error, footer | Email mostrado al usuario |
| `app.name` | `SettingsServiceProvider` override `config('app.name')` + shared prop `appName` | Nombre en login, header, emails, título browser |
| `app.logo_url` | Shared prop `appLogoUrl` | Logo en login (opcional) |
| `app.default_locale` | `UserService::resolveDefaultLocaleId()` | Locale por defecto si la creación del user no manda locale_id |
| `features.audit_log_enabled` | `Auditable` trait | On/off del audit log global |
| `features.subscription_enforcement_enabled` | `EnforceSubscription` middleware | Bloquea tenants sin sub activa |
| `features.google_login_enabled` | Shared prop → `Login.vue` `v-if` | Muestra/oculta botón "Continuar con Google" |
| `bulk.async_threshold` | `Bulk*ActionJob::asyncThreshold()` | Decide inline vs queue |
| `exports.max_csv_rows`, `max_excel_rows`, `max_pdf_rows`, `max_word_rows` | `Setting::getExportLimits()` | Caps por formato en ExportDialog |
| `downloads.expire_after_hours` | `Download::computeExpiresAt()` → 21 jobs | Cuánto vive el archivo desde que se crea |
| `downloads.grace_hours` | `CleanupExpiredDownloads::handle()` | Horas adicionales tras descarga antes de borrar |
| `notifications.poll_interval_seconds` | `AppLayout.vue::startInboxPolling` vía shared prop | Frecuencia del polling del bell (clamp [1, 60]) |
| `notifications.email_enabled` | `DownloadReady::via()`, `DownloadFailed::via()`, `PlanChanged::via()`, `EmailAction::execute()`, `CheckSubscriptionExpirations` | Apaga TODOS los emails sin desactivar el bell |
| `security.session_lifetime_minutes` | `SettingsServiceProvider` override `config('session.lifetime')` | Minutos de inactividad antes de cerrar sesión |
| `uploads.user_photo_max_mb` | User `StoreRequest` / `UpdateRequest` rule `max:N` | Tope upload de foto de perfil |
| `uploads.tenant_logo_max_mb` | Tenant `StoreRequest` rule `max:N` | Tope upload de logo workspace |
| `audit.retention_days` | `PurgeSoftDeleted::purgeModule('audit_logs')` | Días que se mantienen los audit logs antes del purge |

### Futuro (settings sembrados, sin wire-up todavía)

| Setting | Pendiente porque |
|---|---|
| `security.max_login_attempts` | El login no implementa throttle/lockout aún. Cuando se implemente, leer este setting. |
| `security.lockout_minutes` | Idem — duración del lockout. |

Estos están listos en la tabla `settings` y visibles en la UI. Cuando alguien implemente el rate-limit del login, los conecta con `Setting::getInt('security.max_login_attempts', 5)`.

---

## 5b. Cómo funciona el `SettingsServiceProvider`

Vive en [`app/Providers/SettingsServiceProvider.php`](../app/Providers/SettingsServiceProvider.php) y se registra en `bootstrap/app.php`. Al boot del framework lee 2 settings y los **mete dentro de `config(...)`**:

| Setting | Sobreescribe |
|---|---|
| `app.name` | `config('app.name')` |
| `security.session_lifetime_minutes` | `config('session.lifetime')` |

El sender de mail (`mail.from.name`, `mail.from.address`) NO se sobreescribe — vive solo en `.env`.

**Por qué este patrón**: los servicios de Laravel (mail, session, etc.) leen valores de `config()` cuando se inicializan, no son reactivos. Cambiar el setting en la BD no afecta nada si el servicio ya leyó `config()`. La solución: leer los settings al boot y "inyectar" sus valores en config antes que los servicios los lean.

**Limitación**: los procesos persistentes (`queue:work`, `octane`) leen estos valores UNA vez al arrancar. Cambiar el setting requiere reiniciarlos para que tomen el valor nuevo:

```bash
php artisan queue:restart   # workers se reciclan en el próximo job
# o en supervisor:
supervisorctl restart baseapp-queue:*
```

---

## 6. Cache de Settings

`Setting::get()` cachea por request (estático en memoria). Si actualizas un setting desde la UI:

- El cambio es **inmediato para requests nuevas**.
- Si tu request actual ya leyó el valor, sigue con el viejo hasta que termine.

Si necesitas forzar refresh dentro de una misma request: `Setting::flushCache()`.

**Para queue workers que viven en memoria** (`queue:work`): hay que reiniciarlos para que tomen settings nuevos. En supervisor:

```bash
supervisorctl restart baseapp-queue:*
```

O usar `queue:restart` que indica a los workers a reciclarse en el próximo job.

---

## 7. Edición desde la UI

Como super:
1. Sidebar → **System Management** → **Configuración** (módulo Settings)
2. Buscar la key (filtro por nombre o grupo)
3. Editar el campo `value`
4. Guardar — toma efecto inmediato para nuevas requests

> No todos los settings son seguros de cambiar en caliente. Por ejemplo `features.audit_log_enabled = false` deja de loguear pero los jobs ya en queue siguen el comportamiento que tenían al despacharse. Los queue workers necesitan restart para tomar settings nuevos.

---

## 8. Backups de la BD — vive en el cron del SO, no Laravel

Razones técnicas:
- Necesita `pg_dump` del sistema operativo, no PHP.
- Debe correr aunque Laravel esté caído (post deploy fallido).
- Es simple, no necesita la infra de Laravel.

Ejemplo de cron (ya en `crontab` o `/etc/cron.d/baseapp-backup`):

```cron
# Backup diario a las 02:00, retención de 14 días
0 2 * * * postgres pg_dump baseapp | gzip > /var/backups/baseapp-$(date +\%Y\%m\%d).sql.gz
5 2 * * * find /var/backups/baseapp-*.sql.gz -mtime +14 -delete
```

En prod serio se recomienda **DigitalOcean Managed Databases** ($15/mes) que ya incluye backups automáticos + failover gestionados.

---

## 9. Checklist al agregar un nuevo schedule

1. ¿Realmente necesita ser cron? ¿No puede ser un Job en queue disparado por una acción del usuario?
2. ¿Necesita un setting para tunear su comportamiento? Agregarlo al `SettingsSeeder`.
3. ¿Genera Downloads? Usar `Download::computeExpiresAt()` para que respete `downloads.expire_after_hours`.
4. ¿Debe estar en supervisor (proceso persistente) o en cron (corre y termina)? Cron es para tareas cortas, supervisor para procesos largos.
5. Agregar el schedule en `routes/console.php` (con `withoutOverlapping()` para evitar runs concurrentes).
6. Probar manualmente: `php artisan tu:comando` antes de schedular.
7. Confirmar que aparece en `php artisan schedule:list`.

---

## 10. Troubleshooting

| Síntoma | Causa probable |
|---|---|
| Mis schedules no corren en prod | Falta el cron `* * * * * schedule:run` en el server. |
| Cambio un setting y no toma efecto | El queue worker está en memoria — `supervisorctl restart`. |
| Settings::get devuelve null en un test | El seeder no corrió. Agregar `--seed` al `migrate:fresh`. |
| El bell se actualiza muy lento | Subir `notifications.poll_interval_seconds` al revés, o el queue está lento. |
| Los archivos de download no se borran nunca | Falta el cron del SO, o el comando `app:cleanup-expired-downloads` falla. Revisar `storage/logs/cleanup-downloads.log`. |

---

## 11. Documentación relacionada

- [`AUTOMATIONS.md`](AUTOMATIONS.md) — `automations:tick` corre cada minuto sobre este scheduler
- [`MAIL-SETUP.md`](MAIL-SETUP.md) — toggle `notifications.email_enabled` controla envío de correos
- [`USAGE.md`](USAGE.md) — UI de Settings (super only) para editar los 23 settings sin redeploy
- [`PERMISSIONS.md`](PERMISSIONS.md) — quién puede tocar Settings (super only)
- [`../README-PROD.md`](../README-PROD.md) — cómo se monta supervisor + cron del SO en producción
- [`DEPLOY.md`](DEPLOY.md) — stack proyectado para producción
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — errores comunes con queues y schedulers
