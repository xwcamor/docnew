# Seguridad y manejo de secretos (dev + prod)

> Doc de **dev/ops**, no del manual de usuario. Cubre cómo se manejan los
> secretos (.env, claves, datos sensibles) en desarrollo y producción, y qué
> usan de verdad las empresas grandes — para no improvisar.

## TL;DR / recomendación para este proyecto (droplet Digital Ocean, SaaS chico)

1. **Dev**: `.env` en texto plano, **fuera de git** (ya gitignored). `.env.example`
   es la plantilla. Nunca commitear `.env`.
2. **Prod (suficiente y correcto para un solo servidor)**: `.env` en el droplet
   con `chmod 600` y dueño del usuario de la app, **fuera de git**. El verdadero
   blindaje del servidor: HTTPS/TLS, firewall (UFW), usuario de BD con privilegios
   mínimos, backups, y `APP_KEY` resguardada.
3. **BD gestionada de DO** (Managed Postgres) → te da **encryption at rest** + TLS
   gratis. Eso cubre el caso "robaron el disco" sin cifrar nada a mano.
4. Si quieres subir un escalón sin complejidad: un **secrets manager** (Doppler o
   Infisical, ambos con free tier) inyecta las env vars en runtime → los secretos
   no quedan en texto plano en la caja. Es el patrón "pro" accesible.

No hace falta más para el tamaño actual. Lo demás (abajo) es para entender el
espectro y crecer sin sorpresas.

> **Blindaje del droplet de BD**: el paso a paso de cómo montar PostgreSQL en
> Digital Ocean sin exponerlo a internet (VPC, `pg_hba.conf`, TLS, roles mínimos,
> túnel SSH para tu laptop, backups cifrados) está en
> [`DROPLET-POSTGRES-SECURITY.md`](DROPLET-POSTGRES-SECURITY.md).

---

## 1. Separar 3 conceptos (en Rails venían mezclados)

| Qué | Para qué | Dónde vive |
|---|---|---|
| **Secretos de config** (DB password, API keys) | conectar a servicios | `.env` (o secrets manager) |
| **`APP_KEY`** | cifrar **datos de la app**: cookies, sesión, URLs firmadas, `Crypt::encrypt()` | `.env` (¡resguardar!) |
| **Cifrado de columnas** | datos sensibles **en reposo** dentro de la BD | cast `'encrypted'` en el modelo |

`APP_KEY` **NO** cifra el `.env`. Si la pierdes, no desencriptas lo que cifraste
con ella (cookies viejas, columnas cifradas, etc.).

## 2. Traducción Rails → Laravel

| Rails | Laravel |
|---|---|
| `master.key` / `RAILS_MASTER_KEY` | `LARAVEL_ENV_ENCRYPTION_KEY` |
| `config/credentials.yml.enc` | `.env.encrypted` (`php artisan env:encrypt`) |
| `secret_key_base` | `APP_KEY` |
| `encrypts :columna` (AR Encryption) | cast `'encrypted'` |

## 3. Desarrollo (dev)

- `.env` plano, **gitignored**. Copiar de `.env.example` y completar.
- `php artisan key:generate` para el `APP_KEY` local.
- **No** usar credenciales reales de prod en dev.
- Secretos del front (ej. `VITE_AMCHARTS_LICENSE`) van con prefijo `VITE_` y
  **terminan visibles en el bundle** — solo para llaves NO secretas (las de
  amCharts se aplican client-side, no son secreto).

## 4. Producción — opciones de menor a mayor robustez

### A. `.env` plano con permisos cerrados (lo más común en single-server)
```bash
chmod 600 .env
chown deploy:deploy .env     # dueño = usuario de la app
```
Fuera de git. Para un droplet único es correcto y estándar.

