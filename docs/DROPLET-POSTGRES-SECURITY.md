# Droplet de PostgreSQL blindado (Digital Ocean) — guía completa

**Qué es esto**: cómo montar PostgreSQL 16 en Digital Ocean para TRAFODEX de
modo que la base de datos **no sea alcanzable desde internet**, solo desde
(a) los droplets de la aplicación por red privada y (b) tu laptop por túnel SSH.

**Para qué sirve**: es el runbook del componente BD del deploy. Complementa
[`SECURITY.md`](SECURITY.md) (secretos, `.env`, `APP_KEY`) y
[`DEPLOY.md`](DEPLOY.md) (stack general, Nginx, colas).

**Cuándo leerlo**: antes de crear el droplet de producción.

---

## 0. Respuesta directa a las dos preguntas

**"¿Se puede que solo mi laptop entre a ver la BD?"** Sí, y es el objetivo de
esta guía. Pero no se logra con una lista de IPs permitidas: se logra
**no publicando el puerto 5432 en ninguna interfaz pública**. Postgres escucha
únicamente en la IP privada de la VPC (más el socket local). Tu laptop no
"llega" al 5432: abre un **túnel SSH** contra el droplet y el tráfico sale del
otro lado ya dentro de la red privada. Sin tu llave SSH no hay túnel, y sin
túnel el puerto no existe para el mundo.

Por qué no basta el allowlist de IP: tu conexión de casa/oficina es dinámica
(cambia y te quedas afuera), y por VPN/4G la IP cambia sola. Además una IP
permitida sigue siendo un puerto abierto a internet expuesto a escaneo. El
túnel SSH da lo mismo que buscas (solo tu máquina) con autenticación por llave
y sin superficie pública.

**"¿Que otros droplets entren a la BD?"** Sí: los droplets de la app entran por
la **VPC** (red privada de DO, tráfico que no sale a internet), autenticados con
contraseña SCRAM sobre TLS y filtrados por IP privada en el firewall del droplet
de BD. Un droplet que no esté en la lista no conecta, aunque esté en la misma VPC.

**Advertencia importante sobre la VPC**: la VPC **no es un firewall**. Todos los
droplets de tu cuenta en esa VPC se ven entre sí. Si mañana creas un droplet de
pruebas en la misma VPC, alcanza al 5432 salvo que el firewall lo impida. Por eso
el filtro por IP privada (UFW + Cloud Firewall) es obligatorio, no decorativo.

---

## Anexo A — El ataque que esta guía previene (caso real del dueño)

> No es teoría. Al dueño de este proyecto ya le pasó, en un droplet anterior con
> MySQL: **entraron a la base de datos y cifraron/borraron todos los datos**. El
> proyecto quedó intacto: solo la BD. Nunca se supo cómo entraron. Se cambiaron
> las contraseñas de **todos** los usuarios del motor (incluidos los "default" y
> los que parecían deshabilitados) y no volvió a pasar.

### Qué pasó, casi con seguridad

1. El motor escuchaba en la IP pública (`bind-address = 0.0.0.0` en MySQL, el
   equivalente de `listen_addresses = '*'` en Postgres).
2. Existía una cuenta con host `%` (root, un usuario de aplicación, o las
   **cuentas anónimas** que MySQL traía de fábrica y que solo elimina
   `mysql_secure_installation`) con contraseña débil, vacía o reusada.
3. Los bots barren el rango IPv4 completo en minutos y un motor de BD recién
   expuesto aparece en buscadores de servicios el mismo día. Entran, hacen `DROP`
   y dejan una tabla con la nota de rescate.
4. En la campaña clásica contra MySQL (`PLEASE_READ_ME`) **muchas veces no se
   llevan los datos: solo los borran** y afirman tenerlos. Pagar no devuelve nada.

**Que el proyecto quedara intacto y solo la BD afectada es el dato forense clave**:
tuvieron el puerto de la base de datos, no ejecución de código en el servidor. Una
intrusión por la web deja webshells, archivos modificados y logs de Nginx.

### Por qué cambiar las contraseñas funcionó, y por qué no alcanza

Se cerró **la credencial**, no **la puerta**. Con el puerto todavía escuchando en
la IP pública, el sistema sigue a una contraseña de distancia: la próxima clave
débil, un `.env` legible por web (ver [`SECURITY.md` §6.5](SECURITY.md)) o un CVE
del motor devuelven al mismo punto. Y "no volvió a pasar" es indistinguible de
"el bot que lo tenía en su lista dejó de intentar" mientras no se verifique el
puerto con la sección 12.

Cambiar también las cuentas deshabilitadas fue correcto, no paranoia:
"deshabilitado" en un panel suele significar **sin shell del sistema**, no **sin
`GRANT` en la BD**. Un usuario sin login de SO puede seguir autenticando contra
el motor por red.

### Qué de esta guía corta cada paso del ataque

| Paso del ataque | Qué lo bloquea | Sección |
|---|---|---|
| Escaneo encuentra el puerto | `listen_addresses` en IP privada: no hay nada que escanear | 5.2 |
| Conexión desde internet | UFW `deny incoming` + Cloud Firewall + `/32` por droplet | 2.2, 3 |
| Origen no autorizado dentro de la VPC | `pg_hba.conf` por IP `/32` + `reject` final | 5.3 |
| Contraseña interceptada en la red | `hostssl` + `scram-sha-256` (nunca `md5`, nunca `trust`) | 5.3 |
| Fuerza bruta de credenciales | fail2ban + `CONNECTION LIMIT` por rol | 3, 7, 10 |
| Escalar a superusuario / RCE | el rol de la app **no** es superusuario | 7 |
| Borrar/cifrar los datos | backups cifrados, fuera del droplet, con restore probado | 11 |
| No saber qué pasó | `log_connections`, `log_statement='ddl'`, `pg_stat_activity` | 10 |

Dos puntos de esa tabla merecen énfasis, porque son los que convierten el mismo
incidente en un mal rato en vez de una pérdida total:

- **El rol de la app no es superusuario.** En Postgres, un superusuario puede
  ejecutar comandos del sistema operativo con `COPY ... TO PROGRAM`: robar esa
  credencial equivale a tener shell en el droplet. Con `trafodex_app` (sin
  `SUPERUSER`, sin `CREATEROLE`, sin `CREATEDB`), quien la consiga puede dañar
  **esa** base y nada más — no crea usuarios, no lee otras bases, no ejecuta nada.
- **El backup decide el resultado final.** Si te vuelven a cifrar la BD y tienes
  un dump de anoche verificado, pierdes horas. Si no, pierdes el negocio. Un
  backup que el atacante puede borrar no cuenta (ver los tres puntos de la
  sección 11 sobre esto).

