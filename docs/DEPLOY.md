# Deploy a producción — complemento técnico

**Qué es esto**: complemento técnico de [`README-PROD.md`](../README-PROD.md). Aquí viven decisiones de stack y alternativas evaluadas.

**Para qué sirve**: revisar el stack proyectado antes del primer deploy real. Para los pasos operativos del deploy, la fuente principal es `README-PROD.md` (este archivo es referencia adicional).

**Cuándo leerlo**: antes del primer deploy real a DigitalOcean o cuando evalúes cambios de infraestructura.

---

## Stack de producción previsto

| Capa | Tecnología | Notas |
|---|---|---|
| **Servidor** | DigitalOcean Droplet (Ubuntu 22.04 LTS) | Empezar con 2GB RAM ($12/mes) |
| **Web server** | Nginx | Reverse proxy + servir estáticos |
| **PHP** | PHP 8.3-FPM | Worker process model |
| **DB** | PostgreSQL 16 | Local en el mismo Droplet (no managed por ahora) |
| **Cache / Sessions / Queues** | `database` driver (Postgres) | Decisión deliberada: sin Redis. Sub-1ms con índices Postgres es suficiente para los volúmenes esperados |
| **Queues** | Supervisor + `queue:work` | Para exports, emails, jobs pesados |
| **SSL** | Cloudflare (DNS + SSL gratis) | O Let's Encrypt con Certbot |
| **Storage** | Local, disco privado | Las fotos de firma son el único archivo que crece solo. Con las medidas de `docs/BIOMETRIA.md` van del orden de 1 GB al año; la recomendación para producción es DO Spaces, no el disco del droplet |
| **Backups** | Snapshots de DigitalOcean (+20%) | $2.40/mes en plan 2GB |

> **Redis es opcional** y NO se usa hoy. Solo tendrá sentido sumarlo cuando escales a múltiples app servers (donde las sesiones HTTP deben ser compartidas).

---

## Requisitos previos al primer deploy

- [ ] Cuenta en DigitalOcean
- [ ] Dominio comprado (Namecheap, Cloudflare Registrar, etc.)
- [ ] DNS apuntando al Droplet (vía Cloudflare)
- [ ] Repositorio Git accesible desde el servidor (GitHub/GitLab)
- [ ] Variables de producción listas (`.env.production` separado)
- [ ] Llave SSH agregada a DigitalOcean

---

## Flujo resumido (versión rápida)

```bash
# En el servidor (Ubuntu 22.04, vía SSH como root)
apt update && apt upgrade -y
apt install -y nginx php8.3-fpm php8.3-pgsql php8.3-mbstring php8.3-xml \
                php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath \
                postgresql-16 supervisor git curl \
                ufw certbot python3-certbot-nginx

# Node 20
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs

# Composer
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

# Clonar el proyecto
cd /var/www
git clone <repo-url> docufiz
cd docufiz

# Configurar PostgreSQL (crear DB y usuario).
# Si en el mismo droplet conviven TRAFODEX y tenkofiz, usa el guion de
# docs/DESPLIEGUE-POSTGRES.md en vez de estas cinco lineas: alli se revoca
# el CONNECT a PUBLIC, que es lo unico que aisla de verdad una base de otra.
sudo -u postgres psql -c "CREATE DATABASE docufiz;"
sudo -u postgres psql -c "CREATE USER laravel WITH ENCRYPTED PASSWORD 'STRONG_PASSWORD';"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE docufiz TO laravel;"
sudo -u postgres psql -d docufiz -c "GRANT ALL ON SCHEMA public TO laravel;"
sudo -u postgres psql -d docufiz -c "ALTER SCHEMA public OWNER TO laravel;"

# OBLIGATORIO: activar extensión unaccent (case + accent insensitive search)
sudo -u postgres psql -d docufiz -c "CREATE EXTENSION IF NOT EXISTS unaccent;"

# Instalar deps
composer install --no-dev --optimize-autoloader
npm ci
npm run build

# Configurar .env
cp .env.example .env
# Editar .env con APP_ENV=production, APP_DEBUG=false, secrets reales
nano .env
php artisan key:generate

# Migrar (NUNCA setup:project en producción)
php artisan migrate --force
php artisan db:seed --force  # solo la primera vez

# Cachear (route:cache NO — ver la nota de abajo)
php artisan config:cache
php artisan view:cache
php artisan storage:link

# Permisos
chown -R www-data:www-data /var/www/docufiz
chmod -R 755 /var/www/docufiz
chmod -R 775 /var/www/docufiz/storage
chmod -R 775 /var/www/docufiz/bootstrap/cache

# Configurar Nginx (ver sección abajo)
# Configurar Supervisor para queues (ver sección abajo)
# Configurar Certbot SSL (ver sección abajo)

# Firewall
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw enable
```

