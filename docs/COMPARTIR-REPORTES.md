# Diseño: Compartir reportes con clientes (link público + OTP)

> **Estado**: IMPLEMENTADO. El documento arranca como la propuesta original
> (secciones de diseño de abajo) y las secciones fechadas del final registran
> cómo quedó. Ante una diferencia, mandan las fechadas.

## Objetivo

Permitir que el usuario comparta un diagnóstico con un cliente externo (que **no
tiene cuenta** en el sistema), mediante un **enlace público con expiración**, donde
para **abrir** el reporte el cliente ingresa un **código (OTP) que le llega a su
correo**. Una vez adentro, la **descarga del PDF es libre** (sin más claves).

Dos escenarios:
1. **Un transformador**: el diagnóstico de un solo trafo.
2. **Flota**: todos los trafos de un cliente.

## Decisiones de diseño (el "por qué")

- **El link solo NO alcanza para entrar.** Se exige un OTP enviado al correo del
  destinatario. Esto prueba que quien abre controla ese inbox, y evita el error
  clásico de mandar link + clave en el mismo mail (que no protege nada).
- **PDF libre una vez dentro.** La identidad ya se validó con el OTP; pedir clave
  de nuevo para descargar sería fricción inútil.
- **Portal web de solo lectura, no PDF gigante por mail.** Para flota, un PDF
  único sería enorme (rebota en mails, DomPDF lento). El link abre una página que
  lista la flota; cada trafo genera su PDF on-demand.
- **PDF on-demand, sin guardar.** No usamos S3 ni storage persistente para esto
  (coherente con las decisiones del proyecto). Se genera al vuelo, como hoy.
- **Sin Redis.** El OTP y los rate-limits usan la cache `database` que ya está.

## Modelo de datos

### Tabla `report_shares`
| Campo | Tipo | Nota |
|---|---|---|
| id | bigint | |
| token | string(64) unique | aleatorio, va en la URL (`/r/{token}`) |
| scope_type | enum: `transformer` \| `fleet` | qué se comparte |
| transformer_id | fk nullable | si scope = transformer |
| customer_id | fk nullable | si scope = fleet (todos sus trafos) |
| tenant_id | fk | dueño del share (a qué workspace pertenece) |
| recipient_email | string | a quién se le mandó |
| expires_at | datetime | vencimiento del enlace |
| revoked_at | datetime nullable | revocación manual |
| last_opened_at | datetime nullable | tracking |
| open_count | int default 0 | tracking |
| created_by | fk users | quién compartió |
| timestamps | | |

### OTP (sin tabla nueva)
Se guarda en la **cache `database`**, keyed por el share:
`share_otp:{share_id}` → `{ code_hash, expires_at (~10 min), attempts }`.
Borra al validar. Límite de intentos (ej. 5) → invalida y obliga a pedir otro.

### Sesión de invitado
Tras validar OTP, se setea una **cookie de sesión firmada** del estilo
`share_access:{share_id}` con validez corta (ej. 2-4 h). No es login real; solo
habilita ese share. Se guarda en `session` (driver actual).

## Rutas (públicas, fuera del `auth`)

```
GET   /r/{token}                 → gate: valida token/expiración; si no hay sesión, pide OTP
POST  /r/{token}/send-code       → genera y manda el OTP al recipient_email (rate-limited)
POST  /r/{token}/verify          → valida el código; abre sesión del share
GET   /r/{token}/view            → portal de lectura (1 trafo o lista de flota)   [requiere sesión share]
GET   /r/{token}/pdf/{trafo}     → descarga PDF de un trafo del alcance            [requiere sesión share]
```

Rutas internas (con `auth`, para el que comparte):
```
POST   /business_management/.../share        → crea el share + manda Mail 1
GET    /business_management/.../shares        → listar/gestionar shares (Fase 2)
DELETE /business_management/.../shares/{id}   → revocar (Fase 2)
```

## Flujo completo

**El que comparte (logueado):**
1. Botón "Compartir" en el trafo, o "Compartir flota" en el cliente.
2. Modal: `recipient_email` + `expiración` (7/30 días).
3. Submit → crea `report_shares` + envía **Mail 1**: "Te compartieron un reporte: [link]".

**El destinatario (sin cuenta):**
4. Abre `/r/{token}`. Se valida no-vencido / no-revocado.
5. Pantalla: "Te enviaremos un código a tu correo" → botón "Enviar código".
6. `POST /send-code` → OTP de 6 dígitos, hasheado en cache (10 min) → **Mail 2** con el código.
7. Ingresa el código → `POST /verify` → si OK, sesión del share (2-4 h).
8. `/view`:
   - **transformer**: diagnóstico + "Descargar PDF".
   - **fleet**: lista de trafos del cliente (estado / Índice de Salud) + PDF por trafo.
