# Crons, Schedulers y Settings — guía completa

**Qué es esto**: explica cómo se conectan las capas que controlan el comportamiento del sistema en caliente: **cron del sistema operativo**, **scheduler de Laravel**, **comandos** y los **ajustes de la BD**.

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
* * * * * cd /var/www/docufiz && php artisan schedule:run >> /dev/null 2>&1

# Backup BD diario a las 02:00 (independiente de Laravel)
0 2 * * * postgres pg_dump docufiz | gzip > /var/backups/docufiz-$(date +\%Y\%m\%d).sql.gz

# Limpieza de backups viejos (más de 14 días)
5 2 * * * find /var/backups/docufiz-*.sql.gz -mtime +14 -delete
```

### B. Supervisor (queue worker)

No es cron, es un proceso persistente que procesa los Jobs del queue. Necesario para que los exports, emails y automations se ejecuten.

```ini
; /etc/supervisor/conf.d/docufiz-queue.conf
[program:docufiz-queue]
command=php /var/www/docufiz/artisan queue:work --sleep=3 --tries=3 --max-time=3600
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
| `app:cleanup-expired-downloads` | cada hora | Borra los archivos de exportación expirados o ya descargados | `routes/console.php` |
| `automations:purge-old-notifications` | cada hora | Borra notificaciones de automatización de más de 12 h, para que la campana no se llene | `routes/console.php` |
| `app:purge-soft-deleted` | diario 03:00 **y** 04:00 | Borra registros borrados hace tiempo, según `config/purge.php` | `routes/console.php` **y** `bootstrap/app.php` |
| `api:purge-idempotency-keys` | diario 04:30 | Borra las claves de idempotencia de la API ya vencidas | `routes/console.php` |
| `subscriptions:check-expirations` | diario 03:00 | Expira suscripciones vencidas y avisa por email "tu plan vence en 7 días" | `bootstrap/app.php` |
| `automations:tick` | cada minuto | Busca automatizaciones con `next_run_at <= now()` y las despacha a la cola | `bootstrap/app.php` |

> **`app:purge-soft-deleted` está agendado dos veces**: a las 03:00 en
> `routes/console.php` y a las 04:00 en `bootstrap/app.php`. `withoutOverlapping()`
> evita que choquen, pero corre dos veces al día sin motivo. Quitar una antes de
> producción.

> **Dos schedules apuntan a comandos que ya no existen.** `routes/console.php`
> agenda `reports:purge-chart-cache` (domingos 03:30) y `reports:purge-frozen`
> (diario). Los dos eran del flujo de informes de diagnóstico y se fueron con la
> purga; sus clases no están en `app/Console/Commands/`. Cada corrida falla y
> ensucia `storage/logs/purge.log`. Se arregla borrando esas dos entradas; es
> cambio de código, no de este documento.

### D. Los comandos de migración del sistema anterior

No están agendados y no deben estarlo: se corren a mano, una vez, en tu máquina.

| Comando | Para qué |
|---|---|
| `docufiz:migrate-formats` | Crea las plantillas AST, PTF, EPP e IHM con sus catálogos reales. `--fresh` las rehace |
| `docufiz:migrate-data {empresas\|personas\|todo}` | Migra empresas y personas desde el MySQL del sistema v1 |

Los dos son idempotentes: cada fila migrada guarda su `legacy_id`, así que
repetirlos no duplica nada. El procedimiento está en
[`BASE-DE-DATOS-LOCAL.md`](BASE-DE-DATOS-LOCAL.md).

---

## 3. Settings disponibles

Los siembra [`SettingsSeeder.php`](../database/seeders/SettingsSeeder.php), que es idempotente: `php artisan db:seed --class=SettingsSeeder` no duplica ni pisa lo que el super haya editado.

Los lee el código con `\App\Models\Setting::get($key, $default)`, `getBool()` o `getInt()`. El modelo cachea por request para no repetir la consulta.

### Grupo `app`

