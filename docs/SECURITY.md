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

**Estado: hecho.** Tres columnas van cifradas en reposo:

| Columna | Por qué | Cómo se sigue usando |
| --- | --- | --- |
| `people.num_doc` | el DNI de 14 000 personas | por **índice ciego** (`num_doc_hash`), ver abajo |
| `person_biometrics.face_descriptor` | dato biométrico: una cara no se puede revocar | nunca se busca; se lee entero en la verificación 1:1 |
| `person_biometrics.consent_text` | a qué dijo que sí cada persona | nunca se busca |

El cast es `App\Casts\Cifrado` y no el `'encrypted'` de Laravel, por un motivo
concreto: el de la casa lanza `DecryptException` en cuanto encuentra un valor en
claro, y entre desplegar el código y terminar de correr el comando de migración
hay una ventana en la que la tabla tiene las dos cosas. El nuestro lee las dos
formas y escribe siempre cifrado.

**Migrar lo que ya existe**: `php artisan docufiz:cifrar-datos-sensibles`
(`--dry-run` para ensayar). Es idempotente y va por lotes.

### El índice ciego, que es lo que salva la búsqueda

Una columna cifrada **no se puede buscar ni indexar** por su valor: el IV es
aleatorio, así que el mismo DNI guardado dos veces produce dos textos distintos.
Y `people.num_doc` es justamente la clave de búsqueda del producto — en la
puerta se escanea el DNI y la persona entra al plan sola.

La salida es una columna aparte, `people.num_doc_hash`, con un **HMAC-SHA256**
del documento normalizado. Determinista (se indexa y se busca por igualdad) y de
un solo sentido. **HMAC y no `sha256()` pelado**: un DNI peruano son ocho cifras,
o sea cien millones de posibilidades, y un hash sin clave se rompe por fuerza
bruta en segundos. La clave se deriva del `APP_KEY` con una etiqueta propia, para
que el índice y el cifrado no compartan material.

Todo esto está en `App\Support\DocumentoBuscable`, y `App\Models\Builders\
PersonQueryBuilder` traduce `where('num_doc', ...)` al índice solo. Ojo: eso
cubre **Eloquent**, no `DB::table('people')`.

**Lo que se pierde:** búsqueda parcial y orden. Un `LIKE '%parcial%'` sobre el
documento ya no es posible y un `ORDER BY num_doc` ordena por el texto cifrado.
Ver `docs/PENDIENTES.md`.

### Las dos verdades incómodas

1. **Esto protege contra quien lee un backup o el disco, no contra quien tiene el
   `APP_KEY`.** La aplicación descifra con esa clave y la clave vive en el `.env`
   del mismo servidor. El `.sql` de la noche deja de ser una lista de DNI; el
   servidor comprometido sigue siéndolo (para eso son las secciones 6.5 y 7).
2. **Si se pierde el `APP_KEY`, estos datos no se recuperan.** Ni los documentos
   ni la posibilidad de buscar por ellos, porque el índice ciego también se
   deriva de esa clave. Hay que custodiarla fuera del servidor.

**Rotar el `APP_KEY` exige re-cifrar**, y en este orden:

1. la clave vieja pasa a `APP_PREVIOUS_KEYS` (así se sigue pudiendo leer),
2. `APP_KEY` recibe la nueva,
3. `php artisan docufiz:cifrar-datos-sensibles --recifrar`,
4. y **solo entonces** se retira la vieja de `APP_PREVIOUS_KEYS`.

Saltarse el paso 1 o el 3 es tirar los datos.