9. Descargas de PDF libres mientras dure la sesión del share.

**Tracking/control:** `last_opened_at`, `open_count`; revocar con `revoked_at`.

## Seguridad

- **Token**: 64 chars aleatorios (`Str::random`), no adivinable; único.
- **OTP**: 6 dígitos, hasheado, expiración 10 min, máx. 5 intentos, rate-limit en `/send-code` (ej. 1/min, 5/hora) para no spamear el correo.
- **Expiración del share** + **revocación** manual.
- **Multi-tenant**: el portal es público, pero los datos se leen **scoped al share**
  (solo el trafo o los trafos del cliente del share). Se hace bypass del
  `BelongsToTenant` de forma controlada y **solo lectura** — nunca se exponen otros
  tenants. Cuidado de no filtrar datos vía IDs en las rutas (`/pdf/{trafo}` debe
  validar que el trafo pertenece al alcance del share).
- **Rate-limit** en las rutas públicas (throttle) contra fuerza bruta del OTP.

## Dependencias / requisitos

- **Correo funcionando (SMTP)**. El OTP no sirve sin mail. En **dev** se puede
  dejar `MAIL_MAILER=log` (el código sale al `laravel.log`) para probar. En
  **producción** (el droplet) hay que configurar un SMTP real o un servicio
  (Resend / SES / Mailgun). **Esto es un requisito, no opcional.**
- **Queue** (`database`, ya está) para mandar los mails sin bloquear el request.

## UI

- **Modal "Compartir"** (Ant Design): email + expiración. Reusa el patrón de otros modales.
- **Portal de lectura** (`/r/{token}/view`): página pública liviana (puede ser
  Blade simple o una página Inertia sin layout de app), con branding mínimo.
  Para flota: tabla de trafos con su semáforo + botón PDF por fila.
- El PDF reusa lo ya hecho (con los gráficos como imágenes capturadas).

### Compartir una selección desde el índice (2026-07-26)

Elegir los trafos dentro del modal no escalaba: era un `Select` múltiple con
solo el número de serie, sin filtros ni contexto. En vez de construir filtros
nuevos dentro del modal, se reusa el ÍNDICE, que ya tiene búsqueda, filtros
avanzados (incluida **subestación**), vistas guardadas y selección múltiple:

1. En `/business_management/transformers` se filtra y se marcan los trafos.
2. La barra de selección múltiple trae **Compartir** (gateada por el plan,
   `report_sharing`).
3. Se abre el modal de flota con esos trafos ya elegidos.

**Restricción deliberada**: la selección debe ser de UN cliente. El portal
muestra la flota de un cliente, así que mezclar clientes en un enlace filtraría
trafos ajenos al destinatario. Si la selección abarca varios, se avisa y no se
comparte (`sharing.bulk_mixed_customers`).

La lista de enlaces activos muestra ahora **cuándo se envió** y **qué se
envió**: la lista de trafos incluidos (los primeros 6, con "+N más"), o "toda
la flota del cliente" si no se acotó (`sent_labels` en el `present()` del
controlador).

### Entrega: por correo o solo el enlace (2026-07-26)

El destinatario dejó de ser obligatorio. Dos modos en el modal:

- **Enviar por correo** (el de siempre): se manda la invitación y el portal pide
  un código de un solo uso al correo del destinatario.
- **Solo generar el enlace**: no hay destinatario, así que **no hay OTP** — el
  token ES la credencial. Se copia al portapapeles y el usuario lo reparte por
  su cuenta (correo propio, WhatsApp, lo que sea). Sigue venciendo igual.

`ReportShare::isLinkOnly()` (destinatario vacío) es la bandera. El gate
(`PublicShareController::gate`) abre la sesión directo; `sendCode` responde 404
y el reenvío da 422 (no hay a quién reenviarle). El aviso en el modal dice
explícitamente que cualquiera con el enlace lo ve: es una decisión del usuario,
no un default silencioso.

**Excepción**: con `require_report_approval` el enlace lo manda el sistema al
destinatario cuando se aprueba (`autoShare`), así que ahí el correo SÍ es
obligatorio — sin él la solicitud se aprobaría sin generar nada. Se corta en
`store()` con un 422 explicativo.

### Revocar no borra