| Key | Tipo | Sembrado | Para qué |
|---|---|---|---|
| `app.maintenance_mode` | bool | false | Bloquea el acceso (excepto super) y muestra la página 503. |
| `app.support_email` | string | `soporte@example.com` | Email de contacto que se muestra al usuario. |
| `app.name` | string | `DOCUFIZ` | Nombre comercial en login, cabecera, correos y título del navegador. **Esta es la fuente de verdad**, no `APP_NAME` del `.env`. |
| `app.logo_url` | string | (vacío) | URL del logo. Si está vacío se muestra solo el nombre. |
| `app.default_locale` | string | `es` | Idioma que reciben los usuarios nuevos. |
| `legal.terms_version` | string | `1.0` | Versión vigente de Términos y Privacidad. **Subirla obliga a todos los usuarios a aceptar de nuevo** en su siguiente sesión, y la aceptación queda registrada con fecha y versión. Es la palanca de LPDP, no un número decorativo. |

### Grupo `features` (interruptores globales)

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
| `exports.max_csv_rows` | int | 0 | 0 = sin límite. El CSV se transmite en streaming y no carga RAM. |
| `exports.max_excel_rows` | int | 25000 | El Spreadsheet se arma entero en RAM. |
| `exports.max_pdf_rows` | int | 500 | dompdf renderiza todo en RAM. El PDF es formato presentable, no volcado de datos. |
| `exports.max_word_rows` | int | 1000 | Mismo criterio con PHPWord. |

### Grupo `downloads` (vida útil de los archivos exportados)

| Key | Tipo | Sembrado | Para qué |
|---|---|---|---|
| `downloads.expire_after_hours` | int | 24 | Horas que vive un archivo desde que se crea. Después se borra del disco y queda solo el registro de auditoría. |
| `downloads.grace_hours` | int | 24 | Horas extra que se conserva el archivo **después** de que el usuario ya lo descargó, por si quiere bajarlo otra vez. |
| `downloads.stale_processing_minutes` | int | 30 | Si una descarga lleva más de N minutos en "procesando", se marca como fallida. Es el detector de "el `queue:work` no está corriendo". Debe ser mayor que el timeout del job. |

### Grupo `notifications`

| Key | Tipo | Sembrado | Para qué |
|---|---|---|---|
| `notifications.poll_interval_seconds` | int | 30 | Cada cuántos segundos el frontend pregunta si hay notificaciones nuevas. El cliente lo acota a [1, 60]. |
| `notifications.email_enabled` | bool | true | Si es `false`, las notificaciones aparecen solo en la campana y no salen correos. |

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
| `audit.retention_days` | int | 365 | Días que se conservan los registros de auditoría antes de purgarlos. |

### Los ajustes de la firma facial — se leen, pero NO se siembran

`SignatureService` y `SignatureController` consultan cuatro claves del grupo
`docufiz` que **`SettingsSeeder` no crea**. Como `Setting::get()` devuelve `null`
si la clave no existe, cada lectura cae a su valor por defecto en código:

| Key | Valor efectivo hoy | Qué controla |
|---|---|---|
| `docufiz.always_store_photo` | `true` | Si se guarda la foto en **todas** las firmas, no solo cuando el reconocimiento falla. |
| `docufiz.face_threshold` | el del código | Distancia máxima para dar por reconocida a una persona. |
| `docufiz.face_timeout_seconds` | `30` | Segundos antes de pasar a captura por tiempo de espera. |
| `docufiz.face_liveness` | `true` | Si se exige el gesto del reto de vida. |

Funcionan, pero **no se pueden cambiar desde la interfaz** porque no hay fila que
editar. Sembrarlas es la tarea pendiente; hasta entonces, tocar el umbral exige
un despliegue, que es justo lo que este mecanismo existe para evitar. El
significado de cada una está en [`BIOMETRIA.md`](BIOMETRIA.md).

### Ajustes heredados que ya no lee nadie

Tres claves siguen sembradas y visibles en la pantalla de Configuración, pero
ningún código las consulta. Son restos de TRAFODEX y conviene quitarlas de
`SettingsSeeder` para que nadie las cambie creyendo que hacen algo:

| Key | Qué era |
|---|---|
| `fleet_report.pdf_max_transformers` | Tope de transformadores en el PDF de flota |
| `reports.frozen_retention_years` | Retención del archivo del informe de diagnóstico aprobado |
| `diagnostics.cell_alert_sev` | Filtro del amarillo en la grilla de diagnóstico |

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

Un ajuste sembrado no es un ajuste que haga algo. Esta sección es la lista de los
que sí, y la de los que no.