### Lo primero que hay que verificar en el droplet actual

Antes de montar nada nuevo, comprueba si el droplet que hoy está en pie sigue
expuesto. Desde tu laptop, contra su IP pública:

```bash
nmap -Pn -p 3306,5432 <IP-publica-del-droplet-actual>
```

Cualquier cosa que no sea `filtered` o `closed` es un incidente en curso, no un
pendiente. Y en el servidor:

```bash
ss -lntp | grep -E '3306|5432'      # debe mostrar 127.0.0.1 o la IP privada, NUNCA 0.0.0.0
sudo ufw status verbose             # inactive tambien es un hallazgo
```

En MySQL, además: `SELECT user, host, plugin FROM mysql.user;` — cualquier fila
con `host = '%'` o con `user = ''` (anónima) es exactamente la puerta del
incidente anterior.

---

## 1. Elegir la topología antes de tocar nada

| | A. Todo en un droplet | B. Droplet de BD separado | C. DO Managed Postgres |
|---|---|---|---|
| Postgres escucha en | socket Unix / `localhost` | IP privada de la VPC | endpoint gestionado por DO |
| Puertos de BD expuestos | **ninguno** | ninguno público | ninguno público (trusted sources) |
| Costo extra | $0 | +$6 a $12/mes | desde ~$15/mes |
| Backups | los haces tú | los haces tú | automáticos + PITR |
| Actualizar Postgres | tú | tú | DO |
| Cuándo | 1 solo app droplet (hoy) | 2+ app droplets, o separar app/datos | no quieres administrar BD |

**Recomendación honesta**: si hoy tienes **un solo** droplet de aplicación, la
opción **A es la más segura y la más barata** — Postgres por socket Unix
literalmente no tiene puerto de red abierto, y no hay tráfico de BD que
interceptar. Es lo que ya está previsto en [`DEPLOY.md`](DEPLOY.md).

Pasa a **B** cuando tengas más de un app droplet (o quieras que el reinicio de
la app no toque la BD). Esta guía documenta **B en detalle** porque es lo que
pediste, y marca con "solo B" lo que no aplica a la opción A.

**C** es la salida si en algún momento no quieres mantener parches, backups y
WAL a mano: DO te da cifrado en reposo, TLS obligatorio, backups diarios con
PITR y "trusted sources" (solo los droplets que autorices llegan al endpoint).
Con Managed DB, tu laptop entra por el mismo criterio: agregas tu IP a trusted
sources, o mejor, túnel SSH a través de un droplet y desde ahí al endpoint.

> Sea A, B o C: **la BD nunca escucha en la IP pública**. Es la única regla que
> no se negocia en todo este documento. Al dueño de este proyecto ya le cifraron
> una BD por no cumplirla — el caso está en el [Anexo A](#anexo-a--el-ataque-que-esta-guía-previene-caso-real-del-dueño).

---

## 2. Crear los droplets (opción B)

En el panel de DO, o con `doctl`. Dos droplets, **misma región** (si no, no
comparten VPC y el tráfico sale a internet):

| Droplet | Rol | Tamaño inicial |
|---|---|---|
| `trafodex-app` | Nginx + PHP-FPM + colas | 2 GB / 1 vCPU |
| `trafodex-db` | PostgreSQL 16 | 2 GB / 1 vCPU (la BD quiere RAM antes que CPU) |

Al crear cada uno:

- **Imagen**: Ubuntu 24.04 LTS.
- **Autenticación**: **SSH keys**, nunca contraseña. Si DO te ofrece "password",
  no lo uses: el 90 % de los droplets comprometidos son por SSH con contraseña.
- **VPC Network**: la misma red privada para los dos (DO crea una por región;
  conviene crear una propia, ej. `vpc-trafodex`).
- **Monitoring**: activado (gratis, da alertas de CPU/disco/RAM).
- **Backups**: activados en los dos (+20 % del costo). No reemplazan `pg_dump`,
  se suman.

Apunta las **IPs privadas** (pestaña Networking del droplet, `10.x.x.x`). Las
vas a usar en todo el resto de la guía. En los ejemplos:

```
trafodex-app   privada 10.10.0.2    pública 203.0.113.10
trafodex-db    privada 10.10.0.3    pública 203.0.113.11
```

### 2.1 Nombre interno para la BD (no uses la IP pelada)

En **cada app droplet**, agrega a `/etc/hosts`:

```
10.10.0.3   db.trafodex.internal
```

Suena cosmético y no lo es: el certificado TLS se emite para ese nombre y así
puedes usar `sslmode=verify-full` (la verificación más estricta) sin pelear con
SAN de tipo IP. Además, si algún día cambias el droplet de BD, editas una línea
en `/etc/hosts` y no el `.env` de cada máquina.

### 2.2 Cloud Firewall de DO (capa 1, antes del droplet)

Crea dos firewalls en el panel (Networking → Firewalls). Filtran **antes** de
que el paquete toque el droplet, así que valen aunque UFW se configure mal:

**Firewall `fw-db`** (aplicado a `trafodex-db`):

| Dirección | Protocolo | Puerto | Origen/Destino |
|---|---|---|---|
| Inbound | TCP | 5432 | **solo** `10.10.0.2` (cada app droplet, uno por uno) |
| Inbound | TCP | 22 | tu IP fija si la tienes, o el droplet `trafodex-app` como salto |
| Outbound | TCP/UDP | all | all (para `apt` y NTP) |

Nada más. Ni 80, ni 443, ni ICMP desde cualquier lado.

**Firewall `fw-app`** (aplicado a `trafodex-app`): inbound 22 (tu IP o todas si
usas fail2ban), 80 y 443. Nunca 5432.

> Si dejas SSH del droplet de BD cerrado al mundo y entras siempre por salto
> (`ssh -J`) desde el app droplet, mejor todavía: la BD queda sin un solo puerto
> abierto a internet. Es la configuración que recomiendo, y el túnel de la
> sección 8 está escrito para funcionar así.

---

## 3. Endurecer el sistema operativo del droplet de BD

Todo esto como `root` la primera vez, después ya no vuelves a entrar como root.

```bash
apt update && apt upgrade -y
apt install -y ufw fail2ban unattended-upgrades gnupg

# Usuario de operación, sin contraseña, con sudo
adduser --disabled-password --gecos "" deploy
usermod -aG sudo deploy
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
cp /root/.ssh/authorized_keys /home/deploy/.ssh/
chown deploy:deploy /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
```

SSH endurecido — crea `/etc/ssh/sshd_config.d/99-hardening.conf`:

```
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AllowUsers deploy
X11Forwarding no
AllowTcpForwarding yes          # NECESARIO para el tunel de tu laptop
MaxAuthTries 3
LoginGraceTime 20
ClientAliveInterval 300
```

```bash
sshd -t && systemctl restart ssh   # sshd -t valida ANTES de reiniciar
```

> Antes de cerrar la sesión de root, abre **otra** terminal y confirma que
> entras como `deploy`. Si te bloqueas, la recuperación es la consola web de DO.

Actualizaciones de seguridad automáticas:

```bash
dpkg-reconfigure --priority=low unattended-upgrades   # responder "Yes"
```

fail2ban (protege SSH; la jaula de Postgres viene en la sección 10):

```bash
cat >/etc/fail2ban/jail.local <<'EOF'
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5

[sshd]
enabled = true
EOF
systemctl enable --now fail2ban
```

UFW — **denegar por defecto** y abrir solo lo imprescindible:

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow from 10.10.0.2 to any port 22 proto tcp comment 'SSH solo desde app droplet'
ufw allow from 10.10.0.2 to any port 5432 proto tcp comment 'Postgres app droplet'
ufw --force enable
ufw status verbose
```

Fíjate que **no hay** `ufw allow 5432` a secas. Un `allow` sin `from` abre el
puerto a todo internet: es el error más común y el más caro de esta guía.

Cada app droplet nuevo = una línea `ufw allow from <IP privada> to any port 5432`
más su entrada en el Cloud Firewall. Que sea manual es deliberado: obliga a
decidir quién entra.

---

## 4. Instalar PostgreSQL 16

Ubuntu 24.04 trae PG 16 en sus repos. Si usas 22.04 (trae PG 14), agrega el
repositorio oficial PGDG, porque el proyecto requiere 16:

```bash
# Solo si estas en Ubuntu 22.04
install -d /usr/share/postgresql-common/pgdg
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
  -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] \
