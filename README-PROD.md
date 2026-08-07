# Guía de producción — DigitalOcean Deploy

Todo lo que necesitas para llevar el sistema a producción de forma segura. Provisionar el servidor, hardening, configurar BD, queue workers, crons, backups, monitoring.

> **Antes de leer esto**, asegúrate de entender los conceptos en [README.md](README.md) y haber leído [`docs/CRONS-AND-SETTINGS.md`](docs/CRONS-AND-SETTINGS.md) para entender las 4 capas (cron del SO → scheduler → comandos → settings).

---

## Resumen ejecutivo — lo que tendrá en prod

| Componente | Tecnología | Donde corre |
|---|---|---|
| Web server | nginx | Droplet (recibe HTTPS, sirve estáticos) |
| App backend | Laravel 13 + PHP 8.3-FPM | Droplet (procesa requests) |
| BD | PostgreSQL 16 con `unaccent` | Droplet (local socket) o Managed DB |
| Queue worker | `php artisan queue:work` via Supervisor | Droplet (proceso persistente) |
| Cron scheduler | `php artisan schedule:run` | Crontab del SO (cada minuto) |
| Cron backup BD | `pg_dump` | Crontab del SO (diario, fuera de Laravel) |
| HTTPS | Let's Encrypt (Certbot) | nginx |
| Storage | `local` disk en `storage/app/public` + symlink | Droplet (o Spaces si crece) |
| Mail | SMTP (Mailgun / SES / Postmark) | Externo |
| Backups | pg_dump diario a `/var/backups/` | Crontab del SO |

---

## 1. Provisionar el droplet

### 1.1. Crear droplet

- **Ubuntu 24.04 LTS** (o 22.04 si prefieres stable mayor)
- **2 vCPU / 4 GB RAM / 80 GB SSD** ($24/mes) recomendado
- Si arrancas con poco tráfico, 1 vCPU / 2 GB ($12/mes) alcanza
- Región: la más cercana a tus clientes

### 1.2. Usuario no-root

NUNCA trabajes como root. Crea un usuario dedicado:

```bash
adduser deploy
usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy

# Prueba login con deploy desde tu PC
ssh deploy@<ip-del-droplet>
```

### 1.3. SSH hardening

Edita `/etc/ssh/sshd_config`:

```
PermitRootLogin no
PasswordAuthentication no
PubkeyAuthentication yes
Port 2222     # cambiar puerto default 22
AllowUsers deploy
```

Reiniciar SSH:
```bash
sudo systemctl restart ssh
```

> ⚠️ ANTES de reiniciar SSH, abre una segunda terminal y verifica que puedes loguearte como deploy. Si te equivocaste en algo, te quedas afuera.

### 1.4. Firewall (UFW)

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 2222/tcp     # tu puerto SSH custom
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

Solo HTTP/HTTPS + SSH custom. Todo lo demás bloqueado.

### 1.5. Fail2ban (anti brute-force)

```bash
sudo apt install -y fail2ban
sudo systemctl enable fail2ban --now
```

Default config ya cubre SSH. Si quieres sumar nginx, edita `/etc/fail2ban/jail.local`.

### 1.6. Instalar el stack

```bash
sudo apt update && sudo apt upgrade -y

sudo apt install -y nginx postgresql-16 redis-server supervisor unzip git curl \
    php8.3-fpm php8.3-{cli,pgsql,mbstring,xml,bcmath,zip,intl,fileinfo,gd,curl,opcache} \
    nodejs npm certbot python3-certbot-nginx
```

> `redis-server` es opcional (la app usa queue `database` driver). Útil si más adelante mueves sesiones HTTP a Redis.