Para "robaron el disco" también ayuda la **encryption at rest** del disco / BD
gestionada; el cast por columna es lo que además tapa el volcado lógico.

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
10. **Autenticación.** Los tokens de API ya no nacen con `['*']`
    ([`HARDENING.md` §4](HARDENING.md)), y `POST password/email` y
    `POST password/reset` llevan `throttle:5,1`.
    `POST login` **no** lleva el middleware `throttle`, pero eso no significa que
    esté abierto: el freno está dentro de
    [`LoginController::loginAccess`](../app/Http/Controllers/AuthManagement/Auth/LoginController.php),
    con `RateLimiter` sobre una clave de **email + IP** y los ajustes
    `security.max_login_attempts` (5) y `security.lockout_minutes` (15) que el super
    puede cambiar. Al superarlos devuelve `auth.locked` con los minutos que faltan,
    y un acierto limpia el contador.
    Esa clave por email+IP frena el ataque a **una** cuenta. Lo que no frena es el
    reparto: una contraseña común probada contra muchos correos distintos usa una
    clave distinta cada vez. Para eso hace falta un límite por IP a secas, y no lo
    hay. **2FA** sigue siendo la defensa de verdad, y tampoco está.
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
13. **Superficie pública sin auth.** Hoy **no hay ninguna**, y es un buen sitio
    donde estar. El portal de informes compartidos por token (`/r/{token}`) se fue
    con la purga del dominio de diagnóstico; todo lo que responde exige sesión.
    **Es la propiedad que hay que defender**: el día que alguien pida un enlace
    público —"que el cliente descargue su AST sin registrarse"— eso pasa a ser lo
    primero que se audita en cada cambio, porque será lo único que un desconocido
    pueda llamar.
14. **dompdf**: `isRemoteEnabled => false` en todos los jobs de PDF (unos 17
    archivos lo declaran). Importa más de lo que parece — con remoto habilitado,
    un `<img src="file:///var/www/.../.env">` dentro del HTML renderizado **lee
    archivos locales del servidor** y los estampa en el PDF. Es una vía de lectura
    del `.env` que no pasa por el web root. **No cambiar ese flag a `true`.**