---

## Redeploys incrementales (deploys del día a día en prod)

Con data real ya cargada, los deploys son **incrementales** — nunca `setup:project`
ni `migrate:fresh` (borrarían la data). Flujo estándar:

```bash
cd /var/www/docufiz
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force        # NUNCA setup:project / migrate:fresh
php artisan optimize:clear
php artisan config:cache
php artisan view:cache             # route:cache NO (hay closures, peta)
php artisan storage:link
php artisan queue:restart          # avisa a los workers de Supervisor que recarguen el código
sudo systemctl restart php8.3-fpm
```

### ¿Qué comando EXTRA correr según lo que cambió el deploy?

El flujo de arriba cubre código, assets, migraciones y cachés. Lo único que NO se
refresca solo es lo que vive **como dato en la BD**:

| Si el deploy cambió… | Correr después (UNA vez) | Por qué |
|---|---|---|
| **Catálogos, planes, permisos o módulos** (`SystemModulesSeeder`, `RolesAndPermissionsSeeder`, `PlansSeeder`, `SettingsSeeder`) | `php artisan db:seed --class=XxxSeeder --force` | Los deploys incrementales NO re-seedean. Los seeders son idempotentes: no duplican ni pisan lo que ya editó el cliente. |
| **Un módulo nuevo con `make:module`** | `php artisan db:seed --class=RolesAndPermissionsSeeder --force` | El módulo inserta su fila en `system_modules`, y de ahí salen sus 7 permisos. Sin re-sembrar, nadie tiene acceso y el sidebar no lo muestra. |
| **Solo código o UI** | nada extra | Queda vivo con el `git pull` + `npm run build`. |

**En la práctica, la mayoría de los deploys no necesitan nada extra.**

> **Las plantillas de formato NO son código.** Un AST o un PTF nuevo es
> configuración en `form_templates`: se crea desde la interfaz y no requiere
> deploy. Ese es el punto del motor de formatos.

---

## Checklist a completar en el primer deploy real

Los items abajo son decisiones operativas que NO se cierran hasta hacer el primer deploy. Documentar cada uno cuando se aterrice:

- [ ] **Nginx** — plantilla de configuración del vhost (server_name, root, fastcgi_pass a php8.3-fpm, gzip, headers de seguridad)
- [ ] **Supervisor** — config para `php artisan queue:work` (procesos, autoreinicio, logs)
- [ ] **SSL** — opción A: Let's Encrypt con `certbot --nginx`. Opción B: Cloudflare Full Strict
- [ ] **Cloudflare** — DNS apuntando al Droplet + SSL Full Strict + reglas de cache estáticos
- [ ] **Script de deploy** — bash con `git pull` + `composer install --no-dev` + `npm ci && npm run build` + `migrate --force` + cache rebuild (alternativas: deployer.org, GitHub Actions)
- [ ] **Backups BD** — `pg_dump` diario a una carpeta + retención 7d, más snapshots semanales del droplet
- [ ] **Monitoreo** — UptimeRobot (gratis 5 monitores) + alertas a email
- [ ] **Logs centralizados** — al menos rotación con `logrotate` para `storage/logs/laravel.log`
- [ ] **Rollback** — backup de la BD ANTES de cada deploy + tag git del release previo