Composer:
```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

---

## 2. Configurar PostgreSQL

### 2.1. Crear BD + user dedicado

```bash
sudo -u postgres psql
```

```sql
CREATE USER baseapp WITH PASSWORD '<password-fuerte-aleatorio>';
CREATE DATABASE baseapp OWNER baseapp;
\c baseapp
CREATE EXTENSION IF NOT EXISTS unaccent;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT ALL ON SCHEMA public TO baseapp;
ALTER SCHEMA public OWNER TO baseapp;
\q
```

> NO uses el usuario `postgres` superuser para la app. Crea uno dedicado con permisos mínimos.

Generar password fuerte:
```bash
openssl rand -base64 32
```

### 2.2. Bloquear conexiones remotas

Edita `/etc/postgresql/16/main/postgresql.conf`:

```
listen_addresses = 'localhost'
```

Edita `/etc/postgresql/16/main/pg_hba.conf` — solo conexiones locales:

```
# TYPE  DATABASE  USER     ADDRESS         METHOD
local   all       postgres                 peer
local   all       all                      scram-sha-256
host    all       all      127.0.0.1/32    scram-sha-256
```

NUNCA pongas `0.0.0.0/0` ni `host all all all md5`.

Reiniciar Postgres:
```bash
sudo systemctl restart postgresql
```

### 2.3. Alternativa: DigitalOcean Managed Databases

Si quieres delegar backups, failover, scaling: $15/mes. Cambias `DB_HOST` por el endpoint del Managed DB y listo. **Recomendado en serio para producción**.

---

## 3. Deploy del código

### 3.1. Clonar el repo

```bash
sudo mkdir -p /var/www
sudo chown deploy:www-data /var/www
cd /var/www

git clone <url-del-repo> trafodex
cd trafodex
```

### 3.2. Permisos del filesystem

```bash
# Owner: deploy (usuario), grupo: www-data (PHP-FPM)
sudo chown -R deploy:www-data /var/www/trafodex

# Permisos generales: directorios 755, archivos 644
sudo find /var/www/trafodex -type d -exec chmod 755 {} \;
sudo find /var/www/trafodex -type f -exec chmod 644 {} \;

# storage/ y bootstrap/cache/ necesitan escritura por PHP-FPM
sudo chmod -R 775 /var/www/trafodex/storage
sudo chmod -R 775 /var/www/trafodex/bootstrap/cache
```

### 3.3. `.env` de producción

```bash
cp .env.example .env
chmod 600 .env       # solo el owner lee
nano .env
```

Configurá:

```env
APP_NAME="Tu App"
APP_ENV=production
APP_DEBUG=false      # NUNCA true en prod
APP_URL=https://midominio.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=baseapp
DB_USERNAME=baseapp
DB_PASSWORD=<password-generado-arriba>

# Mail SMTP (Mailgun, SES, Postmark, o Gmail App Password)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=postmaster@midominio.com
MAIL_PASSWORD=<app-password-del-proveedor>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@midominio.com
MAIL_FROM_NAME="${APP_NAME}"

# Queue
QUEUE_CONNECTION=database

# Session
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true     # HTTPS only

# App
APP_LOCALE=es
APP_TIMEZONE=UTC
```

Generar la key:
```bash
php artisan key:generate
```

### 3.4. Instalar dependencias

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

> `--no-dev` excluye dev dependencies (PHPUnit, Pail, Pint, Sail). Más liviano.

### 3.5. Migrar + sembrar inicial

```bash
# Primera vez SOLAMENTE
php artisan migrate --force --seed

# Cambiar password del super demo INMEDIATAMENTE
php artisan tinker
>>> $u = User::where('email', 'super@example.com')->first();
>>> $u->password = bcrypt('<password-nuevo-fuerte>');
>>> $u->save();
>>> exit
```

> ⚠️ `--seed` siembra usuarios demo con password `123456`. Cámbialos TODOS antes del primer login público. O mejor: edita los seeders para que solo siembren tu super, y borra el resto.

### 3.6. Storage symlink

```bash
php artisan storage:link
```

Sin esto las imágenes de usuarios y logos de workspaces devuelven 404.

### 3.7. Cache de optimización

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

> Tras cada deploy de código nuevo, repetir esos 4 + `npm run build`.

---

## 4. nginx + HTTPS

### 4.1. Configurar virtualhost

`/etc/nginx/sites-available/baseapp`:

```nginx
server {
    listen 80;
    server_name midominio.com www.midominio.com;
    return 301 https://midominio.com$request_uri;
}