15. **La evidencia biométrica.** Es lo más sensible que guarda DOCUFIZ:
    - Del rostro **no se guarda ninguna imagen de referencia**. Se guarda el
      descriptor: 128 números con los que se compara, pero con los que no se
      reconstruye la cara.
    - Las **fotos de firma** sí son imágenes. Viven en disco privado y se sirven
      por una ruta que exige `signature_events.review`. Nunca por `/storage/`.
    - En el PDF se incrustan como data-uri leyéndolas del disco. No se genera una
      URL de una cara dentro de un documento que después se manda por correo.
    - El consentimiento del trabajador se registra con fecha y **es obligatorio
      antes de enrolar**. No es una cortesía: es el requisito de tratar un dato
      biométrico.

    La regla de fondo: **la foto de la cara de un trabajador nunca puede salir del
    sistema por un enlace que se reenvíe.** Cualquier cambio que introduzca una URL
    pública a `evidence_files` rompe esto.

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
   **Es el agujero típico de un módulo nuevo**, y no es hipotético: en
   `companies`, `people`, `work_plans` y `form_templates` las rutas de exportación
   solo exigen `.view`, no `.export`, así que quien puede ver puede exportar aunque
   su perfil diga lo contrario. Detalle en
   [`ACCESS-MODEL.md` §2](ACCESS-MODEL.md#aviso-export--import-no-gatean-en-los-módulos-de-docufiz).
3. **Rutas que deben ser solo-super y quedan abiertas a admin.** El caso sensible
   aquí es la **evidencia de firma**: `signature_events.review` habilita ver las
   fotos de las caras y autorizar una firma sin reconocimiento. Si una ruta nueva
   del servidor de evidencias pierde ese middleware, cualquiera con
   acceso al módulo ve la biometría de la plantilla. Toda ruta que toque
   `evidence_files` o `signature_events` pasa por ese permiso, sin excepciones.
4. **Mass assignment**: mandar campos extra en el POST (`role_id`, `is_active`,
   `tenant_id`, `plan`, `auto_sign_reports`) y que el modelo los acepte por estar en
   `$fillable`. `tenant_id` ya tiene doble capa. **La regla para campos nuevos**: si
   otorga permisos, cambia de plan, **registra un consentimiento** o **declara una
   firma como verificada**, no se asigna desde el request — lo pone el servicio.
   Los campos que dicen si una firma vale —`method`, `match_distance`,
   `threshold_used`, `pending_review` en `signature_events`, y el `is_approved` de
   la aprobación— son exactamente ese caso: los escribe `SignatureService` tras
   recalcular la distancia en el servidor, nunca el formulario. En el sistema
   anterior el navegador mandaba `is_approved=1` en un campo oculto, y bastaba
   abrir las herramientas de desarrollo para firmar como cualquiera.
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
   **Lo hace la aplicación, no el `.env`.** `AppServiceProvider::forzarHttpsFueraDeLocal()`
   pone `URL::forceScheme('https')` y `session.secure = true` en todo entorno que
   no sea `local` ni `testing`. Antes dependía de que `SESSION_SECURE_COOKIE`
   estuviera escrita en el `.env` de producción — y no lo estaba, así que
   `config('session.secure')` valía null y el navegador mandaba la cookie también
   por http. Sigue haciendo falta el TLS del servidor (Let's Encrypt, 80→443,
   HSTS): esto es la red por debajo, para cuando esa configuración falle o se
   despliegue en otro sitio.
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
    subestimada, y aquí la superficie es real: generar PDFs con dompdf y encolar
    decenas de exportaciones. Un droplet de 1-2 GB no aguanta un bucle de
    generación de documentos. Mitigado con `throttle:5,1` en cada ruta de
    exportación y con los topes de `exports.max_*_rows`.
22. **Costo/correo**: usar los envíos de la app como relé de spam o quemar la cuota
    del proveedor de SMTP.
23. **Llenar el disco con evidencias.** No hace falta atacar nada: 500 firmas
    diarias con una foto cada una llenan el disco de un droplet pequeño en un año.
    Mitigado reduciendo la captura a 320 px en WebP, reutilizando la foto entre los
    formatos del mismo plan y el mismo día, y deduplicando por `sha256`. Los
    números están en [`BIOMETRIA.md`](BIOMETRIA.md), y las pruebas que los fijan en
    `tests/Feature/FieldWork/SignatureEvidenceTest.php`. Un disco lleno tumba la
    aplicación igual que un ataque, y llega solo.

### G. Lógica de negocio

24. **Preguntas que ninguna herramienta automática hace.** Son las fallas que se
    explotan sin romper nada técnicamente. En DOCUFIZ las que importan:
    - ¿Puede alguien **firmar por otro**? La firma manual existe, exige motivo y
      solo la autoriza quien tenga `signature_events.review`. Pero un supervisor
      con ese permiso puede autorizarse a sí mismo: eso es una decisión de
      producto, no un descuido, y conviene tenerlo escrito.
    - ¿Puede **confirmarse un formato incompleto**? No: el servidor comprueba los
      campos obligatorios y el archivo en los formatos de solo subida. Esa
      comprobación no puede migrar al formulario nunca.
    - ¿Puede **cambiar lo ya firmado** publicando una versión nueva de la
      plantilla? No: cada entrega guarda la versión con la que se llenó, y el PDF
      pinta esa versión congelada.
    - ¿Se puede saltar el gateo de plan llamando al endpoint directo en vez de usar
      la interfaz? **En `edit_all`, sí**: la ruta no lleva middleware de plan, solo
      se esconde el botón. Ver [`plan-features.md`](plan-features.md).

    **El gateo se valida en el servidor o no vale.** Esconder el botón en Vue es
    cortesía, no control de acceso.

### H. Cadena de suministro y factor humano

24. **Dependencias**: `composer audit` / `npm audit`; los scripts `postinstall` de
    npm ejecutan código en tu máquina y en el build.
25. **Phishing al admin** — con `MAIL_PASSWORD` filtrada el correo sale de tu propio
    dominio y pasa SPF (§6.6).
26. **Insider / equipo**: acceso mínimo, y que la auditoría registre quién vio qué
    (el audit log de la app + `log_connections` de Postgres).

### Hallazgos abiertos

> El hallazgo de 2026-07-27 sobre `/r/{token}/pdf/{transformer}` **quedó resuelto
> por eliminación**: esa ruta y el portal público entero desaparecieron con la
> purga del dominio de diagnóstico.

1. **`sanctum.expiration => null`** — [`config/sanctum.php`](../config/sanctum.php).
   Los tokens de API **no caducan nunca**: uno filtrado en 2026 sigue sirviendo en
   2030. Acción: poner un vencimiento (ej. `60 * 24 * 30`) y programar
   `sanctum:prune-expired`. Cuidado: es un cambio con impacto en clientes que ya
   usen la API de Customer — hay que avisar, no soltarlo en un deploy.
2. **Inyección de fórmulas en los CSV exportados** — los `Generate*CsvJob` escriben
   con `fputcsv` sin neutralizar el primer carácter. Un valor que empieza con `=`,
   `+`, `-` o `@` (por ejemplo, el nombre de una empresa escrito con mala
   intención) **Excel lo interpreta como fórmula** al abrir el archivo. Severidad
   honesta: baja — el daño ocurre en la máquina de **quien abre el CSV**, no en el
   servidor. Pero es hallazgo estándar de cuestionario de seguridad y el arreglo es
   una línea: prefijar `'` cuando el valor empiece con esos caracteres.
3. **`export` / `import` sin gate en los módulos de obra** — las rutas de
   exportación de `companies`, `people`, `work_plans` y `form_templates` exigen
   `.view` en lugar de `.export`. Un perfil de solo lectura puede sacar el listado
   completo de personas, con nombres y documentos de identidad. Acción: añadir
   `permission:{modulo}.export` a esas rutas, como ya lo tienen `customers` y
   `brands`.

**Verificado correcto** (para no volver a auditarlo): descargas con disco privado,
filtro por `user_id` y vencimiento; `sort` contra lista blanca; `whereRaw` con
parámetros ligados; dompdf sin remoto; evidencias de firma servidas solo tras
`signature_events.review` y nunca desde el disco público.

---

## 7. Checklist de deploy (seguridad)

- [ ] `.env` fuera de git, `chmod 600`, dueño = usuario de la app.
- [ ] `APP_KEY` generada y respaldada (sin ella se pierden datos cifrados).
- [ ] HTTPS/TLS (Let's Encrypt) y redirección 80→443. La app fuerza `https` y la
      cookie `secure` por su cuenta fuera de `local`, pero sin certificado en el
      servidor eso no sirve de nada: hay que poner los dos.
- [ ] Plazos de conservación decididos con el cliente y escritos en el `.env`
      (`PURGE_DAYS_PERSON_RECORD`, `PURGE_DAYS_WORK_EVIDENCE`). Vienen en 0 —sin
      purgar— a propósito; ver `config/purge.php`.
- [ ] Firewall (UFW): solo 22/80/443; BD no expuesta a internet.
- [ ] Usuario de BD con privilegios mínimos (no `postgres` superuser).
- [ ] BD gestionada con encryption at rest + TLS, o disco cifrado.
- [ ] Backups automáticos de BD probados (restore real, no solo dump).
- [ ] `APP_DEBUG=false`, `APP_ENV=production`.
- [ ] Logs sin secretos; `config:cache` y `view:cache` en el deploy (`route:cache`
      **no**: los archivos de rutas tienen closures y el comando falla — ver
      [`DEPLOY.md`](DEPLOY.md)).
- [ ] El directorio de subidas (`storage/app/public`) **no ejecuta PHP** (regla de
      Nginx que deniegue `\.php$` bajo `/storage`).
- [ ] `composer audit` y `npm audit` sin vulnerabilidades altas.
- [ ] Probado como atacante: `/.env`, `/.git/config`, `/storage/logs/laravel.log`
      → 404 (§6.5), y una página de error sin stack trace.
- [ ] **Ninguna evidencia de firma es alcanzable sin sesión.** Copiar la URL de una
      foto del álbum de fotos de firmas, abrirla en una ventana privada y comprobar
      que responde 403 o redirige al login. Si se ve la imagen, hay una foto de la
      cara de un trabajador colgando de internet.
- [ ] El consentimiento biométrico está registrado para todas las personas
      enroladas.
