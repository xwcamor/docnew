# Troubleshooting

**Qué es esto**: catálogo de errores comunes del proyecto y cómo resolverlos.

**Para qué sirve**: ahorrar tiempo cuando aparece un error conocido (CSRF mismatch, Vite no compila, queue worker zombi, permisos Spatie en cache, etc.).

**Cuándo leerlo**: cuando algo falla. Si tu error no está aquí, agrégalo cuando lo resuelvas — el doc crece con la experiencia.

---

## Backend (PHP / Laravel)

### `could not find driver`

**Causa**: Falta la extensión PHP `pdo_pgsql` (o `pdo_mysql`).

**Solución (Laragon)**:
1. Click derecho en Laragon → **PHP** → **Extensions** → marcar `pdo_pgsql` y `pgsql`.
2. Reiniciar Laragon (Stop All → Start All).
3. Verificar:
   ```bash
   php -m | findstr pgsql
   ```

**Solución (manual)**: edita `php.ini` y descomenta:
```ini
extension=pdo_pgsql
extension=pgsql
```

---

### `password authentication failed for user "laravel"`

**Causa**: Las credenciales de `.env` no coinciden con el rol creado en PostgreSQL.

**Solución**:
1. Verifica `DB_USERNAME` y `DB_PASSWORD` en `.env`.
2. En pgAdmin → **Login/Group Roles** → click derecho en `laravel` → **Properties** → pestaña **Definition** → resetear password.
3. `php artisan config:clear`.

---

### `permission denied for schema public`

**Causa**: PostgreSQL 15+ requiere que los nuevos roles tengan permisos explícitos sobre el schema `public`.

**Solución**: en pgAdmin → Query Tool sobre `trafodex`, ejecuta:
```sql
GRANT ALL ON SCHEMA public TO laravel;
ALTER SCHEMA public OWNER TO laravel;
```

---

### `database "trafodex" does not exist`

**Causa**: La BD no fue creada en PostgreSQL.

**Solución**: en pgAdmin → click derecho en **Databases** → **Create** → **Database…** con nombre `trafodex`. Después corre los GRANTs del paso anterior.

---

### `Class "Inertia\..." not found`

**Causa**: `composer install` no corrió o falló a la mitad.

**Solución**:
```bash
composer install
composer dump-autoload -o
```

---

### `Refusing to run setup:project on APP_ENV=production`

**Causa**: Protección integrada — el comando se niega a borrar la BD en producción.

**Solución**: si **realmente** quieres recrear la BD en local:
1. Verifica que tu `.env` tenga `APP_ENV=local`.
2. `php artisan config:clear`.
3. Re-ejecuta `php artisan setup:project`.

> En producción **nunca** uses `setup:project`. Usa `php artisan migrate` para aplicar migraciones nuevas.

---

### Después de `git pull` algo no funciona

**Causa**: Hay dependencias o migraciones nuevas que no tienes.

**Solución estándar**:
```bash
composer install
npm install
php artisan migrate
npm run build
php artisan config:clear
```

---

## Frontend (Vite / Vue / Inertia)

### `Vite manifest not found`

**Causa**: O no corriste `npm run dev` (modo desarrollo) o no buildeaste para producción.

**Solución**:
```bash
npm run dev
# O para producción:
npm run build
```

---

### Pantalla en blanco al abrir una página de Inertia

**Causa**: Error JavaScript. **Siempre abre la consola del navegador** (F12 → Console).

**Errores típicos**:
- Import roto en un `.vue`: corrige la ruta del import.
- Componente Ant Design Vue mal escrito: revisa el nombre exacto en https://antdv.com/components/overview.
- AG Grid sin theme: importar el CSS en `app.css`.

---

### `route is not defined` en consola JS

**Causa**: Falta la directiva `@routes` de Ziggy en `app.blade.php`.

**Solución**: verifica que `resources/views/app.blade.php` tenga:
```html
@routes
@vite(['resources/css/app.css', 'resources/js/app.js'])
@inertiaHead
```

Y limpia caché:
```bash
php artisan route:clear
```

---

### `Failed to resolve import "ziggy-js"`

**Causa**: Ziggy v2 distribuye el JS dentro del paquete PHP (`vendor/tightenco/ziggy/`), no como paquete npm. Vite no lo encuentra a menos que se le indique con un alias.

**Solución**: ya está configurado en `vite.config.js`:
```js
resolve: {
  alias: {
    'ziggy-js': path.resolve(__dirname, 'vendor/tightenco/ziggy'),
  },
}
```