`revoke()` marca `revoked_at`; la fila queda con su historial (aperturas, qué
trafos incluía, cuándo se envió). Desde 2026-07-26 además se audita
(`report_share_revoked`, `ReportShare::auditRevoked()`), igual que la emisión.
La lista del modal solo muestra los NO revocados.

### Volumen: el modal con muchos enlaces

El formulario de "nuevo enlace" va **arriba** y el historial debajo: con 30
enlaces, lo que uno viene a hacer no puede quedar sepultado bajo la lista.
La lista muestra 4 y despliega el resto, tiene contador y buscador por
destinatario, y el `index()` corta en 25.

El listado resuelve las etiquetas de TODOS los enlaces en una consulta
(`labelsFor()`) y manda hasta `SENT_LABELS_MAX` (20) por enlace; el total real
viaja en `partial_count`, que es lo que cuentan los "+N más" del front. Antes
era una consulta por enlace y se mandaban las 400 etiquetas de una flota grande
para mostrar 6.

### "Envíos de informes" — historial cruzando clientes (2026-07-26)

`ReportShareLogController` + `Pages/ReportShares/Index.vue`, en el menú bajo
Gestión de negocio (misma feature de plan `report_sharing`). Es la vista que el
modal no podía dar: TODOS los enlaces del workspace, de todos los clientes, con
búsqueda (destinatario / nota / cliente / serie), filtros de estado
(activo/vencido/revocado), cliente, workspace (super) y rango de fechas.
Columnas: destinatario o "Enlace sin destinatario" + nota, cliente, qué se
envió, cuándo, estado, aperturas y quién lo envió. Se puede copiar el enlace y
revocar desde ahí.

Alcance: `ReportShare` NO usa `BelongsToTenant` (el portal es público), así que
el filtrado se hace a mano — `tenant_id` del usuario (el super ve todo y puede
filtrar por workspace) y, para clientes asignados, subconsultas sobre
`Customer`/`Transformer` que arrastran sus scopes globales.

### Otras mejoras de la misma tanda

- **Revocados visibles**: enlace "Ver revocados" en el modal (`with_revoked`).
  Salen atenuados, con la fecha de revocación y sin acciones.
- **Corte explícito**: el modal lista hasta `LIST_LIMIT` (50) y, si hay más, lo
  DICE y deriva a "Envíos de informes". Antes cortaba en 25 en silencio, que se
  lee como "esto es todo lo que hay".
- **Nota por enlace** (`report_shares.note`, 250 chars): para el registro propio,
  el cliente no la ve. Nació para los enlaces sin destinatario, donde el sistema
  no sabe a quién le llegó.
- **Vencimiento de 24 h**: pensado para el enlace sin destinatario, que es el
  más expuesto.
- **El `Select` de trafos se carga al abrirlo** (`with_transformers`), no
  siempre. OJO: el TOTAL de la flota (`fleet_count`) viaja SIEMPRE porque el
  front lo necesita para saber si la selección abarca todo — sin él creía que sí
  y guardaba `transformer_ids = null`, o sea compartía la flota entera en vez de
  los 2 elegidos. Las etiquetas de lo preseleccionado se pasan desde el índice
  (`preselectedOptions`) para no cargar 400 opciones y mostrar 2 nombres.

## Fases

1. **Fase 1 (núcleo)**: crear share (trafo + flota), Mail 1, gate OTP (send-code +
   verify), sesión de share, portal de lectura, descarga PDF. Mail en `log` en dev.
2. **Fase 2**: gestión de shares (listar, revocar, ver aperturas) desde la UI logueada.
3. **Fase 3**: pulido — reenviar código, expiración configurable, branding del mail,
   límite de descargas, "compartir flota filtrada" (solo algunos trafos).

## Preguntas abiertas (para definir antes de Fase 1)

1. **Expiración por defecto**: ¿7 días? ¿configurable por el usuario en el modal?
2. **Duración de la sesión del share** tras OTP: ¿2 h? ¿24 h?
3. **¿Gating por plan?** ¿Es feature premium (como webhooks)? ¿Qué planes lo tienen?
4. **Flota = todos los trafos del cliente**, ¿o el usuario elige cuáles? (Fase 1
   propone "todos"; selección parcial sería Fase 3.)
5. **Idioma del portal/mails**: ¿según el locale del usuario que comparte, o
   elegible al compartir? (los clientes externos podrían ser de otro idioma.)
6. **Branding**: ¿logo del workspace en el portal y los mails? (tenemos logos en storage.)