Una vez cerrado el primer deploy, las decisiones tomadas se mueven a `README-PROD.md` como guía operativa fija.

---

## Variables de entorno de producción

Lista mínima de cambios respecto al `.env` de desarrollo:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://miapp.com

DB_PASSWORD=password-fuerte-generada

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=smtp     # o ses, mailgun, postmark
MAIL_HOST=smtp.mailgun.org
MAIL_USERNAME=...
MAIL_PASSWORD=...

GOOGLE_REDIRECT_URI=https://miapp.com/auth/google/callback

FILESYSTEM_DISK=public  # o spaces cuando migres
```

---

## Reglas de oro de producción

1. **Nunca** `php artisan setup:project` (el comando lo bloquea, pero igual).
2. **Nunca** `migrate:fresh` ni `migrate:rollback` sin tener backup verificado.
3. **Siempre** `php artisan migrate --force` para aplicar migraciones nuevas.
4. **Nunca** commitear `.env` al repo.
5. **Siempre** correr `composer install --no-dev --optimize-autoloader` (sin `--no-dev` el bundle pesa el doble).
6. **Siempre** correr `npm run build` (no `npm run dev`).
7. **Después** de cada deploy:
   ```bash
   php artisan config:cache
   php artisan view:cache
   ```
   **`route:cache` no**, y no es una preferencia: los archivos de rutas tienen
   closures (los redirects `.../{slug}/restore` de cada módulo, y la raíz en
   `routes/localized.php`). Laravel no puede serializar un closure, así que el
   comando falla con *"Unable to prepare route for serialization"*. Si algún día
   se quieren cachear las rutas, primero hay que mover esos closures a métodos de
   controller.
8. **Cuando edites el código en caliente**: limpia primero el cache (`php artisan config:clear`), edita, vuelve a cachear. Si no, los cambios no se ven.

---

## Costo proyectado mensual (DigitalOcean)

| Concepto | Plan inicial (2GB) | Plan crecido (4GB) |
|---|---|---|
| Droplet | $12 | $24 |
| Backups (recomendado) | +$2.40 | +$4.80 |
| Dominio | ~$1 (anualizado) | ~$1 |
| Cloudflare | $0 | $0 |
| **Total** | **~$15** | **~$30** |

Sin Spaces, sin managed DB, sin nada extra. Un solo Droplet con todo dentro.

---

## Documentación relacionada

- [`../README-PROD.md`](../README-PROD.md) — guía operativa principal de producción (paso a paso)
- [`../README-DEV.md`](../README-DEV.md) — guía de desarrollo en PC
- [`DESPLIEGUE-POSTGRES.md`](DESPLIEGUE-POSTGRES.md) — DOCUFIZ, TRAFODEX y tenkofiz en un mismo PostgreSQL sin que se vean entre sí
- [`BASE-DE-DATOS-LOCAL.md`](BASE-DE-DATOS-LOCAL.md) — migrar el sistema anterior en local y subir la base ya terminada
- [`DROPLET-POSTGRES-SECURITY.md`](DROPLET-POSTGRES-SECURITY.md) — PostgreSQL en el droplet sin exponerlo a internet (VPC, TLS, `pg_hba.conf`, roles mínimos, túnel SSH, backups)
- [`ENV.md`](ENV.md) — variables de entorno (qué tiene que cambiar para producción)
- [`MAIL-SETUP.md`](MAIL-SETUP.md) — SMTP en producción (Mailgun / SES / Postmark)
- [`CRONS-AND-SETTINGS.md`](CRONS-AND-SETTINGS.md) — capas de cron y settings que necesitan estar bien antes del go-live
- [`SENTRY.md`](SENTRY.md) — activar error tracking en producción (feature futura)
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — errores comunes y cómo resolverlos
