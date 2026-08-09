# Variables de entorno

**Qué es esto**: referencia completa de las variables del archivo `.env`.

**Para qué sirve**: saber qué variable controla qué, qué valores son válidos y qué hay que limpiar después de cambiarlas.

**Cuándo leerlo**: al setear `.env` la primera vez, al preparar `.env` de producción, al cambiar de proveedor de correo o storage, o cuando algo "no toma" el valor nuevo (típicamente porque falta `php artisan config:clear`).

> **Nunca commitear `.env`.** Solo `.env.example` que sirve de plantilla sin secrets.

---

## Aplicación

| Variable | Default | Descripción |
|---|---|---|
| `APP_NAME` | `DOCUFIZ` | Nombre de arranque. **No es la fuente de verdad**: el setting `app.name` de la BD lo pisa al boot (ver [`CRONS-AND-SETTINGS.md`](CRONS-AND-SETTINGS.md#5b-cómo-funciona-el-settingsserviceprovider)) |
| `APP_ENV` | `local` | Entorno actual: `local`, `staging`, `production` |
| `APP_KEY` | — | Generado con `php artisan key:generate`. Usado para cifrado |
| `APP_DEBUG` | `false` en el ejemplo | `true` solo en local, y activa la DebugBar. En producción, `false` |
| `APP_URL` | `http://localhost` | URL pública de la app |
| `APP_LOCALE` | `en` | Idioma por defecto |
| `APP_FALLBACK_LOCALE` | `en` | Idioma de respaldo si falta una traducción |

---

## Logs

| Variable | Default | Descripción |
|---|---|---|
| `LOG_CHANNEL` | `stack` | Canal principal |
| `LOG_STACK` | `daily` | Un archivo por día con rotación. Sin esto `laravel.log` crece sin tope y termina llenando el disco del droplet |
| `LOG_LEVEL` | `debug` | En producción conviene `warning` o `error` |

---

## Base de datos (PostgreSQL recomendado)

| Variable | Default | Descripción |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | Driver: `pgsql`, `mysql`, `sqlite` |
| `DB_HOST` | `127.0.0.1` | Host de la BD |
| `DB_PORT` | `5432` | Puerto (PG: 5432, MySQL: 3306) |
| `DB_DATABASE` | `docufiz` | Nombre de la BD |
| `DB_USERNAME` | `laravel` | Usuario |
| `DB_PASSWORD` | — | Password |

### La base del sistema anterior (solo en tu máquina)

Los comandos de migración leen el MySQL del sistema v1 por la conexión `legacy`,
declarada en [`config/database.php`](../config/database.php). Es de **solo
lectura** y solo hace falta mientras migras; en producción no se define.

| Variable | Default | Descripción |
|---|---|---|
| `LEGACY_DB_HOST` | `127.0.0.1` | Host del MySQL con el volcado del sistema viejo |
| `LEGACY_DB_PORT` | `3306` | |
| `LEGACY_DB_DATABASE` | `doc_app_development` | Base importada del volcado |
| `LEGACY_DB_USERNAME` | `root` | |
| `LEGACY_DB_PASSWORD` | (vacío) | |

El procedimiento completo está en [`BASE-DE-DATOS-LOCAL.md`](BASE-DE-DATOS-LOCAL.md).

---

## Consultas a SUNAT y RENIEC (apis.net.pe)

Un solo token para las dos consultas que hacía el sistema anterior y que aquí se
recuperaron:

- **RUC en SUNAT** → al teclear un RUC de 11 dígitos en el alta de una empresa,
  rellena la razón social. ([`ConsultaRuc`](../app/Services/Peru/ConsultaRuc.php))
- **DNI en RENIEC** → al teclear un DNI de 8 dígitos en el alta de una persona,
  rellena nombre y apellidos y los deja bloqueados. Solo Perú y solo DNI: un
  carné de extranjería, un PTP o un pasaporte no están en RENIEC.
  ([`ConsultaDni`](../app/Services/Peru/ConsultaDni.php))

Es el mismo token que la v1 guardaba en las credenciales de Rails como
`pe_reniec_token`. Se saca en <https://apis.net.pe>.

| Variable | Default | Descripción |
|---|---|---|
| `APIS_NET_PE_URL` | `https://api.apis.net.pe` | |
| `APIS_NET_PE_TOKEN` | (vacío) | Sin él no se consulta nada y todo se escribe a mano |
| `APIS_NET_PE_TIMEOUT` | `6` | Segundos antes de rendirse |

**Sin token no es un error.** Las dos consultas degradan sin estorbar: sin
credencial, con la API caída o con un número que no existe, devuelven un estado,
la pantalla deja escribir a mano y el alta sigue. En obra se registra la cuadrilla
a las seis de la mañana y no se espera a un tercero.

El acierto de RENIEC se guarda en caché 30 días (un nombre no cambia); el fallo
no, porque el que no aparece hoy puede aparecer mañana.

---

## Sesión y cache

| Variable | Default | Descripción |
|---|---|---|
| `SESSION_DRIVER` | `database` | Dónde se guardan las sesiones: `file`, `database`, `redis` |
| `SESSION_LIFETIME` | `120` | Minutos antes de expirar |
| `CACHE_STORE` | `database` | `file`, `database`, `redis`, `memcached` |
| `QUEUE_CONNECTION` | `database` | Cola para jobs: `database`, `redis`, `sync` |

> **Recomendación**: en producción usa `redis` para los tres (más rápido).

---

## Email

| Variable | Default | Descripción |
|---|---|---|
| `MAIL_MAILER` | `log` | En desarrollo: `log` (revisa `storage/logs/laravel.log`). En producción: `smtp` |
| `MAIL_HOST` | `smtp.gmail.com` | Servidor SMTP |
| `MAIL_PORT` | `587` | |
| `MAIL_USERNAME` | — | Email del remitente (Gmail) o usuario del proveedor SMTP |
| `MAIL_PASSWORD` | — | Clave de correo. **NO** es tu contraseña personal de Gmail — es una **App Password** generada aparte. Ver guía |
| `MAIL_ENCRYPTION` | `tls` | |
| `MAIL_FROM_ADDRESS` | — | Email remitente |
| `MAIL_FROM_NAME` | `${APP_NAME}` | Nombre del remitente |

> **Cómo generar la App Password de Gmail y configurar SMTP completo**: guía paso a paso con troubleshooting en [`docs/MAIL-SETUP.md`](MAIL-SETUP.md) — cubre Gmail, Mailgun, AWS SES y Postmark.

---

## Login con Google (Socialite)

| Variable | Descripción |
|---|---|
| `GOOGLE_CLIENT_ID` | Obtener en https://console.cloud.google.com/ |
| `GOOGLE_CLIENT_SECRET` | |
| `GOOGLE_REDIRECT_URI` | Debe coincidir con la URL configurada en Google Cloud Console |

Pasos para configurar Google OAuth:
1. Ve a Google Cloud Console → crear proyecto.
2. **APIs & Services** → **OAuth consent screen** → llenar (External, modo testing).
3. **APIs & Services** → **Credentials** → **Create credentials** → **OAuth client ID**.
4. Tipo: **Web application**.
5. **Authorized redirect URIs**: `http://localhost:8000/auth/google/callback` (y la de producción).
6. Copia `Client ID` y `Client Secret` al `.env`.

Con las credenciales no basta: el botón "Continuar con Google" solo aparece si el
setting `features.google_login_enabled` está en `true` (se edita desde la UI del
super, sin redeploy).

---

## Storage / Filesystem

| Variable | Default | Descripción |
|---|---|---|
| `FILESYSTEM_DISK` | `local` | `local` (storage/app), `public` (storage/app/public), `s3` |

Para migrar a DigitalOcean Spaces u otro S3-compatible más adelante:
```env
FILESYSTEM_DISK=spaces
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=mi-bucket
AWS_ENDPOINT=https://nyc3.digitaloceanspaces.com
```

---

## Vite

| Variable | Default | Descripción |
|---|---|---|
| `VITE_APP_NAME` | `${APP_NAME}` | Nombre disponible en JS via `import.meta.env.VITE_APP_NAME` |
| `VITE_AMCHARTS_LICENSE` | (vacío) | Licencia de amCharts 5, aplicada del lado del cliente en [`AmGauge.vue`](../resources/js/Components/Common/AmGauge.vue). Vacío = marca de agua |

> Solo las variables prefijadas con `VITE_` son accesibles desde el código JS.
> Cambiarlas exige **volver a compilar** (`npm run build`): quedan horneadas en el
> bundle, no se leen en tiempo de ejecución.

---

## Sentry (opcional — no activado por default)

| Variable | Default | Descripción |
|---|---|---|
| `SENTRY_LARAVEL_DSN` | (vacío) | DSN del proyecto en sentry.io. Sin esto, el SDK no envía nada |
| `SENTRY_TRACES_SAMPLE_RATE` | `0` | Porcentaje de transacciones que se capturan (0 = ninguno, 1 = todas) |
| `SENTRY_PROFILES_SAMPLE_RATE` | `0.0` | Profiling, opcional |
| `SENTRY_SEND_DEFAULT_PII` | `false` | Si `true` envía info del user (email, IP). Cuidado con GDPR |

El SDK **no está instalado** (`sentry/sentry-laravel` no está en `composer.json`).
Estas variables están en `.env.example` para cuando se instale; hoy no las lee
nadie. Detalle en [`SENTRY.md`](SENTRY.md).

---

## Plantilla mínima (`.env` para desarrollo)

```env
APP_NAME="DOCUFIZ"
APP_ENV=local
APP_KEY=                          # Generar con: php artisan key:generate
APP_DEBUG=true
APP_URL=http://docufiz.test
APP_LOCALE=es
APP_FALLBACK_LOCALE=es

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=docufiz
DB_USERNAME=laravel
DB_PASSWORD=secret

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=log

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback
```

---

## Después de cambiar `.env`

Siempre limpia caché:
```bash
php artisan config:clear
```

Y si modificaste `APP_ENV` (ej: para probar producción local), también:
```bash
php artisan optimize:clear
```

---

## Documentación relacionada

- [`MAIL-SETUP.md`](MAIL-SETUP.md) — variables `MAIL_*` con guía paso a paso por proveedor
- [`SENTRY.md`](SENTRY.md) — variables `SENTRY_*` para activar error tracking en prod
- [`INSTALL-TOOLS.md`](INSTALL-TOOLS.md) — preparar Postgres + extensiones antes de poblar `DB_*`
- [`../README-DEV.md`](../README-DEV.md) — `.env` mínimo para desarrollo local
- [`../README-PROD.md`](../README-PROD.md) — `.env` mínimo para producción
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — errores comunes por `.env` mal configurado