### Conectados

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
| `downloads.stale_processing_minutes` | `CleanupExpiredDownloads::handle()` | Minutos antes de dar por fallida una descarga atascada en "procesando" |
| `notifications.poll_interval_seconds` | `AppLayout.vue::startInboxPolling` vía shared prop | Frecuencia del polling del bell (clamp [1, 60]) |
| `notifications.email_enabled` | `DownloadReady::via()`, `DownloadFailed::via()`, `PlanChanged::via()`, `EmailAction::execute()`, `CheckSubscriptionExpirations` | Apaga TODOS los emails sin desactivar el bell |
| `security.session_lifetime_minutes` | `SettingsServiceProvider` override `config('session.lifetime')` | Minutos de inactividad antes de cerrar sesión |
| `uploads.user_photo_max_mb` | User `StoreRequest` / `UpdateRequest` rule `max:N` | Tope upload de foto de perfil |
| `uploads.tenant_logo_max_mb` | Tenant `StoreRequest` rule `max:N` | Tope upload de logo workspace |
| `audit.retention_days` | `PurgeSoftDeleted::purgeModule('audit_logs')` | Días que se mantienen los registros de auditoría antes de purgarlos |
| `legal.terms_version` | Comprobación de aceptación de términos | Subirla obliga a todos a aceptar de nuevo |

### Sembrados, pero sin conectar

| Setting | Pendiente porque |
|---|---|
| `security.max_login_attempts` | El login no implementa bloqueo por intentos. Cuando se implemente, leer este ajuste. |
| `security.lockout_minutes` | Ídem, para la duración del bloqueo. |

### Conectados, pero sin sembrar

Los cuatro `docufiz.*` de la firma facial. Los lee el código con un valor por
defecto y funcionan; lo que no se puede es cambiarlos desde la interfaz. Ver la
sección 3.

### Ni sembrados ni conectados

`fleet_report.pdf_max_transformers`, `reports.frozen_retention_years` y
`diagnostics.cell_alert_sev` están sembrados pero no los lee nadie. Sección 3.

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
supervisorctl restart docufiz-queue:*
```

---

## 6. Cache de Settings

`Setting::get()` cachea por request (estático en memoria). Si actualizas un setting desde la UI:

- El cambio es **inmediato para requests nuevas**.
- Si tu request actual ya leyó el valor, sigue con el viejo hasta que termine.

Si necesitas forzar refresh dentro de una misma request: `Setting::flushCache()`.

**Para queue workers que viven en memoria** (`queue:work`): hay que reiniciarlos para que tomen settings nuevos. En supervisor:

```bash
supervisorctl restart docufiz-queue:*
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

Ejemplo de cron (ya en `crontab` o `/etc/cron.d/docufiz-backup`):

```cron
# Backup diario a las 02:00, retención de 14 días
0 2 * * * postgres pg_dump docufiz | gzip > /var/backups/docufiz-$(date +\%Y\%m\%d).sql.gz
5 2 * * * find /var/backups/docufiz-*.sql.gz -mtime +14 -delete
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
| La campana tarda en actualizarse | **Bajar** `notifications.poll_interval_seconds` (está en 30 s), o la cola va lenta. |
| Una descarga se queda en "procesando" para siempre | No hay `queue:work` corriendo. Pasados `downloads.stale_processing_minutes` el cron horario la marca como fallida. |
| Los archivos de download no se borran nunca | Falta el cron del SO, o el comando `app:cleanup-expired-downloads` falla. Revisar `storage/logs/cleanup-downloads.log`. |

---

## 11. Documentación relacionada

- [`AUTOMATIONS.md`](AUTOMATIONS.md) — `automations:tick` corre cada minuto sobre este scheduler
- [`MAIL-SETUP.md`](MAIL-SETUP.md) — toggle `notifications.email_enabled` controla envío de correos
- [`USAGE.md`](USAGE.md) — UI de Settings (super only) para editar los ajustes sin redeploy
- [`PERMISSIONS.md`](PERMISSIONS.md) — quién puede tocar Settings (super only)
- [`../README-PROD.md`](../README-PROD.md) — cómo se monta supervisor + cron del SO en producción
- [`DEPLOY.md`](DEPLOY.md) — stack proyectado para producción
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — errores comunes con queues y schedulers
