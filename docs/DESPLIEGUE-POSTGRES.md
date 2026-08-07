# Tres aplicaciones, tres bases, un solo servidor

Guion para dejar TRAFODEX, tenkofiz y DOCUFIZ conviviendo en el mismo PostgreSQL sin que ninguna
pueda ver los datos de las otras.

La idea de fondo: en PostgreSQL **los roles son del servidor, no de la base**, así que crear un
usuario por aplicación no aísla nada por sí solo. Lo que aísla es revocar el permiso de conexión al
pseudo-rol `PUBLIC`, que por defecto deja entrar a cualquiera a cualquier base.

---

## 1. Instalar PostgreSQL en el droplet

```bash
sudo apt update
sudo apt install -y postgresql-16 postgresql-contrib
sudo systemctl enable --now postgresql
psql --version
```

## 2. Crear las tres bases

Una sola sesión como `postgres`:

```bash
sudo -u postgres psql
```

```sql
-- ── DOCUFIZ ──────────────────────────────────────────────
CREATE USER docufiz  WITH PASSWORD 'clave-larga-distinta-1';
CREATE DATABASE docufiz  OWNER docufiz;
REVOKE CONNECT ON DATABASE docufiz  FROM PUBLIC;
GRANT  CONNECT ON DATABASE docufiz  TO docufiz;

-- ── TRAFODEX ─────────────────────────────────────────────
CREATE USER trafodex WITH PASSWORD 'clave-larga-distinta-2';
CREATE DATABASE trafodex OWNER trafodex;
REVOKE CONNECT ON DATABASE trafodex FROM PUBLIC;
GRANT  CONNECT ON DATABASE trafodex TO trafodex;

-- ── tenkofiz ─────────────────────────────────────────────
CREATE USER tenkofiz WITH PASSWORD 'clave-larga-distinta-3';
CREATE DATABASE tenkofiz OWNER tenkofiz;
REVOKE CONNECT ON DATABASE tenkofiz FROM PUBLIC;
GRANT  CONNECT ON DATABASE tenkofiz TO tenkofiz;
```

Tres contraseñas **distintas**. Si son la misma, el aislamiento vale la mitad.

## 3. Extensiones y permisos, dentro de cada base

`CREATE EXTENSION` necesita superusuario y se aplica **a la base donde estás parado**, no al
servidor. Por eso hay que entrar a cada una:

```sql
\c docufiz
CREATE EXTENSION IF NOT EXISTS unaccent;
GRANT ALL ON SCHEMA public TO docufiz;

\c trafodex
CREATE EXTENSION IF NOT EXISTS unaccent;
GRANT ALL ON SCHEMA public TO trafodex;

\c tenkofiz
GRANT ALL ON SCHEMA public TO tenkofiz;

\q
```

El `GRANT ALL ON SCHEMA public` hace falta desde PostgreSQL 15: antes cualquiera podía crear tablas
en `public`, ahora solo el dueño. Sin esa línea, `php artisan migrate` falla con un permiso
denegado que despista bastante.

tenkofiz no usa `unaccent`; solo se lo damos a quien lo necesita.

## 4. Cerrar el servidor al exterior

Si las tres aplicaciones corren en el **mismo droplet** que la base, PostgreSQL no debe escuchar en
la red. Comprueba en `/etc/postgresql/16/main/postgresql.conf`:

```
listen_addresses = 'localhost'
```

Y en `/etc/postgresql/16/main/pg_hba.conf`, que las conexiones locales pidan contraseña:

```
local   all   all                 scram-sha-256
host    all   all   127.0.0.1/32  scram-sha-256
```

```bash
sudo systemctl restart postgresql
```

Si la base va en **otro droplet**, no lo abras a internet: usa la red privada (VPC) de Digital
Ocean, limita `listen_addresses` a esa IP privada y exige TLS. El detalle está en
`docs/DROPLET-POSTGRES-SECURITY.md`.

## 5. Configurar cada aplicación

En el `.env` de DOCUFIZ:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=docufiz
DB_USERNAME=docufiz
DB_PASSWORD=clave-larga-distinta-1
```

Y lo mismo, cambiando los tres valores, en cada una de las otras. Ninguna usa `postgres`.

```bash
php artisan migrate --force
```

## 6. Comprobar que el aislamiento existe de verdad

Este paso no es opcional: es el único que demuestra que los cinco comandos de arriba hicieron lo que
crees que hicieron.

```bash
# El usuario de DOCUFIZ contra su propia base: tiene que funcionar
PGPASSWORD='clave-larga-distinta-1' psql -h 127.0.0.1 -U docufiz -d docufiz -c '\dt'

# El mismo usuario contra la base de TRAFODEX: tiene que FALLAR
PGPASSWORD='clave-larga-distinta-1' psql -h 127.0.0.1 -U docufiz -d trafodex -c '\dt'
```

La segunda debe responder:

```
FATAL:  permission denied for database "trafodex"
```

Si en vez de eso te lista las tablas, falta el `REVOKE CONNECT ... FROM PUBLIC` en esa base.

## 7. Copias de seguridad

Una por base, para poder restaurar una sola sin tocar las demás:

```bash
sudo -u postgres pg_dump -Fc docufiz  > /var/backups/docufiz-$(date +%F).dump
sudo -u postgres pg_dump -Fc trafodex > /var/backups/trafodex-$(date +%F).dump
sudo -u postgres pg_dump -Fc tenkofiz > /var/backups/tenkofiz-$(date +%F).dump
```

Automatizado, en el crontab de root:

```cron
0 3 * * * for d in docufiz trafodex tenkofiz; do sudo -u postgres pg_dump -Fc $d | gpg -c --batch --passphrase-file /root/.backup-pass > /var/backups/$d-$(date +\%F).dump.gpg; done
15 3 * * * find /var/backups -name '*.dump.gpg' -mtime +30 -delete
```

**Una copia que nunca se restauró no es una copia.** Prueba el `pg_restore` en una base de pruebas
al menos una vez, y repítelo cada cierto tiempo.

---

## Resumen

| Paso | Por qué |
| --- | --- |
| Un rol y una base por aplicación | limita el daño si se filtra una credencial |
| `REVOKE CONNECT ... FROM PUBLIC` | **sin esto, el usuario de una app entra a las otras bases** |
| `GRANT ALL ON SCHEMA public` | desde PostgreSQL 15 hace falta para que `migrate` pueda crear tablas |
| `CREATE EXTENSION` dentro de cada base | es por base, no por servidor, y pide superusuario |
| Ninguna app usa `postgres` | ese usuario manda sobre todo el servidor |
| `listen_addresses = 'localhost'` | la base no tiene por qué estar expuesta |
| Probar la conexión cruzada | es la única prueba de que el aislamiento funciona |
| Una copia por base, cifrada y probada | restaurar una sin arrastrar las otras |