server {
    listen 443 ssl http2;
    server_name midominio.com;

    root /var/www/trafodex/public;
    index index.php;

    # SSL — Let's Encrypt los popula después
    # ssl_certificate /etc/letsencrypt/live/midominio.com/fullchain.pem;
    # ssl_certificate_key /etc/letsencrypt/live/midominio.com/privkey.pem;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header Permissions-Policy "geolocation=(), microphone=()" always;

    # Upload size (fotos + imports)
    client_max_body_size 10M;

    # Block sensitive files
    location ~ /\.(?!well-known) { deny all; }
    location ~ ^/(storage|bootstrap)/ { deny all; }

    # Static assets — cache largo
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff2?)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # Inertia / Laravel
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Logs
    access_log /var/log/nginx/baseapp.access.log;
    error_log /var/log/nginx/baseapp.error.log;
}
```

Activar:
```bash
sudo ln -s /etc/nginx/sites-available/baseapp /etc/nginx/sites-enabled/
sudo nginx -t       # verificar syntax
sudo systemctl reload nginx
```

### 4.2. SSL con Let's Encrypt

```bash
sudo certbot --nginx -d midominio.com -d www.midominio.com
```

Certbot agrega automáticamente los bloques `ssl_certificate*` al nginx config + cron de auto-renovación.

### 4.3. PHP-FPM tuning

Edita `/etc/php/8.3/fpm/php.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0      ; ⚠️ 0 = NO revalida — tras deploy reload PHP-FPM
opcache.jit=1255
opcache.jit_buffer_size=128M

