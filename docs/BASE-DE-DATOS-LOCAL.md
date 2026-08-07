# Migrar en local y subir la base ya terminada

La forma de trabajo que vamos a seguir, y la razón por la que es la buena.

## La idea

En vez de conectar el servidor de producción a la base vieja y migrar allí, se hace todo **en tu
máquina**: importas el volcado del sistema anterior a tu MySQL local, corres los comandos de
migración contra tu PostgreSQL local, revisas el resultado con calma, y **cuando la base está
terminada y probada, subes esa base ya hecha** al droplet.

```
   volcado MySQL              tu máquina                        droplet
   del sistema viejo   ─────> MySQL local ──┐
                                            ├─> PostgreSQL local ──> pg_dump ──> PostgreSQL
   (lo bajas una vez)         DOCUFIZ ──────┘    (revisas aquí)                  de producción
```

## Por qué es mejor que migrar en el servidor

| | Migrar en el servidor | Migrar en local y subir la base |
| --- | --- | --- |
| La base vieja | tiene que estar accesible desde producción | no sale de tu máquina |
| Si algo sale mal | ya está mal en producción | lo repites hasta que salga bien, sin que nadie lo vea |
| Cuántas veces se ejecuta | una, con miedo | las que hagan falta |
| Datos personales | viajan por la red hacia otro servidor | se quedan donde ya estaban |
| Producción necesita MySQL | sí, un motor de más solo para esto | **no**, solo PostgreSQL |
| Tiempo de la aplicación parada | el que dure la migración | ninguno: llega hecha |

El último punto es el que más pesa: los AST del sistema viejo son 226 875 filas. Eso no es algo que
quieras estar mirando en un droplet de producción con la aplicación arriba.

---

## Los pasos

### 1. Bajar el volcado del sistema anterior

Del servidor donde vive hoy el sistema viejo:

```bash
mysqldump -u USUARIO -p --single-transaction --routines doc_app_development > doc_app.sql
```

`--single-transaction` para que no bloquee las tablas mientras la gente trabaja.

### 2. Importarlo en tu MySQL local

Con Laragon o XAMPP, desde phpMyAdmin: crea la base `doc_app_legacy` e importa el `.sql`.

Si el archivo es grande, phpMyAdmin se atraganta; por consola no:

```bash
mysql -u root -p -e "CREATE DATABASE doc_app_legacy CHARACTER SET utf8mb4"
mysql -u root -p doc_app_legacy < doc_app.sql
```

### 3. Apuntar DOCUFIZ a las dos bases

En tu `.env` local conviven las dos conexiones. La nueva es donde escribe; la vieja **solo se lee**:

```dotenv
# La base nueva: aquí se escribe todo
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=docufiz
DB_USERNAME=docufiz
DB_PASSWORD=tu-clave-local

# La base vieja: solo lectura, nunca se escribe en ella
LEGACY_DB_CONNECTION=mysql
LEGACY_DB_HOST=127.0.0.1
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=doc_app_legacy
LEGACY_DB_USERNAME=root
LEGACY_DB_PASSWORD=
```

### 4. Preparar la base nueva

```bash
php artisan migrate:fresh --seed
```

### 5. Migrar, por pasos

Cada comando es **idempotente**: puedes repetirlo sin duplicar nada, porque cada fila migrada guarda
su `legacy_id`. Corre uno, mira lo que dice, y sigue.

```bash
php artisan docufiz:migrate-formats     # las plantillas AST, PTF, EPP e IHM con los catálogos reales
php artisan docufiz:migrate-data empresas
php artisan docufiz:migrate-data personas
```

Los comandos imprimen **origen contra destino**. Si no cuadra, se ve ahí mismo, y ese es el momento
de mirarlo — no cuando ya está en producción.

### 6. Revisar

Este paso es el que justifica todo lo demás. Entra a la aplicación en local, con los datos reales
puestos, y comprueba lo que solo se ve mirando:

- que las personas que trabajan en varias empresas aparezcan **una sola vez**
- que los 13 documentos con nombres distintos entre tablas (los lista
  `docufiz:migrate-data personas`) tengan el nombre correcto
- que los formatos tengan sus campos y sus catálogos
- que los planes cuelguen de la empresa que les toca

Corrige lo que haga falta, vuelve a correr los comandos, y repite hasta que esté bien.

### 7. Sacar la base terminada

```bash
pg_dump -h 127.0.0.1 -U docufiz -Fc docufiz > docufiz-lista.dump
```

`-Fc` es el formato comprimido de PostgreSQL: ocupa menos y `pg_restore` lo entiende directamente.

### 8. Subirla al droplet

```bash
scp docufiz-lista.dump root@TU-DROPLET:/tmp/
```

Y allí, una vez creada la base y el usuario según `docs/DESPLIEGUE-POSTGRES.md`:

```bash
sudo -u postgres pg_restore -d docufiz --no-owner --role=docufiz /tmp/docufiz-lista.dump
rm /tmp/docufiz-lista.dump
```

`--no-owner --role=docufiz` hace que todo quede a nombre del usuario de la aplicación, aunque en tu
máquina se llamara distinto.

### 9. Comprobar del otro lado

```bash
php artisan migrate --force   # no debería haber nada que aplicar
php artisan tinker --execute='echo App\Models\Person::count(), " personas", PHP_EOL;'
```

Los números tienen que ser los mismos que viste en local. Si no, algo se quedó por el camino.

---

## Dos avisos

**El archivo `.dump` lleva datos personales reales** — nombres y documentos de 228 personas. No va
al repositorio (que es público), no va por correo y no se queda en `/tmp` del droplet. Bórralo de
los dos lados cuando termines.

**Las fotos y firmas no van en el volcado.** Son archivos en el disco del servidor viejo
(`public/images_uploads`). Se copian aparte con `rsync`, y son unos pocos cientos: de las 9 012
fotos que registra la base vieja, **7 508 son la cadena `detected_by_IA`** y el archivo nunca
existió. Eso está explicado en `docs/MIGRACION.md`.

## Después de la primera vez

Este camino sirve igual para volver a hacerlo. Si dentro de un mes decides que la migración de los
planes hay que rehacerla, la repites en local, sacas otro volcado y lo restauras. Producción solo ve
bases terminadas.