### B. `.env.encrypted` (estilo Rails credentials) — deploys self-contained
Cifra el `.env` y commitea SOLO el cifrado; guardas una única clave.
```bash
# Genera .env.encrypted + imprime la clave UNA vez (guárdala en un gestor):
php artisan env:encrypt
# o con tu propia clave:
php artisan env:encrypt --key=base64:XXXXXXXX

# En el servidor / CI, antes de arrancar:
php artisan env:decrypt --key="$LARAVEL_ENV_ENCRYPTION_KEY"
```
- `.env.encrypted` **sí** se puede commitear; la clave va en
  `LARAVEL_ENV_ENCRYPTION_KEY` (env var del runner/servidor), **nunca** en el repo.
- **Honestidad**: en un solo droplet, la clave vive en la misma caja que el
  cifrado → la ganancia de seguridad sobre la opción A es marginal. Su valor real
  es CI/CD y multi-entorno (secretos versionados sin exponerlos).

### C. Secrets manager (lo recomendable si quieres "pro" sin montar Vault)
- **Doppler** / **Infisical** (free tier): inyectan las env vars en runtime; los
  secretos no quedan en archivos en el box; rotación y auditoría incluidas.
- DO **App Platform**: env vars cifradas inyectadas por la plataforma.

## 5. Qué usan las super empresas (honesto, sin humo)

No usan `.env` en el repo ni `.env.encrypted`. El estándar a escala es:

- **12-factor**: la configuración vive en **variables de entorno inyectadas por la
  plataforma** (Kubernetes **Secrets**, ECS task definitions, etc.). La app solo
  lee `env()`; no hay archivo `.env` en prod.
- **Secrets manager dedicado**: HashiCorp **Vault**, **AWS Secrets Manager**,
  **GCP Secret Manager**, **Azure Key Vault**. Fetch en runtime, **rotación
  automática**, **auditoría** de cada acceso, **least privilege** vía IAM.
- **KMS** (AWS KMS, Cloud KMS) para las *master keys*; las claves nunca tocan el
  código ni el repo.
- **BD**: **encryption at rest** (gestionada por KMS) + **TLS in transit**;
  cifrado a nivel **columna** solo para PII puntual (no toda la tabla).
- Reglas duras: secretos **nunca** en código, repo, logs ni en el front; rotación
  periódica; acceso auditado; mínimo privilegio.

**Para TU escala** eso es overkill. El camino sano de crecimiento:
`.env` chmod 600 → (si querés) Doppler/Infisical → (si algún día hay equipo
grande/compliance) Vault o el secrets manager del cloud.

## 6. Cifrado de columnas (datos sensibles en la BD)

Para campos puntuales (PII): usar el cast `'encrypted'`.
```php
protected $casts = [
    'numero_documento' => 'encrypted',
];
```
- Cifra/descifra automático con `APP_KEY`.
- **Trade-off**: una columna cifrada **no se puede buscar ni indexar** por su valor.
  Reservar para datos que NO se filtran/buscan. No cifrar toda la BB así.
- Para "robaron el disco" alcanza la **encryption at rest** del disco / BD
  gestionada; el cast por columna es para "ni el DBA ve ese dato en claro".

## 6.5. Evitar que bots/hackers lean tus claves (lo que de verdad importa)

> Dónde guardás el secreto importa MENOS que esto: si un atacante puede **leer
> el archivo** o **ejecutar código** en el server, lee el `.env` igual (incluso
> con Vault: el secreto descifrado vive en el entorno del proceso). Cerrar estas
> vías es la prioridad #1.

Vías reales por las que se fuga el `.env` de un Laravel, ordenadas por frecuencia:

1. **`.env` accesible por web (la #1 en la vida real).** Si el web root apunta a
   la **raíz del proyecto** en vez de a `public/`, cualquiera baja
   `https://tuapp.com/.env`. Los bots lo prueban 24/7.
   → El document root **DEBE** ser `.../public`. Verificá: `curl https://tuapp.com/.env`
   tiene que dar **404**, nunca el contenido.
2. **`APP_DEBUG=true` en prod.** La página de error de Laravel (Ignition) muestra
   stack traces **con variables de entorno y credenciales**. Además hubo un RCE
   (CVE-2021-3129) que se explota con debug on. → `APP_DEBUG=false`, `APP_ENV=production`.
3. **`.git/` expuesto.** Si subís el `.git/` al web root, bajan todo el repo (y si
   alguna vez se commiteó un `.env`, ahí está). → No deployar `.git/`; bloquearlo.
4. **Páginas de debug expuestas**: `phpinfo()`, **Telescope**, **Horizon**, swagger.
   Filtran env. → deshabilitar en prod o detrás de auth.
5. **PHP servido como texto** (FPM mal configurado): los `.php` se descargan como
   código fuente → secretos a la vista. → Verificá que PHP ejecuta.
6. **Archivos de backup/editor** en `public/`: `.env.bak`, `.env~`, `config.php.save`.
   → Bloquear dotfiles y extensiones de backup.
7. **RCE por dependencia vieja** o por la app → shell → lee el `.env`. → Parchear
   (`composer audit`), usuario de BD con privilegios mínimos.
8. **Acceso SSH** (password débil, root). → SSH **solo con llave**, `fail2ban`,
   firewall, usuario de deploy no-root.

### Nginx endurecido (bloquea las vías 1, 3, 6)
```nginx
server {
    server_name tuapp.com;
    root /var/www/trapnew/public;        # ← public, NUNCA la raíz del proyecto
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    # PHP via FPM
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    # Bloquear dotfiles (.env, .git, .htaccess, etc.) y backups
    location ~ /\.          { deny all; return 404; }
    location ~ ~$           { deny all; return 404; }
    location ~* \.(env|bak|save|old|sql|log)$ { deny all; return 404; }
}
```
> Con Apache, lo equivalente: `AllowOverride` + reglas en `.htaccess` que bloqueen
> dotfiles, y el `DocumentRoot` en `public/`. Laravel ya trae `public/.htaccess`.

### Capa extra (gratis y efectiva contra bots)
- **Cloudflare** delante (proxy): oculta la IP del droplet, WAF básico gratis,
  rate limit, bloqueo de scanners conocidos.
- **`fail2ban`**: banea IPs que prueban `/.env`, `/.git`, login por fuerza bruta.
- **UFW**: cerrar todo menos 22/80/443; la BD **nunca** expuesta a internet.
- HTTPS obligatorio (Let's Encrypt) + HSTS.

### Verificación post-deploy (probar como atacante)
```bash
curl -i https://tuapp.com/.env            # debe 404
curl -i https://tuapp.com/.git/config     # debe 404
curl -i https://tuapp.com/storage/logs/laravel.log   # debe 404
# Forzar un error y confirmar que NO muestra stack trace ni env (debug off).
```

## 6.6. Cómo se hackea una app Laravel, en orden de frecuencia real

> "Siempre entran por el `.env`" es medio cierto y conviene entenderlo bien: al
> `.env` **no se entra, se descarga**. No es un exploit, es un archivo estático
> servido por error. Por eso es el intento #1 de los bots (cuesta un GET) y por
> eso se arregla con configuración, no con código.

### Nivel 1 — configuración del servidor (aquí pasa la gran mayoría)

| # | Vía | Qué entrega | Se cierra con |
|---|---|---|---|
| 1 | `GET /.env` con el document root en la raíz del proyecto en vez de `public/` | `DB_PASSWORD`, `APP_KEY`, SMTP, tokens | `root .../public` + bloqueo de dotfiles (§6.5) |
| 2 | `APP_DEBUG=true` | Ignition imprime **todas** las env vars en la página de error; con debug on hubo RCE (CVE-2021-3129) | `APP_DEBUG=false`, `APP_ENV=production` |
| 3 | `.git/` servido | el repo completo con su historial: si alguna vez se commiteó un `.env`, sigue ahí | no deployar `.git/`; bloquearlo en Nginx |
| 4 | Paneles expuestos: phpMyAdmin, Adminer, Telescope, Horizon, `phpinfo()` | credenciales, env, consultas | no instalarlos en prod, o detrás de auth |
| 5 | SSH con contraseña o root habilitado | el servidor entero | llave únicamente, `PermitRootLogin no`, fail2ban |
| 6 | Puerto de la BD abierto a internet | la base de datos, directo | [`DROPLET-POSTGRES-SECURITY.md`](DROPLET-POSTGRES-SECURITY.md) |

Ninguna de las seis es una vulnerabilidad del código. Son errores de despliegue,
y es la razón por la que el checklist de §7 no es burocracia.

### Qué gana el atacante con tu `.env`, y qué NO

- **`DB_PASSWORD`**: sirve **solo si además puede llegar al puerto de la BD**. Con
  la BD escuchando en la IP privada, la contraseña filtrada no le abre nada desde
  internet. Esto es defensa en profundidad funcionando: una falla no alcanza para
  el desastre.
- **`APP_KEY`**: es la más grave y la que se subestima. Con ella se **forjan
  cookies y sesiones** (firmar una sesión de otro usuario), se firman URLs
  temporales, y se descifra todo lo guardado con `Crypt::encrypt()`. Históricamente
  también dio RCE por deserialización de cookies forjadas. Si se filtra: rotarla
  (sabiendo que invalida sesiones y datos cifrados con la vieja).
- **`MAIL_PASSWORD`**: se usa para enviar correo **desde tu dominio** — phishing a
  tus propios clientes con tu remitente legítimo. Se subestima siempre.

### Nivel 2 — la aplicación

7. **Subida de archivos → webshell.** El objetivo es dejar un `.php` en un
   directorio que el servidor ejecute. Dos capas: validar el archivo, y que el
   directorio de subidas **nunca ejecute PHP** (regla de Nginx).
8. **XSS almacenado → robo de sesión.** Se guarda `<script>` en un campo y se
   ejecuta en el navegador de quien lo lea (idealmente un admin). Mitigado aquí con
   [`HtmlSanitizer`](../app/Support/HtmlSanitizer.php) — ver [`HARDENING.md` §3](HARDENING.md).
9. **IDOR / fuga entre tenants.** Cambiar un ID en la URL y ver datos de otro
   workspace. En un SaaS multi-tenant es la falla más costosa: no se pierde una
   cuenta, se pierde la confianza de todos. Mitigado con doble capa
   (`BelongsToTenant` + FormRequests) — [`HARDENING.md` §1-2](HARDENING.md).
10. **Autenticación**: sin throttle → credential stuffing con contraseñas
    filtradas de otros sitios; tokens de API con `['*']` → un token de lectura
    escribe. Ambos ya cerrados ([`HARDENING.md` §4](HARDENING.md)).
11. **SQL injection**: rara con Eloquent, y aparece en dos lugares concretos:
    `whereRaw`/`DB::raw` concatenando input, y **`orderBy` con un nombre de columna
    que viene del request** (el más común de verdad, porque las tablas ordenables
    lo invitan). Aquí ambos están bien: el `whereRaw` de `unaccent` usa parámetros
    ligados vía [`LikeQuery`](../app/Support/LikeQuery.php), y el `sort` se valida
    contra un `in_array` de columnas permitidas (ej. `Customer::applyFilters`).
    **Regla para módulos nuevos: el nombre de columna del `orderBy` SIEMPRE contra
    lista blanca.** No hay forma de "escapar" un identificador de columna.
12. **Dependencias con CVE** → `composer audit` y `npm audit` antes de cada
    deploy. Es la vía que no depende de que tú escribas mal nada.
13. **Superficie pública sin auth.** En esta app son el portal de informes
    compartidos (`/r/{token}`) y la verificación (`/verify/{code}`). Revisados:
    token + OTP al correo + vencimiento (410) + `throttle` + el drill-down por
    trafo resuelve con `transformerInScope($share, $id)`, así que un token válido
    no alcanza para leer trafos fuera de su alcance. **Es el código que hay que
    auditar primero cuando se toque**: es lo único que un desconocido puede llamar.
14. **dompdf**: `isRemoteEnabled => false` en los 30+ jobs de PDF. Importa más de
    lo que parece — con remoto habilitado, un `<img src="file:///var/www/.../.env">`
    dentro del HTML renderizado **lee archivos locales del servidor** y los estampa
    en el PDF. Es una vía de lectura del `.env` que no pasa por el web root.
    **No cambiar ese flag a `true`.**

### Hallazgo pendiente (2026-07-27)

- **`svg` aceptado en el logo de Customer.**
  [`StoreCustomerRequest`](../app/Http/Requests/BusinessManagement/Customer/StoreCustomerRequest.php)
  y `UpdateCustomerRequest` validan
  `'logo' => ['nullable','image','mimes:jpg,jpeg,png,gif,svg,webp']`. Un SVG es XML
  y puede llevar `<script>`: servido desde tu propio dominio y abierto directo, es
  **XSS almacenado** con la sesión de quien lo abra. El resto de la app **no** lo
  acepta (`ProfileController` photo/signature, `WorkspaceBrandingController` logo,
  `User` photo), así que la inconsistencia sugiere que fue un descuido, no una
  decisión. Acción: quitar `svg` de esas dos reglas. Nota: la regla `image` de
  Laravel 11+ excluye SVG salvo `allow_svg`, así que **puede ya estar bloqueado en
  la práctica** — hay que comprobar cuál gana antes de cantar victoria, y quitarlo
  igual para no depender de eso.
- **`mimes:` valida por extensión, no por contenido** (ya anotado en
  [`HARDENING.md` §10](HARDENING.md)). El riesgo queda bajo porque el nombre se
  regenera al guardar (`Str::random(12)`), pero el cinturón real es que el
  directorio de subidas no ejecute PHP.

---

## 6.7. Catálogo de vías de ataque (más allá del `.env`)

> §6.6 lista lo **frecuente**. Esto es el mapa **completo** por categoría, con lo
> que aplica a esta app en concreto. Sirve para dos cosas: revisar módulos nuevos,
> y responder cuestionarios de seguridad de clientes grandes sin improvisar.

### A. Autorización (la categoría #1 del OWASP Top 10)

1. **IDOR de objeto**: cambiar un ID en la URL. Cubierto por `BelongsToTenant` +
   FormRequests ([`HARDENING.md`](HARDENING.md)).
2. **Autorización de *acción*, no de objeto**: el endpoint existe y olvidaron el
   `permission:x.action`. El objeto es tuyo, la operación no debería serlo.
   **Es el agujero típico de un módulo nuevo**: el scaffold genera las rutas con su
   middleware, pero una ruta agregada a mano después queda sin gate.
3. **Rutas que deben ser solo-super y quedan abiertas a admin.** En esta app es
   concreto y sensible: los **pesos del HI**, los **params fiqui** y las tablas
   **IEEE 2019** son estándar normativo GLOBAL. Si una ruta del editor de reglas
   pierde su `role:super`, un admin de un workspace edita la norma de todos. El gate
   central es `assertCanEditSet` — cualquier endpoint nuevo del editor pasa por ahí.
4. **Mass assignment**: mandar campos extra en el POST (`role_id`, `is_active`,
   `tenant_id`, `plan`, `auto_sign_reports`) y que el modelo los acepte por estar en
   `$fillable`. `tenant_id` ya tiene doble capa; **la regla para campos nuevos**: si
   otorga permisos, cambia de plan o registra un consentimiento, no se asigna desde
   el request — se setea en el servicio.
5. **Escalada horizontal entre tenants con usuario legítimo**: un admin de un
   workspace es un atacante autenticado y con contrato. No es paranoia: es el modelo
   de amenaza normal de un SaaS multi-tenant.

### B. Sesión y credenciales

6. **Credential stuffing**: contraseñas reusadas filtradas de otros sitios. El
   throttle mitiga la velocidad, no el acierto. Defensa real: **2FA** (hoy no hay) y
   longitud mínima.
7. **Tokens de API eternos**: ver el hallazgo de `sanctum.expiration` abajo.
8. **Robo de cookie de sesión** (XSS, malware, wifi sin TLS): con la cookie no hace
   falta la contraseña. Mitigación: HTTPS + `HttpOnly` + `SameSite` + rotación al
   cambiar contraseña.
9. **Flood de recuperación de contraseña** y **enumeración de correos** — ya
   throttleado (`throttle:5,1`).

### C. Entrada de datos

10. **SQL injection**: `whereRaw`/`DB::raw` concatenando, y `orderBy` con columna
    del request (ver §6.6; aquí ambos correctos).
11. **XSS**: almacenado (`v-html`, mitigado con `HtmlSanitizer`), reflejado, y **en
    SVG subido** (hallazgo de §6.6).
12. **Imports de Excel/CSV**: un `.xlsx` es un ZIP con XML → históricamente **XXE**
    (lectura de archivos del servidor) y **zip bomb** (descomprimir 10 MB en 10 GB
    → tumba el droplet). PhpSpreadsheet deshabilita entidades externas en versiones
    actuales, así que la defensa es **mantenerlo actualizado** (`composer audit`),
    más el `max:10240` que ya está.
13. **CSV injection en los *exports***: ver hallazgo abajo.
14. **Path traversal en nombres de archivo** (`../../`): mitigado porque el nombre
    se regenera al guardar (`Str::random(12)`), no se usa el del cliente.

### D. Archivos y almacenamiento

15. **Subida ejecutable → webshell**: el directorio de subidas no debe ejecutar PHP
    (regla de Nginx, en el checklist de §7).
16. **Archivos servidos sin autorización**. **Verificado correcto** en esta app: los
    exports usan `disk = 'local'` (raíz `storage/app/private`, fuera del web root) y
    se sirven por un controlador que filtra
    `Download::where('user_id', Auth::id())` con vencimiento. **Regla para features
    nuevas: ningún archivo con datos de clientes va al disco `public`.** Ese solo
    error convierte un export en una URL pública adivinable.

### E. Red y servidor

17. **SSRF**: hacer que el servidor pida una URL que elige el atacante. Hoy la
    superficie está cerrada (dompdf con `isRemoteEnabled => false`), y **se abre el
    día que se implementen los webhooks** (feature futura documentada). Cuando
    llegue: lista blanca de destinos y **bloquear la IP de metadatos
    `169.254.169.254`** — en Digital Ocean sirve el *user-data* del droplet, que
    suele contener el script de arranque con secretos.
18. **CSRF**: Laravel lo cubre por defecto; el riesgo vive en las **excepciones**
    (`VerifyCsrfToken::$except`) y en endpoints de estado que se agregan como GET.
19. **Cabeceras faltantes**: sin `X-Frame-Options`/CSP hay clickjacking, y una CSP
    es la red de contención cuando un XSS se cuela. Va en el vhost de Nginx.
20. **Puerto de BD, SSH, paneles** — §6.5 y
    [`DROPLET-POSTGRES-SECURITY.md`](DROPLET-POSTGRES-SECURITY.md).

### F. Abuso y disponibilidad (sin vulnerabilidad de por medio)

21. **Agotar el servidor con operaciones caras y legítimas.** Es la categoría más
    subestimada, y en esta app la superficie es real: generar PDFs con dompdf,
    encolar 33 tipos de export, y `diagnose:fleet-cache`. Un droplet de 1-2 GB no
    aguanta un bucle de generación de informes. Ver el hallazgo del portal público.
22. **Costo/correo**: usar los envíos de la app como relay de spam o quemar la cuota
    del proveedor de SMTP.

### G. Lógica de negocio

23. **Preguntas que ninguna herramienta automática hace**: ¿puede un firmante
    aprobar la solicitud que él mismo envió? ¿puede un admin compartir un informe de
    un cliente que no le fue asignado? ¿se puede saltar el `FeatureGate` de plan
    llamando el endpoint directo en vez de la UI? Son las fallas que se explotan sin
    romper nada técnicamente. **El plan gating se valida en el servidor o no vale**:
    esconder el botón en Vue no es control de acceso.

### H. Cadena de suministro y factor humano

24. **Dependencias**: `composer audit` / `npm audit`; los scripts `postinstall` de
    npm ejecutan código en tu máquina y en el build.
25. **Phishing al admin** — con `MAIL_PASSWORD` filtrada el correo sale de tu propio
    dominio y pasa SPF (§6.6).
26. **Insider / equipo**: acceso mínimo, y que la auditoría registre quién vio qué
    (el audit log de la app + `log_connections` de Postgres).

### Hallazgos de esta revisión (2026-07-27)

1. **`/r/{token}/pdf/{transformer}` sin `throttle`** —
   [`routes/web.php`](../routes/web.php). Sus hermanas sí lo tienen (`/code` con
   `share-otp`, `/verify` con `throttle:10,1`), pero la ruta que **más cuesta** no.
   Ese endpoint **renderiza el informe completo con dompdf en vivo** en cada llamada
   (decisión documentada en `CLAUDE.md`), así que un bucle sobre esa URL consume CPU
   y memoria hasta tumbar un droplet chico. Y en los shares **link-only** el token
   ES la credencial: basta que un correo compartido se reenvíe. Acción: `throttle` en
   `/pdf/{transformer}` (y conviene también en `/view` y `/t/{transformer}`).
2. **`sanctum.expiration => null`** — [`config/sanctum.php`](../config/sanctum.php).
   Los tokens de API **no caducan nunca**: uno filtrado en 2026 sigue sirviendo en
   2030. Acción: poner un vencimiento (ej. `60 * 24 * 30`) y programar
   `sanctum:prune-expired`. Cuidado: es un cambio con impacto en clientes que ya
   usen la API de Customer — hay que avisar, no soltarlo en un deploy.
3. **CSV injection en los exports** — los `Generate*CsvJob` escriben con `fputcsv`
   sin neutralizar el primer carácter. Un valor que empieza con `=`, `+`, `-` o `@`
   (ej. un nombre de cliente escrito con mala intención) **Excel lo interpreta como
   fórmula** al abrir el archivo. Severidad honesta: baja — el daño ocurre en la
   máquina de **quien abre el CSV**, no en el servidor. Pero es hallazgo estándar de
   cuestionario de seguridad y el arreglo es una línea: prefijar `'` cuando el valor
   empiece con esos caracteres.

**Verificado correcto en esta revisión** (para no re-auditarlo): descargas de
exports con disco privado + filtro por `user_id` + vencimiento; `sort` contra lista
blanca; `whereRaw` con parámetros ligados; dompdf sin remoto; drill-down del portal
público con `transformerInScope`.

---

## 7. Checklist de deploy (seguridad)

- [ ] `.env` fuera de git, `chmod 600`, dueño = usuario de la app.
- [ ] `APP_KEY` generada y respaldada (sin ella se pierden datos cifrados).
- [ ] HTTPS/TLS (Let's Encrypt) y redirección 80→443.
- [ ] Firewall (UFW): solo 22/80/443; BD no expuesta a internet.
- [ ] Usuario de BD con privilegios mínimos (no `postgres` superuser).
- [ ] BD gestionada con encryption at rest + TLS, o disco cifrado.
- [ ] Backups automáticos de BD probados (restore real, no solo dump).
- [ ] `APP_DEBUG=false`, `APP_ENV=production`.
- [ ] Logs sin secretos; `config:cache` y `route:cache` en deploy.
- [ ] El directorio de subidas (`storage/app/public`) **no ejecuta PHP** (regla de
      Nginx que deniegue `\.php$` bajo `/storage`).
- [ ] `composer audit` y `npm audit` sin vulnerabilidades altas.
- [ ] Probado como atacante: `/.env`, `/.git/config`, `/storage/logs/laravel.log`
      → 404 (§6.5), y una página de error sin stack trace.