upload_max_filesize=10M
post_max_size=12M
memory_limit=256M
max_execution_time=120
```

Reiniciar PHP-FPM:
```bash
sudo systemctl restart php8.3-fpm
```

> Tras cada deploy: `sudo systemctl reload php8.3-fpm` para que opcache tome los archivos nuevos.

---

## 5. Queue worker con Supervisor

**Sin esto los exports/emails/automations NUNCA se procesan.**

`/etc/supervisor/conf.d/baseapp-queue.conf`:

```ini
[program:baseapp-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/trafodex/artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=deploy
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/baseapp-queue.log
stopwaitsecs=3600
```

Activar:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start baseapp-queue:*

# Ver status
sudo supervisorctl status baseapp-queue:*
```

**Cada deploy** que toca código de Jobs: `sudo supervisorctl restart baseapp-queue:*` (o `php artisan queue:restart` que los recicla en el próximo job).

---

## 6. Crons (las 3 entradas críticas)

`crontab -e` como usuario `deploy`:

```cron
# 1) Laravel scheduler — dispara TODOS los schedules internos de Laravel
* * * * * cd /var/www/trafodex && php artisan schedule:run >> /dev/null 2>&1

# 2) Backup BD diario a las 02:00 (independiente de Laravel)
0 2 * * * pg_dump -U baseapp baseapp | gzip > /var/backups/baseapp-$(date +\%Y\%m\%d).sql.gz

# 3) Limpieza de backups viejos (más de 14 días)
5 2 * * * find /var/backups/baseapp-*.sql.gz -mtime +14 -delete
```

### Verificar que los schedules de Laravel se disparan

```bash
php artisan schedule:list
```

Deberías ver:
- `app:cleanup-expired-downloads` (cada hora)
- `app:purge-soft-deleted` (diario 03:00 + 04:00)
- `subscriptions:check-expirations` (diario 03:00)
- `automations:tick` (cada minuto)

Detalle completo en [`docs/CRONS-AND-SETTINGS.md`](docs/CRONS-AND-SETTINGS.md).

---

## 7. Backups de BD

### Backup manual (cuando lo necesites)

```bash
pg_dump -U baseapp baseapp | gzip > /tmp/baseapp-manual-$(date +%Y%m%d-%H%M).sql.gz
```

### Backup automático ya cubierto

Cron diaria 02:00 + retención 14 días (sección 6 arriba).

### Restaurar un backup

```bash
gunzip < /var/backups/baseapp-20260516.sql.gz | psql -U baseapp baseapp
```

> Antes de restaurar, haz un backup del estado actual por si quieres volver.

### Backups off-site (recomendado producción seria)

Los backups en el mismo droplet no protegen contra "se rompió el droplet". Opciones:
- **DigitalOcean Spaces** ($5/mes 250 GB) — copiar el dump con `rclone` o `s3cmd`
- **Managed DB** ($15/mes) — backups gestionados incluidos
- **Backblaze B2** ($6/TB/mes) — más barato para volumen grande

Ejemplo con Spaces (cron diario):
```cron
10 2 * * * s3cmd put /var/backups/baseapp-$(date +\%Y\%m\%d).sql.gz s3://mi-bucket/backups/
```

---

## 8. Settings — qué configurar al primer login

Una vez deployado, login como super y entre a Sidebar → **Configuración**. Revise estos:

| Setting | Default | Qué cambiar |
|---|---|---|
| `app.name` | "Application Name" | Tu marca real |
| `app.support_email` | `soporte@example.com` | Tu email de soporte real |
| `features.subscription_enforcement_enabled` | `false` | Cambiar a `true` cuando tu billing esté listo |
| `notifications.email_enabled` | `true` | Mantener `true` en prod |
| `downloads.expire_after_hours` | 24 | Ajustar si exports muy grandes (subir) o ahorrás espacio (bajar) |
| `downloads.grace_hours` | 24 | Idem |

Lista completa de los 23 settings: [`docs/CRONS-AND-SETTINGS.md`](docs/CRONS-AND-SETTINGS.md#3-settings-disponibles-23-keys-en-9-grupos).

> El sender de email (`MAIL_FROM_NAME`, `MAIL_FROM_ADDRESS`) vive en `.env`, no en Settings. Las credenciales SMTP (`MAIL_USERNAME`, `MAIL_PASSWORD`) siempre en `.env` — jamás en BD.

---

## 9. SMTP — configuración del envío de correos

Guía completa paso a paso (Gmail App Password, Mailgun, AWS SES, Postmark) con troubleshooting:

**Ver [`docs/MAIL-SETUP.md`](docs/MAIL-SETUP.md)**.

Lo crítico para prod:

- `MAIL_PASSWORD` SIEMPRE en `.env` (jamás en BD)
- Tras tocar variables `MAIL_*`: `php artisan config:clear && php artisan queue:restart` (los workers viejos tienen la config vieja en memoria)
- Configurar SPF + DKIM + DMARC en el DNS del dominio (evita que los emails caigan a spam)
- Para volumen real: Mailgun, SES o Postmark — Gmail tope ~500/día

Toggle global de emails (sin tocar `.env`): setting `notifications.email_enabled` en `/system_management/settings`. En `false` silencia todos los emails (siguen apareciendo en la campana).

---

## 10. Anti SQL injection — ya cubierto

La app está protegida estructuralmente. Verificar que ningún PR futuro rompa esto:

- ✅ **Eloquent ORM + Query Builder** en todos lados → prepared statements automáticos
- ✅ **`whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$name])`** → parameter binding
- ✅ **Sort/direction validados** con `in_array(['asc', 'desc'])` + `in_array(['id', 'name', ...])`
- ✅ **Filtros + IDs vienen vía FormRequest** → tipos validados antes de query

Si alguien futuro escribe `DB::statement("SELECT * FROM x WHERE name = '{$name}'")` → vulnerable. NO hay un solo caso así hoy (`grep -r "DB::statement" app/`).

---

## 11. Rate limiting

Ya implementado a 3 niveles:

| Limit | Configurado en | Default |
|---|---|---|
| API global | `app/Providers/AppServiceProvider.php` | 60 req/min por token o IP |
| Exports | `routes/*.php` → `throttle:5,1` | 5 req/min por user |
| Bulk operations | `routes/*.php` → `throttle:10,1` | 10 req/min |
| Login | TODO (sin throttle hoy, futuro con `security.max_login_attempts` setting) | — |

---

## 12. Monitoring + logs

### Logs Laravel

```bash
tail -f /var/www/trafodex/storage/logs/laravel.log
```

Rotación automática (Laravel por default, 14 días).

### Logs nginx

```bash
tail -f /var/log/nginx/baseapp.access.log
tail -f /var/log/nginx/baseapp.error.log
```

### Logs supervisor (queue worker)

```bash
tail -f /var/log/supervisor/baseapp-queue.log
```

### Logs específicos de comandos

- `storage/logs/cleanup-downloads.log` — cleanup hourly
- `storage/logs/purge.log` — purge nightly

### Sentry (error tracking — futuro)

Hoy no está activado. Cuando quieras: `composer require sentry/sentry-laravel` + configurar DSN en `.env`. Detalle: [`docs/SENTRY.md`](docs/SENTRY.md).

### Métricas server

```bash
# CPU + RAM
htop

# Disco
df -h
du -sh /var/www/trafodex/storage/   # ¿está creciendo?

# Connections Postgres
sudo -u postgres psql -c "SELECT count(*) FROM pg_stat_activity;"

# Active queues
php artisan queue:monitor   # Laravel 11+ (si se activa)
```

---

## 13. Workflow de deploy continuo

Después del primer deploy, los siguientes:

```bash
# Como deploy en el server
cd /var/www/trafodex

# 1. Pull nuevo código
git pull origin main

# 2. Reinstalar deps si composer.lock o package-lock cambiaron
composer install --no-dev --optimize-autoloader
npm ci

# 3. Migrate nuevo (si hay migraciones nuevas)
php artisan migrate --force

# 4. Re-seedear settings si hay nuevos
php artisan db:seed --class=SettingsSeeder --force

# 5. Re-seedear permisos si hay nuevos
php artisan db:seed --class=RolesAndPermissionsSeeder --force

# 6. Rebuild frontend
npm run build

# 7. Re-cache de optimización
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 8. Reload PHP-FPM (opcache toma archivos nuevos)
sudo systemctl reload php8.3-fpm

# 9. Reciclar queue workers
php artisan queue:restart
# o:
sudo supervisorctl restart baseapp-queue:*
```

### Automatizar con GitHub Actions

Cuando estés listo, automatizar con un workflow `.github/workflows/deploy.yml` que haga SSH al droplet y corra los pasos de arriba. Feature futura, no crítica para arrancar.

---

## 14. Checklist pre-go-live

Antes de mostrar el sistema a un cliente real:

- [ ] Droplet provisionado con Ubuntu LTS
- [ ] Usuario `deploy` no-root con SSH key, password auth deshabilitada
- [ ] UFW activo (solo 80/443/SSH custom)
- [ ] Fail2ban corriendo
- [ ] PostgreSQL 16 con `unaccent`, usuario dedicado, conexiones solo local
- [ ] HTTPS con Let's Encrypt funcionando
- [ ] Security headers en nginx config
- [ ] PHP-FPM con opcache + JIT activado
- [ ] `.env` con `APP_DEBUG=false`, `chmod 600`
- [ ] `php artisan migrate --force --seed` ejecutado
- [ ] Password del super CAMBIADO inmediatamente (no usar el demo `123456`)
- [ ] Usuarios demo ELIMINADOS del seeder o sus passwords cambiados
- [ ] `php artisan storage:link` ejecutado
- [ ] `config/route/view/event:cache` ejecutados
- [ ] Supervisor con queue worker activo + auto-restart
- [ ] Cron `schedule:run` cada minuto
- [ ] Cron `pg_dump` diario 02:00
- [ ] SMTP configurado y probado (mandar un email de prueba)
- [ ] Settings revisados: `app.name`, `app.support_email`, `features.subscription_enforcement_enabled`
- [ ] DNS apuntando al droplet
- [ ] Browser test: login OK, crear workspace OK, crear usuario OK, export OK
- [ ] Mobile test: el sistema es responsive

---

## 15. Si algo se rompe en producción

### "Tira 500 en todo"

1. `tail -100 /var/www/trafodex/storage/logs/laravel.log` — leer el último error
2. Permisos de `storage/` y `bootstrap/cache/` — `chmod -R 775`
3. `.env` con `APP_DEBUG=true` temporal para ver el error (NO dejar así)

### "Login funciona pero todo da 401/redirect"

1. Cookies con `SESSION_SECURE_COOKIE=true` pero el sitio no es HTTPS → revisar nginx + Certbot
2. `SESSION_DRIVER=database` pero la tabla `sessions` no existe → `php artisan session:table && migrate`

### "Exports nunca llegan"

1. ¿Queue worker corriendo? `sudo supervisorctl status baseapp-queue:*`
2. ¿Hay jobs failed? `php artisan queue:failed` — si hay, ver el error y `queue:retry all`
3. ¿Setting `notifications.email_enabled` está en true?
4. ¿SMTP funciona? `php artisan tinker` → `Mail::raw('test', fn($m) => $m->to('tu@email.com')->subject('test'));`

### "Imágenes 404"

`php artisan storage:link` — se pierde con cada `git pull` si alguien borró el symlink.

### "BD lenta"

```bash
sudo -u postgres psql baseapp
EXPLAIN ANALYZE SELECT ...    # query lenta
\d+ customers                 # ver indexes de una tabla
```

Si una query no usa índice, considera agregarlo. Los principales ya están en las migraciones.

### Detalle completo de errores

[`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md).

---

## 16. Mantenimiento mensual

Una vez al mes:

- [ ] Verificar que los backups se están generando (`ls -la /var/backups/`)
- [ ] Restore test: bajar un backup y restaurarlo en una BD de prueba
- [ ] Actualizar SO: `sudo apt update && sudo apt upgrade -y`
- [ ] Reiniciar PHP-FPM + Postgres después
- [ ] Renovar SSL si Certbot falló (`sudo certbot renew --dry-run` para verificar)
- [ ] Revisar `storage/logs/` — si `laravel.log` pasa los 100 MB, rotar manualmente
- [ ] Revisar uso de disco (`df -h`, `du -sh /var/www/trafodex/storage/`)
- [ ] Revisar audit logs por anomalías (acciones sospechosas)
- [ ] Revisar usuarios super-only (¿alguien debería tener menos privilegios?)

---

## 17. Escalar más adelante

Si el droplet 2GB queda chico:

| Síntoma | Solución |
|---|---|
| CPU al 80%+ constante | Subir a 4GB / 4 vCPU ($48/mes) |
| Disco se llena | Migrar `storage/` a DigitalOcean Spaces (S3-compat) |
| BD lenta con + de 5 workspaces grandes | Pasar a Managed DB ($15/mes) o droplet dedicado para Postgres |
| Tráfico > 1000 req/min | Load balancer + 2 droplets app + 1 droplet BD |
| Queue saturado | Subir `numprocs=2` → `numprocs=4` en supervisor |

Caveat — la app **no usa Redis hoy** (decisión deliberada). Si escala a múltiples app servers, va a necesitar Redis para sesiones compartidas.

---

## 18. Documentación complementaria

| Documento | Para qué |
|---|---|
| [`docs/DEPLOY.md`](docs/DEPLOY.md) | Guía paso a paso de deploy (complementa este README) |
| [`docs/CRONS-AND-SETTINGS.md`](docs/CRONS-AND-SETTINGS.md) | Las 4 capas + 23 settings con su efecto |
| [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) | Errores comunes |
| [`docs/SENTRY.md`](docs/SENTRY.md) | Setup de error tracking (cuando quieras agregar) |
