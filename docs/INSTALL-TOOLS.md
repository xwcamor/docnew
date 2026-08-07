# Instalación de herramientas de desarrollo

**Qué es esto**: guía paso a paso para preparar una PC con Windows desde cero antes de clonar el proyecto.

**Para qué sirve**: instalar Laragon (PHP/Apache/Composer), PostgreSQL 16 con extensión `unaccent`, Node.js 22, Git y DBeaver.

**Cuándo leerlo**: la primera vez que abres el proyecto en una PC nueva.

> Tiempo estimado: **30-45 minutos** la primera vez.

---

## Tabla de contenido

1. [Laragon](#1-laragon-php--apache--node--composer) — entorno de desarrollo PHP
2. [PostgreSQL 16](#2-postgresql-16) — base de datos
3. [DBeaver Community](#3-dbeaver-community) — cliente gráfico para ver/editar la BD
4. [Verificación final](#4-verificación-final)

---

## 1. Laragon (PHP + Apache + Node + Composer)

**¿Qué es?** Un entorno de desarrollo "todo en uno" para Windows. Incluye:
- PHP 8.3
- Apache
- Composer
- Node.js + npm
- Git
- HeidiSQL (cliente MySQL)

Reemplaza a XAMPP/WAMP con un setup más moderno y rápido.

### 1.1. Descargar e instalar

1. Ve a https://laragon.org/download/ → descarga **Laragon Full** (recomendado, trae todo).
2. Ejecuta el instalador.
3. Pantallas:
   - **Installation Folder**: deja `C:\laragon` (recomendado).
   - **Options**: marca todas las opciones (Auto Virtual Hosts, Add to PATH, etc.).
   - Click **Next → Install**.
4. Al terminar, marca **Run Laragon** y finaliza.

### 1.2. Primera ejecución

1. Click en **Start All** (botón grande en la ventana de Laragon).
2. Verás los servicios encendidos: Apache, MySQL, etc.
3. Comprueba que funciona abriendo http://localhost — debería mostrar la pantalla de bienvenida de Laragon.

### 1.3. Habilitar las extensiones de PHP necesarias

PostgreSQL requiere extensiones PHP que **no vienen activadas por defecto**:

1. Click derecho en el ícono de Laragon (en la barra de tareas o en la ventana principal) → **PHP** → **Extensions**.
2. Marca:
   - ✅ `pdo_pgsql`
   - ✅ `pgsql`
   - ✅ `zip` (si no está)
   - ✅ `gd`
   - ✅ `intl`
3. Cierra el menú.
4. Reinicia Laragon: **Stop All** → **Start All**.

**Verificar que las extensiones están cargadas**:

Abre la terminal de Laragon (Menú → **Terminal**, atajo: `Ctrl+Alt+T`) y corre:

```bash
php -m | findstr pgsql
```

Debe listar:
```
pdo_pgsql
pgsql
```

Si no aparecen, edita manualmente `C:\laragon\bin\php\php-X.X.X\php.ini` y descomenta las líneas:
```ini
extension=pdo_pgsql
extension=pgsql
```
Reinicia Laragon de nuevo.

### 1.4. Configurar el dominio del proyecto

Laragon crea automáticamente un virtual host por cada carpeta dentro de `C:\laragon\www\`. Por ejemplo, si clonas el proyecto en `C:\laragon\www\trafodex-main`, automáticamente puedes abrirlo en:

```
http://trafodex-main.test
```

Sin tocar el archivo `hosts` ni configurar Apache manualmente.

### 1.5. Verificar versiones

```bash
php -v        # PHP 8.3.x
composer -V   # Composer 2.x
node -v       # v20.x.x
npm -v        # 10.x.x
git --version # 2.x.x
```

Todo eso debe estar disponible. Si alguno falla, reinstala Laragon marcando "Add to PATH".

---

## 2. PostgreSQL 16

**¿Qué es?** El motor de base de datos que usa el proyecto. **No viene con Laragon** — se instala aparte como servicio de Windows.

> Convive sin problema con MySQL/MariaDB de Laragon (PG usa puerto 5432, MySQL usa 3306).

### 2.1. Descargar

1. Ve a https://www.postgresql.org/download/windows/ → click **Download the installer** (te lleva a EnterpriseDB).
2. Descarga **PostgreSQL 16.x Windows x86-64**.

### 2.2. Instalar

1. Ejecuta el instalador como **administrador**.
2. Pantallas:
   - **Installation Directory**: deja default (`C:\Program Files\PostgreSQL\16`).
   - **Select Components**: deja todos marcados:
     - ✅ PostgreSQL Server
     - ✅ pgAdmin 4 (cliente gráfico oficial)
     - ✅ Stack Builder (puedes desmarcar)
     - ✅ Command Line Tools
   - **Data Directory**: deja default.
   - **Password**: ⚠️ **APUNTA ESTA CONTRASEÑA**. Es del superusuario `postgres`. Sugerencia: `postgres123` (memorable, solo para desarrollo local).
   - **Port**: `5432` (default, no cambiar).
   - **Locale**: `[Default locale]`.
   - Click **Next → Install**.
3. Al final, **desmarca** "Launch Stack Builder".

PostgreSQL queda corriendo como **servicio de Windows** y arranca automáticamente al encender la PC.

### 2.3. Verificar instalación

Abre **CMD** (no la terminal de Laragon) y corre:

```bash
"C:\Program Files\PostgreSQL\16\bin\psql.exe" -U postgres -c "SELECT version();"
```

Te pedirá la password del usuario `postgres`. Debe imprimir algo como:
```
PostgreSQL 16.x on x86_64-windows, ...
```

> **Tip**: para no escribir la ruta completa cada vez, agrega `C:\Program Files\PostgreSQL\16\bin` al `PATH` de Windows (Settings → Variables de entorno → editar `Path`).

### 2.4. Crear la base de datos del proyecto

Abre **pgAdmin 4** (lo instaló el instalador, búscalo en el menú Inicio):

1. Te pide una **master password** para pgAdmin → puedes poner la misma que de `postgres`.
2. En el panel izquierdo:
   - **Servers** → **PostgreSQL 16** → te pide el password del superusuario `postgres` (el que apuntaste).
3. **Crear la BD**:
   - Click derecho en **Databases** → **Create** → **Database…**
   - **Database**: `trafodex`
   - Click **Save**.
4. **Crear el usuario de la app**:
   - Click derecho en **Login/Group Roles** → **Create** → **Login/Group Role…**
   - Pestaña **General → Name**: `laravel`
   - Pestaña **Definition → Password**: `secret` (o lo que decidas — **anótalo**)
   - Pestaña **Privileges**:
     - ✅ Can login?
     - ✅ Create databases?
   - Click **Save**.
5. **Asignar permisos sobre la BD** (obligatorio en PostgreSQL 15+):
   - Click izquierdo en `trafodex` para seleccionarla → ícono **Query Tool** (⚡ arriba).
   - Pega y ejecuta (botón ▶ o F5):
     ```sql
     GRANT ALL PRIVILEGES ON DATABASE trafodex TO laravel;
     GRANT ALL ON SCHEMA public TO laravel;
     ALTER SCHEMA public OWNER TO laravel;
     ```
   - Debe decir "Query returned successfully".

### 2.5. Probar la conexión como `laravel`

En la misma pgAdmin → **Servers** → click derecho → **Register → Server…**:

- Pestaña **General → Name**: `Local laravel`
- Pestaña **Connection**:
  - Host: `localhost`
  - Port: `5432`
  - Maintenance database: `trafodex`
  - Username: `laravel`
  - Password: `secret`
- Click **Save**.

Si conecta sin error, **el usuario y la BD están bien configurados**.

---

## 3. DBeaver Community

**¿Qué es?** Un cliente gráfico gratuito para administrar bases de datos. Soporta **MySQL, PostgreSQL, SQLite y muchas más en una sola app**.

> Es opcional (pgAdmin ya cubre PostgreSQL), pero recomendado: con DBeaver puedes ver tu BD MySQL legacy de Laragon Y la nueva PostgreSQL desde el mismo programa, con una UI más moderna.

### 3.1. Descargar e instalar

1. Ve a https://dbeaver.io/download/ → descarga **Community Edition** para Windows (`.exe` installer).
2. Ejecuta el instalador → Next → Next → Install.

### 3.2. Conectar a tu PostgreSQL

1. Abre DBeaver.
2. Ícono **🔌 New Database Connection** (esquina superior izquierda).
3. Selecciona **PostgreSQL** → **Next**.
4. Llena:
   - **Host**: `localhost`
   - **Port**: `5432`
   - **Database**: `trafodex`
   - **Username**: `laravel`
   - **Password**: `secret` (marca "Save password")
5. Click **Test Connection**:
   - La primera vez te pedirá descargar el driver JDBC de PostgreSQL → click **Download** → espera 5 segundos.
   - Si sale "Connected", click **Finish**.

En el panel izquierdo verás `trafodex` → expande → **Schemas** → **public** → **Tables**.

### 3.3. Conectar a tu MySQL de Laragon (opcional)

Si quieres seguir consultando datos antiguos:

1. **🔌 New Database Connection** → **MySQL** → Next.
2. Llena:
   - **Host**: `localhost`
   - **Port**: `3306`
   - **Database**: (deja vacío para ver todas)
   - **Username**: `root`
   - **Password**: (vacío en Laragon por defecto)
3. **Test Connection** → si descarga driver → **Finish**.

Ahora tienes **ambas BDs** visibles en el panel izquierdo.

### 3.4. Atajos útiles de DBeaver

| Acción | Atajo |
|---|---|
| Nueva consulta SQL | `Ctrl+]` |
| Ejecutar consulta | `Ctrl+Enter` |
| Ver datos de una tabla | doble click en la tabla → pestaña **Data** |
| Editar fila | doble click en una celda → editar → guardar con `Ctrl+S` |
| Generar SQL de la tabla | click derecho → **Generate SQL → DDL** |
| Exportar datos | click derecho → **Export Data** (CSV, JSON, Excel) |

---

## 4. Verificación final

Antes de clonar el proyecto, valida que **todo funciona**:

```bash
# En la terminal de Laragon (Ctrl+Alt+T)
php -v                  # PHP 8.3.x
php -m | findstr pgsql  # debe listar pdo_pgsql y pgsql
composer -V             # Composer 2.x
node -v                 # v20.x.x
npm -v                  # 10.x.x
```

Y desde **pgAdmin** o **DBeaver** debes poder conectar a `localhost:5432 / trafodex / laravel`.

✅ Si todo lo anterior pasa, estás listo para clonar el proyecto y seguir el [README principal](../README.md).

---

## Troubleshooting

| Síntoma | Causa | Solución |
|---|---|---|
| `php -m` no incluye `pgsql` | Extensión no habilitada | Laragon → PHP → Extensions → marcar `pdo_pgsql` y `pgsql` → reiniciar |
| pgAdmin no abre / pantalla en blanco | Bug conocido en algunas versiones | Usa DBeaver en su lugar |
| No me acepta la password de `postgres` | La olvidaste | Reinstalar PostgreSQL es lo más rápido en desarrollo |
| Puerto 5432 ocupado | Otra instancia de PG corriendo | `netstat -ano \| findstr 5432` para ver qué usa el puerto |
| `psql: command not found` | Ruta no en PATH | Agregar `C:\Program Files\PostgreSQL\16\bin` al PATH de Windows |
| DBeaver pide driver y falla | Sin internet o firewall bloquea | Descargar manualmente el JDBC de https://jdbc.postgresql.org/ |
| Apache de Laragon no arranca | Puerto 80 ocupado (IIS, Skype) | Detén el servicio que ocupe el puerto, o cambia Apache a 8080 en Laragon |

---

## ¿Y cuando despliegue a producción?

Para producción (DigitalOcean / VPS Linux) **no usas Laragon ni instalador de Windows**. Se instala todo con `apt`:

```bash
sudo apt install php8.3-fpm php8.3-pgsql php8.3-mbstring nginx postgresql-16
```

Detalle completo en [`DEPLOY.md`](DEPLOY.md) y [`../README-PROD.md`](../README-PROD.md).

---

## Próximos pasos después de instalar

1. Clonar el repo y correr `composer install` + `npm install` — guía en [`../README-DEV.md`](../README-DEV.md#12-clonar-e-instalar).
2. Crear la BD `trafodex` y la extensión `unaccent`.
3. Configurar `.env` (`APP_KEY`, `DB_*`, `MAIL_*`) — referencia en [`ENV.md`](ENV.md).
4. Correr `php artisan setup:project` para sembrar toda la data inicial.

---

## Documentación relacionada

- [`../README-DEV.md`](../README-DEV.md) — qué hacer después de instalar las herramientas
- [`ENV.md`](ENV.md) — qué variables setear en `.env` después del install
- [`MAIL-SETUP.md`](MAIL-SETUP.md) — configurar SMTP en dev (puede ser `log` driver)
- [`DEPLOY.md`](DEPLOY.md) — stack equivalente para producción Linux
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — errores comunes durante la instalación
