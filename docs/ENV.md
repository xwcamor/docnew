# Variables de entorno

**Qué es esto**: referencia completa de las variables del archivo `.env`.

**Para qué sirve**: saber qué variable controla qué, qué valores son válidos y qué hay que limpiar después de cambiarlas.

**Cuándo leerlo**: al setear `.env` la primera vez, al preparar `.env` de producción, al cambiar de proveedor de correo o storage, o cuando algo "no toma" el valor nuevo (típicamente porque falta `php artisan config:clear`).

> **Nunca commitear `.env`.** Solo `.env.example` que sirve de plantilla sin secrets.

---

## Aplicación

| Variable | Default | Descripción |
|---|---|---|
| `APP_NAME` | `Laravel` | Nombre que se ve en títulos, emails, etc. |
| `APP_ENV` | `local` | Entorno actual: `local`, `staging`, `production` |
| `APP_KEY` | — | Generado con `php artisan key:generate`. Usado para cifrado |
| `APP_DEBUG` | `true` | En producción debe ser `false` |
| `APP_URL` | `http://localhost` | URL pública de la app |
| `APP_LOCALE` | `en` | Idioma por defecto |
| `APP_FALLBACK_LOCALE` | `en` | Idioma de respaldo si falta una traducción |

---

## Base de datos (PostgreSQL recomendado)

| Variable | Default | Descripción |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | Driver: `pgsql`, `mysql`, `sqlite` |
| `DB_HOST` | `127.0.0.1` | Host de la BD |
| `DB_PORT` | `5432` | Puerto (PG: 5432, MySQL: 3306) |
| `DB_DATABASE` | `trafodex` | Nombre de la BD |
| `DB_USERNAME` | `laravel` | Usuario |
| `DB_PASSWORD` | — | Password |

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

> Solo las variables prefijadas con `VITE_` son accesibles desde el código JS.

---

## Sentry (opcional — no activado por default)

| Variable | Default | Descripción |
|---|---|---|
| `SENTRY_LARAVEL_DSN` | (vacío) | DSN del proyecto en sentry.io. Sin esto, el SDK no envía nada |
| `SENTRY_TRACES_SAMPLE_RATE` | `0` | Porcentaje de transacciones que se capturan (0 = ninguno, 1 = todas) |
| `SENTRY_SEND_DEFAULT_PII` | `false` | Si `true` envía info del user (email, IP). Cuidado con GDPR |
| `VITE_SENTRY_DSN_PUBLIC` | (vacío) | DSN para el frontend Vue (separado del backend, usa Sentry browser SDK) |

Detalle de cómo activar Sentry: [`SENTRY.md`](SENTRY.md).

---

## Google OAuth (opcional)

| Variable | Default | Descripción |
|---|---|---|
| `GOOGLE_CLIENT_ID` | (vacío) | Client ID de Google Cloud Console |
| `GOOGLE_CLIENT_SECRET` | (vacío) | Secret correspondiente |
| `GOOGLE_REDIRECT_URI` | `http://localhost:8000/auth/google/callback` | URL de callback registrada en Google |

Adicionalmente, activar el setting `features.google_login_enabled = true` en la UI para mostrar el botón "Continuar con Google" en el login.

---

## Plantilla mínima (`.env` para desarrollo)

```env
APP_NAME="Base App"
APP_ENV=local
APP_KEY=                          # Generar con: php artisan key:generate
APP_DEBUG=true
APP_URL=http://trafodex-main.test
APP_LOCALE=es
APP_FALLBACK_LOCALE=es

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=trafodex
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