Si el error aparece igual: detén `npm run dev` con `Ctrl+C` y reinícialo (los cambios al `vite.config.js` no se recargan con HMR).

---

### Cambios en `.vue` no se reflejan en el navegador

**Causa**: `npm run dev` no está corriendo, o el HMR perdió la conexión.

**Solución**:
1. Verifica que en una terminal aparezca `VITE v7.x ready`.
2. Si no, corre `npm run dev` de nuevo.
3. Refresca el navegador con `Ctrl+Shift+R` (hard refresh).

---

### El input pierde el foco al escribir / el componente se re-monta cada cambio

**Causa**: probablemente estás usando `v-model` mal con un componente Ant Design Vue. La mayoría usa `v-model:value` (no `v-model`):

```vue
<!-- ❌ MAL -->
<Input v-model="text" />

<!-- ✅ BIEN -->
<Input v-model:value="text" />
```

---

## PostgreSQL / DBeaver

### DBeaver no descarga el driver JDBC

**Causa**: Sin internet o firewall corporativo.

**Solución**:
1. Descarga manualmente desde https://jdbc.postgresql.org/download/ el `.jar`.
2. En DBeaver → **Database** → **Driver Manager** → selecciona PostgreSQL → **Edit** → **Libraries** → **Add File** → selecciona el `.jar`.

---

### `psql: command not found`

**Causa**: La carpeta `bin` de PostgreSQL no está en el PATH.

**Solución (Windows)**:
1. Settings → "Editar las variables de entorno del sistema".
2. **Variables de entorno** → editar `Path` (en variables del usuario o sistema).
3. Agregar `C:\Program Files\PostgreSQL\16\bin`.
4. Reiniciar la terminal.

---

### Puerto 5432 ya en uso

**Causa**: Otra instancia de PostgreSQL corriendo, o algún otro servicio.

**Diagnóstico**:
```bash
netstat -ano | findstr 5432
```

Te dará el PID. Para ver el nombre del proceso:
```bash
tasklist /FI "PID eq <numero>"
```

**Solución**: detener el otro servicio o cambiar el puerto del nuevo PostgreSQL en su instalación.

---

## Laragon

### Apache no arranca (puerto 80 ocupado)

**Causa**: IIS, Skype, World Wide Web Publishing Service, o algún otro servicio usa el puerto 80.

**Solución**:
- Detener IIS: `iisreset /stop`
- O cambiar el puerto de Apache en Laragon: Menú → **Apache** → `httpd.conf` → cambiar `Listen 80` a `Listen 8080`. Reiniciar.

---

### Mensaje `extension=zip` al instalar paquetes Composer

**Causa**: Extensión `zip` no habilitada.

**Solución**: editar `C:\laragon\bin\php\php-X.X.X\php.ini` y descomentar:
```ini
extension=zip
```

Reiniciar Laragon.

---

## Email

### Los emails no se envían (en desarrollo)

**Causa**: `MAIL_MAILER=log` por defecto — los emails se "envían" al archivo de logs, no a un servidor real.

**Solución**: revisa `storage/logs/laravel.log`. Verás el contenido completo del email.

Para enviar emails reales en desarrollo, configura SMTP (Gmail, Mailtrap, etc.) en `.env`. Ver [`docs/ENV.md`](ENV.md#email).

---

## Cuando nada funciona — checklist universal

Si algo extraño pasa y no sabes por dónde empezar:

```bash
# 1. Limpia todo el caché de Laravel
php artisan optimize:clear

# 2. Reinstala dependencias
composer install
npm install

# 3. Re-buildea assets
npm run build

# 4. Reinicia los servicios
# Laragon: Stop All → Start All
# Vite: Ctrl+C en la terminal y volver a `npm run dev`

# 5. Hard refresh del navegador
# Ctrl+Shift+R (Chrome/Edge) o Ctrl+F5
```

Si después de eso el problema persiste, revisa:
- `storage/logs/laravel.log` — errores del backend
- Consola del navegador (F12) — errores del frontend
- Terminal donde corre `npm run dev` — errores de build

---

## Documentación relacionada

- [`../README-DEV.md`](../README-DEV.md) — flujo del día a día (los comandos correctos para limpiar caches)
- [`ENV.md`](ENV.md) — variables que típicamente faltan o están mal configuradas
- [`MAIL-SETUP.md`](MAIL-SETUP.md) — troubleshooting específico de SMTP
- [`INSTALL-TOOLS.md`](INSTALL-TOOLS.md) — errores comunes durante la instalación inicial
- [`SENTRY.md`](SENTRY.md) — error tracking en producción (para errores que escapan a este catálogo)