http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" \
  > /etc/apt/sources.list.d/pgdg.list
apt update
```

```bash
apt install -y postgresql-16 postgresql-contrib-16
systemctl status postgresql@16-main
```

Rutas en Debian/Ubuntu (distintas a las de la documentación upstream):

| Qué | Dónde |
|---|---|
| `postgresql.conf` | `/etc/postgresql/16/main/postgresql.conf` |
| `pg_hba.conf` | `/etc/postgresql/16/main/pg_hba.conf` |
| Drop-ins de config | `/etc/postgresql/16/main/conf.d/*.conf` |
| Datos | `/var/lib/postgresql/16/main` |
| Logs | `/var/log/postgresql/postgresql-16-main.log` |

El paquete ya trae `include_dir = 'conf.d'`, así que **no edites
`postgresql.conf`**: pon todo en un archivo propio. Así un `apt upgrade` nunca
te pisa la configuración y ves de un golpe qué cambiaste tú.

---

## 5. Configuración de red y TLS del servidor

### 5.1 Certificados

Postgres necesita certificado propio (no es HTTP, Certbot no encaja de forma
natural). Con una **CA propia de dos comandos** obtienes verificación completa
sin dependencias externas ni renovaciones cada 90 días.

En el droplet de BD, como `root`:

```bash
cd /var/lib/postgresql/16/main   # fuera del alcance de cualquier proceso web

# 1) CA propia, 10 anios
openssl req -new -x509 -days 3650 -nodes -newkey rsa:4096 \
  -keyout ca.key -out ca.crt -subj "/CN=TRAFODEX Postgres CA"

# 2) Certificado del servidor, emitido para el nombre interno
openssl req -new -nodes -newkey rsa:4096 -keyout server.key -out server.csr \
  -subj "/CN=db.trafodex.internal"
openssl x509 -req -in server.csr -days 1825 \
  -CA ca.crt -CAkey ca.key -CAcreateserial -out server.crt \
  -extfile <(printf "subjectAltName=DNS:db.trafodex.internal,IP:10.10.0.3")

chown postgres:postgres server.key server.crt ca.crt
chmod 600 server.key            # Postgres NO arranca si la llave es legible por otros
chmod 644 server.crt ca.crt
rm server.csr
```

Guarda `ca.crt` (es público, se copia a cada cliente) y resguarda `ca.key`
**fuera del droplet**, en tu gestor de contraseñas: con esa llave se pueden
emitir certificados de cliente. Anota el vencimiento del certificado de servidor
(5 años): el día que expire, la app deja de conectar y el mensaje de error no es
obvio.

### 5.2 Drop-in de configuración

`/etc/postgresql/16/main/conf.d/99-trafodex.conf`:

```ini
# --- Red: la BD NO escucha en la IP publica ---
listen_addresses = '10.10.0.3, localhost'
port = 5432

# --- TLS obligatorio ---
ssl = on
ssl_cert_file = '/var/lib/postgresql/16/main/server.crt'
ssl_key_file  = '/var/lib/postgresql/16/main/server.key'
ssl_min_protocol_version = 'TLSv1.2'
ssl_prefer_server_ciphers = on

# --- Autenticacion: SCRAM, nunca md5 ---
password_encryption = scram-sha-256

# --- Limites de sesion (contienen un cliente colgado o abusivo) ---
max_connections = 100
idle_in_transaction_session_timeout = 60s
statement_timeout = 0                    # global 0; se acota POR ROL (seccion 7)
tcp_keepalives_idle = 60

# --- Auditoria basica (seccion 10) ---
log_connections = on
log_disconnections = on
log_hostname = off
log_line_prefix = '%m [%p] %q user=%u db=%d app=%a host=%h '
log_statement = 'ddl'                    # todo CREATE/ALTER/DROP queda registrado
log_min_duration_statement = 2000        # consultas de mas de 2 s
```

Sobre `listen_addresses`: `localhost` queda para mantenimiento local
(`psql` por socket) y para el extremo del túnel SSH. **Nunca** pongas `'*'` ni
`0.0.0.0`: es exactamente lo que esta guía existe para evitar.

`password_encryption` tiene que quedar puesto **antes** de crear los roles: el
hash se calcula al asignar la contraseña. Si creas el usuario primero, se guarda
en el formato anterior y tu regla `scram-sha-256` del `pg_hba.conf` lo rechaza
con un mensaje que no explica nada.

### 5.3 `pg_hba.conf` — quién puede autenticarse y cómo

Este archivo es el control de acceso de Postgres, y **se evalúa en orden**: gana
la primera línea que coincide. Reemplaza el contenido por esto:

```
# TYPE    DATABASE   USER              ADDRESS          METHOD

# Mantenimiento local por socket Unix (sin red, sin contrasena)
local     all        postgres                           peer
local     all        all                                scram-sha-256

# Tunel SSH de tu laptop: llega como loopback DENTRO del droplet.
# Solo el rol de lectura, y con TLS igual.
hostssl   trafodex   trafodex_ro       127.0.0.1/32     scram-sha-256
hostssl   trafodex   trafodex_ro       ::1/128          scram-sha-256

# App droplets: por IP privada, uno por uno. NUNCA una /16 completa.
hostssl   trafodex   trafodex_app      10.10.0.2/32     scram-sha-256

# Cierre explicito: cualquier otra cosa se rechaza.
host      all        all               0.0.0.0/0        reject
host      all        all               ::/0             reject
```

Puntos que importan más de lo que parecen:

- **`hostssl`, no `host`**: `host` acepta conexiones en claro. Con `hostssl`, un
  cliente sin TLS es rechazado en la puerta y la contraseña nunca viaja legible.
- **`/32` por droplet**, no `10.10.0.0/16`. Una regla de rango vuelve a poner a
  cualquier droplet futuro de tu cuenta dentro del perímetro.
- **El rol de la app no puede entrar por el túnel** y el de lectura no puede
  entrar desde el app droplet. Es intencional: si te roban la contraseña de la
  app, no sirve para conectarse desde otro lado.
- Las líneas `reject` finales son redundantes (el default ya es rechazar), pero
  hacen explícita la intención para quien lea el archivo dentro de dos años.

```bash
systemctl reload postgresql@16-main    # reload alcanza para pg_hba
systemctl restart postgresql@16-main   # restart hace falta para listen_addresses/ssl
```

Verifica que Postgres leyó lo que crees:

```bash
sudo -u postgres psql -c "SELECT line_number, type, database, user_name, address, auth_method, error FROM pg_hba_file_rules;"
sudo -u postgres psql -c "SHOW ssl; SHOW listen_addresses; SHOW password_encryption;"
```

Si alguna fila trae `error` no nulo, esa regla **no está activa** aunque el
archivo se vea bien.

---

## 6. Crear la base de datos y la extensión requerida

```bash
sudo -u postgres psql <<'SQL'
CREATE DATABASE trafodex ENCODING 'UTF8' LC_COLLATE 'en_US.UTF-8' LC_CTYPE 'en_US.UTF-8' TEMPLATE template0;
SQL

# unaccent es OBLIGATORIA en este proyecto (busquedas sin acentos ni mayusculas).
# Requiere superusuario, asi que se instala UNA vez como postgres, no desde la app.
sudo -u postgres psql -d trafodex -c "CREATE EXTENSION IF NOT EXISTS unaccent;"
```

Que la extensión la instale `postgres` y no el rol de la app es justamente el
motivo por el que la app **no necesita ser superusuario**. Si algún día una
migración pide una extensión nueva, se instala a mano así, una vez.

---

## 7. Roles y privilegios mínimos

Tres roles con propósitos separados. Nunca uses `postgres` en el `.env`.

```bash
sudo -u postgres psql -d trafodex
```

```sql
-- 1) Rol de la aplicacion: dueno del schema (necesita DDL para las migraciones)
CREATE ROLE trafodex_app LOGIN PASSWORD 'GENERADA-LARGA-ALEATORIA'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION
  CONNECTION LIMIT 40;

-- 2) Rol de solo lectura: para tu laptop y para pg_dump
CREATE ROLE trafodex_ro LOGIN PASSWORD 'OTRA-DISTINTA'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION
  CONNECTION LIMIT 5;

-- 3) Quitar el acceso por defecto de PUBLIC
REVOKE ALL ON DATABASE trafodex FROM PUBLIC;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
REVOKE CONNECT ON DATABASE trafodex FROM PUBLIC;

-- 4) Permisos de la app: dueno del schema public de ESTA base
GRANT CONNECT ON DATABASE trafodex TO trafodex_app;
ALTER SCHEMA public OWNER TO trafodex_app;
GRANT ALL ON SCHEMA public TO trafodex_app;

-- 5) Permisos de lectura: pg_read_all_data cubre las tablas FUTURAS solo
GRANT CONNECT ON DATABASE trafodex TO trafodex_ro;
GRANT USAGE ON SCHEMA public TO trafodex_ro;
GRANT pg_read_all_data TO trafodex_ro;

-- 6) Frenos por rol: una consulta tuya no puede tumbar la app
ALTER ROLE trafodex_ro SET statement_timeout = '30s';
ALTER ROLE trafodex_app SET statement_timeout = '60s';
ALTER ROLE trafodex_app SET idle_in_transaction_session_timeout = '60s';
```

Notas que ahorran horas de depuración:

- **La app necesita DDL.** `php artisan migrate --force` crea y altera tablas, así
  que el rol de la app es dueño del schema. Un rol solo-DML rompe los deploys. Lo
  que sí se le niega: superusuario, crear bases, crear roles y replicación — que
  es lo que un atacante querría para escalar.
- **`pg_read_all_data` (PG 14+) en vez de `GRANT SELECT ON ALL TABLES`**: el
  segundo solo cubre las tablas que existen hoy, así que cada módulo nuevo del
  scaffold `make:module` quedaría invisible para tu laptop hasta re-otorgar. El rol
  predefinido cubre las futuras sin mantenimiento.
- **En PG 15+ el schema `public` ya no es escribible por todos**, pero los
  `REVOKE` explícitos siguen valiendo: dejan el estado claro y protegen si la base
  se restaura en un servidor más viejo.
- Contraseñas: 32+ caracteres aleatorios, distintas entre roles, generadas con
  `openssl rand -base64 32`. Van al `.env` (`chmod 600`) y al gestor de
  contraseñas, nunca a un chat ni a un ticket.

---

## 8. Acceso desde tu laptop (el punto que preguntaste)

El 5432 no está publicado. Tu laptop hace un **port forward por SSH**: abre un
`localhost:6543` local que sale por el otro extremo del SSH hacia el 5432 de la
BD. Autenticación: tu llave SSH primero, y la contraseña de Postgres después.

### 8.1 Con SSH directo al droplet de BD

Si dejaste el 22 del droplet de BD abierto a tu IP:

```powershell
# PowerShell, Windows. -N = no ejecutar comandos remotos, solo el tunel
ssh -N -L 6543:localhost:5432 deploy@203.0.113.11
```

### 8.2 Con salto por el app droplet (recomendado: la BD sin SSH público)

```powershell
ssh -N -J deploy@203.0.113.10 -L 6543:localhost:5432 deploy@10.10.0.3
```

`-J` (jump host) entra primero al app droplet y desde ahí, por la red privada,
al de BD. El droplet de BD queda **sin un solo puerto abierto a internet**.

Deja el túnel abierto en una ventana y conéctate en otra:

```powershell
psql "host=localhost port=6543 dbname=trafodex user=trafodex_ro sslmode=require"
```

### 8.3 pgAdmin / DBeaver / TablePlus

Configura la conexión contra el túnel, no contra el droplet:

| Campo | Valor |
|---|---|
| Host | `localhost` |
| Puerto | `6543` |
| Base | `trafodex` |
| Usuario | `trafodex_ro` |
| SSL mode | `require` |

DBeaver y pgAdmin además saben abrir el túnel SSH ellos mismos (pestaña "SSH" /
"SSH Tunnel"): host del salto, usuario `deploy`, tu llave privada. Es más cómodo
que mantener la ventana abierta a mano.

Sobre `verify-full` desde la laptop: el certificado se emitió para
`db.trafodex.internal`, y por el túnel te conectas a `localhost`, así que la
verificación de nombre falla por diseño. Con `require` alcanza: el tramo de red
real ya va cifrado y autenticado por SSH. Si quieres `verify-full` igual, agrega
`127.0.0.1 db.trafodex.internal` al `hosts` de Windows, copia `ca.crt` y usa
`host=db.trafodex.internal sslrootcert=C:\ruta\ca.crt`.

### 8.4 Por qué solo lectura desde la laptop

`trafodex_ro` no puede escribir. Es a propósito: consultar la producción es
rutina y un `UPDATE` sin `WHERE` a mano no tiene vuelta. Cuando necesites
escribir (una corrección puntual), entra por SSH al droplet y usa el socket
local con el rol de la app, con la intención explícita y un backup fresco.

### 8.5 Lo que NO hay que hacer

- Abrir el 5432 al mundo "un rato para probar". Los escáneres encuentran un
  Postgres nuevo en minutos, y "un rato" se vuelve permanente.
- Instalar pgAdmin como aplicación web en el droplet. Es un panel con
  credenciales de BD colgado de internet; si tiene una vulnerabilidad, ya no
  importa nada de lo anterior. El túnel cubre el mismo caso sin exponer nada.
- Poner `trust` en el `pg_hba.conf` "solo para el túnel": `trust` es sin
  contraseña, y cualquiera que consiga una sesión en el droplet entra a la BD.

---

## 9. Configurar la aplicación Laravel (opción B)

### 9.1 `.env` del app droplet

```env
DB_CONNECTION=pgsql
DB_HOST=db.trafodex.internal      # el nombre de /etc/hosts, no la IP
DB_PORT=5432
DB_DATABASE=trafodex
DB_USERNAME=trafodex_app
DB_PASSWORD=la-generada-en-la-seccion-7
DB_SSLMODE=verify-full
DB_SSLROOTCERT=/etc/ssl/trafodex/ca.crt
```

Copia `ca.crt` del droplet de BD a cada app droplet:

```bash
install -d -m 755 /etc/ssl/trafodex
# scp del ca.crt desde el droplet de BD, luego:
chmod 644 /etc/ssl/trafodex/ca.crt
```

### 9.2 Cambio necesario en `config/database.php`

Hoy la conexión `pgsql` trae `'sslmode' => 'prefer'` **clavado**. `prefer`
significa "cifra si el servidor quiere, y si no, sigo en claro": no valida nada
y acepta degradarse. Hay que hacerlo configurable:

```php
'pgsql' => [
    // ...
    'sslmode'     => env('DB_SSLMODE', 'prefer'),
    'sslrootcert' => env('DB_SSLROOTCERT'),
],
```

El default `prefer` mantiene dev/tests intactos (Postgres local sin TLS sigue
conectando) y producción pone `verify-full` por `.env`. La escala de `sslmode`:

| Valor | Cifra | Valida la CA | Valida el nombre |
|---|---|---|---|
| `disable` / `allow` / `prefer` | no garantizado | no | no |
| `require` | sí | **no** | no |
| `verify-ca` | sí | sí | no |
| `verify-full` | sí | sí | sí |

`require` cifra pero **no verifica quién está del otro lado**, así que no
protege de un intermediario que se haga pasar por la BD. En producción,
`verify-full`.

> Si tu versión de Laravel no mapeara `sslrootcert` a la DSN de PDO, el camino
> equivalente y garantizado es `DB_URL`:
> `DB_URL=pgsql://trafodex_app:PASS@db.trafodex.internal:5432/trafodex?sslmode=verify-full&sslrootcert=/etc/ssl/trafodex/ca.crt`.
> Con `DB_URL` puesto, Laravel ignora los `DB_HOST`/`DB_*` sueltos. En cualquier
> caso, no lo des por hecho: confírmalo con la consulta de 9.3.

### 9.3 Verificar que la app va cifrada de verdad

Es la única prueba que vale (la configuración puede estar puesta y no aplicarse):

```bash
php artisan tinker
>>> DB::select("SELECT s.ssl, s.version, s.cipher, a.usename, a.client_addr
...            FROM pg_stat_ssl s JOIN pg_stat_activity a USING (pid)
...            WHERE a.pid = pg_backend_pid()");
```

`ssl => true` y un `version` TLSv1.2/1.3. Si sale `false`, el `sslmode` no llegó
al driver — revísalo antes de seguir. Después de tocar el `.env`:
`php artisan config:cache`.

### 9.4 Detalles operativos de la opción B

- **`unaccent`**: ya quedó instalada en la sección 6. Si el droplet se recrea
  desde cero, se vuelve a instalar a mano — las migraciones no la crean.
- **Colas y cron** viven en el app droplet y usan la misma conexión (el proyecto
  usa driver `database` para colas, caché y sesiones): la latencia de la VPC entra
  en cada job. Es sub-milisegundo dentro de la misma región, pero es la razón por
  la que los dos droplets tienen que estar en la **misma** región.
- **`pgbouncer` no hace falta** con `max_connections = 100` y un app droplet.
  Anótalo como opción para cuando haya varios app droplets con muchos workers.
- **Latencia mayor que en la opción A.** El proyecto decidió Postgres sin Redis
  apoyado en índices sub-milisegundo; separar la BD suma un salto de red a cada
  consulta. Con una sola app, sigue siendo un argumento a favor de A.

---

## 10. Auditoría y detección

Con lo de la sección 5.2 ya registras conexiones, desconexiones, DDL y consultas
lentas. Para revisar:

```bash
tail -f /var/log/postgresql/postgresql-16-main.log
grep "password authentication failed" /var/log/postgresql/postgresql-16-main.log
```

Quién está conectado ahora mismo:

```sql
SELECT usename, client_addr, application_name, state,
       now() - state_change AS duracion
FROM pg_stat_activity
WHERE datname = 'trafodex';
```

Si aparece un `client_addr` que no es de tus app droplets ni loopback, tienes un
incidente: revisa `ufw status`, `pg_hba_file_rules` y el Cloud Firewall.

**Rotación de logs**: `logrotate` ya cubre los de Postgres en Ubuntu. Los de la
app se rotan con `LOG_STACK=daily` (ya está en `.env.example`); en un droplet
chico, un `laravel.log` sin rotar se come el disco y la BD se detiene por falta
de espacio.

**Jaula fail2ban para Postgres** (opcional; el valor grande está en la de SSH,
porque el 5432 no es público):

`/etc/fail2ban/filter.d/postgresql.conf`:
```
[Definition]
failregex = ^.*password authentication failed for user.*host=<HOST>.*$
            ^.*no pg_hba.conf entry for host "<HOST>".*$
ignoreregex =
```

`/etc/fail2ban/jail.d/postgresql.conf`:
```
[postgresql]
enabled  = true
filter   = postgresql
logpath  = /var/log/postgresql/postgresql-16-main.log
port     = 5432
maxretry = 5
bantime  = 1h
```

`pgaudit` (`apt install postgresql-16-pgaudit`) registra cada `SELECT` con su
texto. Es lo que pide una auditoría formal, y en un droplet chico genera mucho
volumen: déjalo para cuando alguien lo exija por escrito. Ojo: los informes de
TRAFODEX contienen datos de clientes, así que el día que un cliente pregunte
"quién vio mis datos", esto es la respuesta — junto con el audit log que la app
ya lleva a nivel aplicación.

---

## 11. Backups (sin esto, lo demás es decorado)

Tres capas que cubren fallas distintas:

| Capa | Cubre | Frecuencia |
|---|---|---|
| Snapshot de DO | droplet perdido, disco corrupto | diario (automático) |
| `pg_dump` cifrado fuera del droplet | borrado accidental, ransomware, migrar | diario |
| WAL / PITR (`pgBackRest`) | volver a un instante exacto | continuo (opcional) |

Dónde dejar la copia de fuera (Backblaze B2 gratis, FTP de name.com, modelo pull
desde tu laptop) está en **11.1**; la prueba de restore, en **11.2**.

Un snapshot **no** te salva de un `DELETE` con `WHERE` mal escrito descubierto
tres días después: por eso el `pg_dump` con retención.

`/usr/local/bin/trafodex-backup.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

DEST=/var/backups/trafodex
STAMP=$(date +%F-%H%M)
RECIPIENT=backup@trafodex          # llave GPG publica; la privada NO vive aqui

mkdir -p "$DEST"
# -Fc = formato custom (comprimido, restaurable con pg_restore selectivo)
sudo -u postgres pg_dump -Fc trafodex \
  | gpg --encrypt --recipient "$RECIPIENT" --trust-model always \
  > "$DEST/trafodex-$STAMP.dump.gpg"

find "$DEST" -name '*.dump.gpg' -mtime +7 -delete
```

```bash
chmod 700 /usr/local/bin/trafodex-backup.sh
crontab -e   # 15 3 * * * /usr/local/bin/trafodex-backup.sh >> /var/log/trafodex-backup.log 2>&1
```

Puntos que la gente omite y luego lamenta:

- **Cifrado con llave pública**: el droplet puede *crear* el backup y no puede
  *leerlo*. Si lo comprometen, se llevan un archivo inútil. La llave privada vive
  en tu laptop y en tu gestor de contraseñas.
- **Fuera del droplet.** Un backup en el mismo disco no es un backup. Súbelo a
  Spaces o bájalo por `scp` a tu laptop. (El proyecto decidió no usar S3 para
  *archivos de la app*; los backups son otra cosa y sí conviene sacarlos de la
  caja.)
- **Un backup que el atacante puede borrar no es un backup.** Contra ransomware
  esto define el resultado, y es la parte que casi todo el mundo omite:
  - Prefiere el modelo **"tira" (pull)**: tu laptop o un servidor aparte se
    conecta y **baja** el dump. Si el droplet no tiene credenciales para escribir
    ni borrar en el destino, quien tome el droplet no alcanza el histórico.
  - Si lo subes desde el droplet (modelo "empuja"), usa una llave de Spaces con
    permiso **solo de escritura** y activa **versionado** en el bucket. Con una
    llave de borrado en el droplet, el primer `rm -rf` se lleva backup y original.
  - **No guardes un token de la API de DO con permiso de escritura en el
    droplet.** Con ese token se borran los snapshots del propio droplet desde
    dentro — la copia de seguridad y quien la puede destruir en la misma caja.
  - Los 7 días de retención son el mínimo: el borrado se puede descubrir días
    después. Con retención corta, ya solo tienes backups de la base ya destruida.
- **Prueba el restore, no el dump.** Un backup no verificado no es un backup, es
  una esperanza. Procedimiento mensual en **11.2**.
- **Antes de cada deploy con migraciones**, un dump manual. La regla de oro de
  [`DEPLOY.md`](DEPLOY.md) ("nunca `migrate:fresh` sin backup verificado") sigue
  vigente, y el candado de producción evita el dedazo, no el desastre.
- La contraseña de BD no va en el script ni en la línea de comandos (queda en el
  historial y en `ps`). Arriba se usa `sudo -u postgres`, que autentica por
  `peer`. Si necesitas contraseña, va en `~/.pgpass` con `chmod 600`.

### 11.1 Dónde dejar la copia de fuera del droplet

Tamaño real a mover: los dumps del sistema viejo que trae el repo pesan **4.3 MB
en SQL plano**, así que un `pg_dump -Fc` completo de TRAFODEX ronda los **10-20 MB
comprimido**, y crece con el audit log y las muestras nuevas. Un dump diario de
20 MB con 30 días de retención = **600 MB**. Cualquier plan gratuito alcanza de
sobra; el criterio de elección NO es el espacio, es **si el droplet puede borrar
lo que ya subió**.

| Destino | Costo | ¿El droplet puede borrar el histórico? | Papel |
|---|---|---|---|
| **Backblaze B2** (10 GB gratis) | $0 | **No**, con application key sin `deleteFiles` | **primaria** |
| **Tu laptop (modelo pull)** | $0 | **No** — el droplet no tiene credenciales del destino | verificación mensual |
| **FTP de name.com** | ya lo pagas | **Sí** (FTP no tiene permisos por operación) | secundaria, prescindible |
| DO Spaces | $5/mes | No, con llave de solo escritura + versionado | alternativa a B2 |

Combinación recomendada: **B2 como primaria** + **restore mensual desde tu
laptop** + el **FTP como copia extra** si lo quieres. Tres copias en tres lugares,
costo cero adicional.

> El dump se cifra con GPG **antes** de salir del droplet (sección 11), así que
> ninguno de estos proveedores ve datos de tus clientes: solo guardan un archivo
> ilegible sin tu llave privada. Es la postura correcta también para lo legal —
> los informes contienen datos de clientes, y cifrar antes de subir evita
> convertir a Backblaze o a name.com en "destinatarios" de datos personales para
> el registro ante la ANPD (ver los pendientes de LPDP en `CLAUDE.md`).

#### Backblaze B2 (primaria)

Lo que lo hace la opción correcta no es el espacio gratis: es que puedes crear una
llave que **escribe pero no borra**. Aunque tomen el droplet con root y lean el
script, no pueden destruir el histórico — que es exactamente lo que falló en el
incidente del [Anexo A](#anexo-a--el-ataque-que-esta-guía-previene-caso-real-del-dueño).

1. Crea un bucket **privado**, ej. `trafodex-backups`.
2. En "Application Keys", crea una llave **restringida a ese bucket** y marca
   solo `listFiles` + `writeFiles`. **Sin `deleteFiles`.** Guarda el `keyID` y el
   `applicationKey` (se muestra una sola vez).
3. La **retención se configura en el bucket**, no en el script: Lifecycle Rules →
   "Keep only the last version" con los días que quieras. Así el borrado de lo
   viejo lo hace Backblaze, sin que el droplet tenga permiso de borrar nada.
4. Opcional y más fuerte: crea el bucket con **Object Lock** y fija retención por
   archivo. Queda inmutable incluso para ti hasta que venza el plazo.

```bash
# En el droplet, una vez
curl https://rclone.org/install.sh | sudo bash
rclone config    # n) new remote -> tipo "b2" -> account = keyID, key = applicationKey
chmod 600 /root/.config/rclone/rclone.conf
```

Agrega al final de `/usr/local/bin/trafodex-backup.sh`:

```bash
# Sube la copia cifrada. Sin deleteFiles en la llave, un atacante con root
# puede subir basura pero NO borrar los backups anteriores.
rclone copy "$DEST/trafodex-$STAMP.dump.gpg" b2:trafodex-backups/db/ --no-traverse

# Los archivos de la app (logos, imports, fotos de perfil) NO estan en el dump:
# el disco es 'local'. Van aparte, y cambian poco.
tar -czf - -C /var/www/trafodex/storage/app . \
  | gpg --encrypt --recipient "$RECIPIENT" --trust-model always \
  | rclone rcat "b2:trafodex-backups/storage/storage-$STAMP.tar.gz.gpg"
```

Verifica que llegó, sin necesidad de permisos de borrado:

```bash
rclone ls b2:trafodex-backups/db/ | tail -5
```

#### FTP de name.com (secundaria)

Sirve, y hay que ser claro sobre sus límites antes de confiarle algo:

- **No tiene permisos por operación.** La misma cuenta que sube puede borrar y
  sobrescribir todo. Quien lea el script en el droplet borra el histórico
  completo. Por eso es copia **extra**, nunca la única ni la principal.
- **FTP plano manda la contraseña en claro.** Usa **FTPS** (`--ssl-reqd`). Si el
  plan ofrece SFTP, mejor todavía. Sin TLS, la credencial viaja legible por
  internet en cada corrida del cron.
- **Crea una cuenta FTP dedicada**, restringida a un directorio propio
  (`/backups`), distinta de la del hosting web. Si la reusas y la comprometen,
  se llevan también el sitio.
- **Que el directorio no sea accesible por web.** Si `/backups` cae dentro de
  `public_html`, cualquiera baja tus dumps por HTTP. Están cifrados, pero es una
  filtración igual: regálale tiempo a nadie. Verifica con
  `curl -I https://tudominio/backups/`: tiene que dar 403 o 404.
- **Revisa los términos del hosting.** Los planes compartidos suelen prohibir
  usar el espacio web como almacenamiento de backups; el riesgo real es que te
  suspendan la cuenta justo el día que necesitas el archivo.

```bash
# Credenciales FUERA del script, en un archivo que solo root lee
cat >/root/.netrc <<'EOF'
machine ftp.tudominio.com
login backups@tudominio.com
password LA-CONTRASENA
EOF
chmod 600 /root/.netrc
```

```bash
# Al final de trafodex-backup.sh. --ssl-reqd aborta si el servidor no da TLS.
curl --netrc --ssl-reqd --ftp-create-dirs \
     -T "$DEST/trafodex-$STAMP.dump.gpg" \
     "ftp://ftp.tudominio.com/backups/"
```

La contraseña va en `.netrc` y no en la línea de comandos: en el segundo caso
queda visible en `ps` para cualquier usuario del sistema y en el historial.

**La rotación en FTP es el punto incómodo**: borrar lo viejo exige una cuenta con
permiso de borrado, que es justo lo que no quieres en el droplet. Dos salidas
honestas: (a) que el FTP acumule y lo limpies tú desde la laptop cada tanto, o
(b) aceptar que esta copia es prescindible y dejar la garantía real en B2.

#### Modelo pull desde tu laptop (la copia que ningún atacante alcanza)

El droplet no necesita credencial de tu laptop, y tu laptop no expone nada:

```powershell
# PowerShell. Baja el ultimo dump para probar el restore
scp deploy@203.0.113.11:/var/backups/trafodex/trafodex-*.dump.gpg D:\backups\trafodex\
```

### 11.2 Prueba de restore (mensual, no negociable)

Un backup no verificado no es un backup, es una esperanza. En tu PC:

```bash
gpg --decrypt trafodex-2026-07-27-0315.dump.gpg > t.dump
createdb trafodex_restore_test
pg_restore -d trafodex_restore_test --no-owner --role=$USER t.dump
psql -d trafodex_restore_test -c "SELECT count(*) FROM transformers;"
dropdb trafodex_restore_test
```

Si el `count` cuadra con producción, el backup sirve. Bájalo **desde B2**, no del
droplet: así pruebas el archivo *y* la ruta de recuperación real, que es la que
vas a usar el día que el droplet no exista.

---

## 12. Checklist de verificación — pruébalo como atacante

Desde **tu laptop**, contra la IP pública del droplet de BD:

```bash
# 1) El 5432 NO debe estar accesible. Esperado: filtered o closed, NUNCA open
nmap -Pn -p 5432 203.0.113.11

# 2) Conexion directa: debe fallar por timeout, no por contrasena
psql "host=203.0.113.11 port=5432 dbname=trafodex user=trafodex_app sslmode=require"
```

Si el segundo comando pide contraseña, **el puerto está expuesto**: para y revisa
`listen_addresses`, UFW y el Cloud Firewall antes de seguir.

Desde el **app droplet**:

```bash
# 3) Debe conectar y reportar ssl = t
psql "host=db.trafodex.internal dbname=trafodex user=trafodex_app \
      sslmode=verify-full sslrootcert=/etc/ssl/trafodex/ca.crt" \
     -c "SELECT ssl, version FROM pg_stat_ssl WHERE pid = pg_backend_pid();"

# 4) Sin TLS debe ser RECHAZADO (por hostssl)
psql "host=db.trafodex.internal dbname=trafodex user=trafodex_app sslmode=disable"

# 5) El rol de la app no debe poder escalar
psql ... -c "CREATE ROLE intruso LOGIN;"     # esperado: permission denied
psql ... -c "SELECT rolsuper FROM pg_roles WHERE rolname = 'trafodex_app';"  # f
```

Desde **otro droplet** de la misma VPC que NO esté autorizado:

```bash
# 6) Debe fallar. Si conecta, el filtro por IP no esta puesto
psql "host=10.10.0.3 dbname=trafodex user=trafodex_app sslmode=require"
```

Lista final, para firmar el go-live:

- [ ] `nmap` al 5432 público: filtered/closed.
- [ ] `SHOW listen_addresses` sin `*` ni `0.0.0.0`.
- [ ] `pg_hba.conf` con `hostssl` y `scram-sha-256`; ningún `trust`, ningún `md5`.
- [ ] `pg_hba_file_rules` sin filas con `error`.
- [ ] UFW: `deny incoming` por defecto y reglas 5432 con `from <IP privada>`.
- [ ] Cloud Firewall de DO aplicado a los dos droplets.
- [ ] SSH: solo llave, sin root, `sshd -t` limpio, fail2ban activo.
- [ ] `.env` del app droplet con `chmod 600` y `DB_SSLMODE=verify-full`.
- [ ] La app reporta `ssl = true` en `pg_stat_ssl` (verificado, no supuesto).
- [ ] `trafodex_app` no es superusuario y no puede crear roles ni bases.
- [ ] `trafodex_ro` solo lee y solo entra por loopback (túnel).
- [ ] `unaccent` instalada en `trafodex`.
- [ ] `pg_dump` cifrado en cron, fuera del droplet, **con restore probado**.
- [ ] El droplet **no** puede borrar sus propios backups (pull, o llave de solo
      escritura + versionado; sin token de la API de DO con permiso de escritura).
- [ ] Snapshots de DO activos en ambos droplets.
- [ ] `ss -lntp` sin `0.0.0.0:5432`; ningún rol con contraseña reusada del
      droplet anterior (ver [Anexo A](#anexo-a--el-ataque-que-esta-guía-previene-caso-real-del-dueño)).
- [ ] `unattended-upgrades` activo; `LOG_STACK=daily` en el `.env`.
- [ ] `ca.key` y `APP_KEY` resguardadas fuera del droplet.
- [ ] Vencimiento del certificado del servidor anotado en el calendario.

Y el checklist de la app propiamente dicha (document root en `public/`,
`APP_DEBUG=false`, `.env` no descargable) está en
[`SECURITY.md` §6.5](SECURITY.md) — esa parte no la reemplaza nada de aquí: el
camino más probable a tu BD no es el 5432, es un `.env` legible por web.

---

## 13. Errores comunes

| Síntoma | Causa habitual | Solución |
|---|---|---|
| `connection refused` desde el app droplet | Postgres no escucha en la IP privada | `listen_addresses` + `restart` (no `reload`) |
| `connection timed out` | UFW o Cloud Firewall | `ufw status`; revisar reglas del panel |
| `no pg_hba.conf entry for host` | falta la línea del droplet, o quedó debajo de un `reject` | orden del archivo; `pg_hba_file_rules` |
| `no encryption` / `SSL required` | el cliente fue sin TLS contra `hostssl` | `sslmode=verify-full` en el `.env` |
| `SSL error: certificate verify failed` | `ca.crt` mal copiado o `DB_HOST` distinto del CN | `sslrootcert`; usar `db.trafodex.internal` |
| `password authentication failed` con la contraseña correcta | rol creado antes de `password_encryption` | `ALTER ROLE ... PASSWORD '...'` otra vez |
| Postgres no arranca tras poner TLS | `server.key` con permisos abiertos | `chmod 600` + `chown postgres` |
| `permission denied for schema public` | falta `ALTER SCHEMA public OWNER TO trafodex_app` | sección 7 |
| Migraciones fallan con `permission denied` | rol de app sin DDL | la app es dueña del schema, no solo-lectura |
| `too many connections` | workers de cola × app droplets > `max_connections` | subir el límite o `pgbouncer` |

---

## 14. Costo (opción B vs A)

| Concepto | A: un droplet | B: app + BD | C: Managed |
|---|---|---|---|
| App droplet 2 GB | $12 | $12 | $12 |
| BD droplet 2 GB | — | $12 | — |
| Managed Postgres 1 GB | — | — | ~$15 |
| Backups DO (+20 %) | $2.40 | $4.80 | incluido |
| VPC / Cloud Firewall | $0 | $0 | $0 |
| **Total** | **~$14** | **~$29** | **~$27** |

Separar la BD duplica el costo del cómputo. Si el motivo es *seguridad*, la
opción A con Postgres en socket Unix es igual de sólida o más (cero puertos de
BD en la red). Si el motivo es *escalar la app horizontalmente*, B es el camino
correcto. Y si no quieres mantener parches, backups y WAL, C cuesta casi lo mismo
que B y traslada ese trabajo a DO.

---

## 15. Mejoras futuras (no hacen falta hoy)

- **Certificados de cliente (mTLS)**: `pg_hba.conf` con `cert clientcert=verify-full`
  exige que el cliente presente un certificado firmado por tu CA — así la
  contraseña sola no alcanza ni desde una IP autorizada.
- **Cifrado en reposo**: la opción C lo trae de fábrica. En droplet propio,
  LUKS sobre un Volume aparte para `/var/lib/postgresql`. Cubre "se llevaron el
  disco", no "entraron por la app".
- **Réplica de solo lectura** en otro droplet: alta disponibilidad y un lugar
  seguro para consultas pesadas, y de paso el punto al que apuntar tu laptop.
- **`pgaudit`** cuando haya una exigencia formal de auditoría (ver sección 10).
- **RLS (Row Level Security) por tenant**: sería una tercera capa de aislamiento
  además de `BelongsToTenant` y los FormRequests. Es un cambio de arquitectura
  con costo real (el rol de la app tendría que dejar de ser dueño de las tablas);
  queda anotado como opción, no como pendiente.

---

## Documentación relacionada

- [`SECURITY.md`](SECURITY.md) — secretos, `.env`, `APP_KEY`, cómo NO filtrar credenciales
- [`DEPLOY.md`](DEPLOY.md) — stack de producción, Nginx, Supervisor, redeploys
- [`HARDENING.md`](HARDENING.md) — hardening a nivel aplicación (multi-tenant, IDOR, XSS)
- [`ENV.md`](ENV.md) — variables de entorno
- [`../README-PROD.md`](../README-PROD.md) — guía operativa de producción
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — errores comunes de la app
