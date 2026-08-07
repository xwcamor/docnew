# Manual de uso del sistema

**Qué es esto**: manual operativo del sistema desde el punto de vista del usuario.

**Para qué sirve**: entender el flujo desde la primera vez (instalación → primer login → onboarding de un workspace) hasta el uso diario por cada rol. Cubre lo que hace cada módulo, qué puede hacer cada rol y en qué orden se hacen las cosas.

**Cuándo leerlo**: cuando un usuario nuevo necesita entender cómo se usa la plataforma o cuando dudas qué módulo cubre cierta funcionalidad.

> Para conceptos técnicos profundos (capas de permisos, planes, background tasks), revisa también:
> - [`PERMISSIONS.md`](PERMISSIONS.md) — roles, gates, super bypass
> - [`plan-features.md`](plan-features.md) — matriz plan × feature
> - [`CRONS-AND-SETTINGS.md`](CRONS-AND-SETTINGS.md) — schedulers, settings, background tasks

---

## 1. Flujo del sistema (de la primera vez al uso diario)

### Paso 1 — Tú (super) inicializas el sistema

```
[Instalar el código] → [setup:project siembra todo] → [Login como super]
```

Con `php artisan setup:project` se crean automáticamente:

- **Catálogos globales**: 14 idiomas, 18 locales, 50+ países, 5 regiones
- **Planes**: free / basic / pro / enterprise con sus features
- **23 settings** globales con defaults sensatos
- **Roles del sistema** (super / admin / user / api) con sus permissions Spatie
- **Workspaces demo** (Empresa 1, Empresa 2, Independiente, Estudio Pérez)
- **Suscripciones demo** (cada workspace con un plan distinto para probar)
- **9 usuarios demo** con credenciales (password: `123456`)

Pasos para setup detallados: [README-DEV.md → Setup inicial](../README-DEV.md#1-setup-inicial-pc-nueva).

### Paso 2 — Tú creas un workspace para tu cliente

Sidebar (super) → **Workspaces** → **Nuevo workspace**:

- **Nombre** del workspace (ej. "ACME Inc")
- **Logo** (opcional, hasta 2 MB)
- **Admin obligatorio**: nombre, email, password inicial. Sin admin, el workspace queda inutilizable.
- **País del admin** (auto-completa el timezone)

El sistema crea internamente:
- Un `system_user` invisible — lo usa para emitir tokens API si el cliente los necesita
- El admin que designaste (queda en estado `is_active=true`)

### Paso 3 — Tú asignas una suscripción al workspace

Workspace → Tab **Suscripciones** → **Nueva**:

- **Plan**: free / basic / pro / enterprise
- **Vigencia**: starts_at, ends_at
- **Trial** (opcional, default 14 días al crear el workspace)
- **Monto + moneda + método de pago** (manual hoy — Stripe integration es feature futura)

El plan vigente desbloquea las features del workspace según la matriz [plan × feature](plan-features.md).

### Paso 4 — El admin se loguea y configura su equipo

El admin recibe las credenciales que le pasaste (manualmente o por email):

1. Entra a `/es/login`, se autentica
2. **Mi perfil** → cambia su password inicial, agrega foto, ajusta idioma + timezone
3. **Usuarios** → crea a sus empleados (workers)
4. **Perfiles** (Roles) → crea roles custom para su equipo (ej. "Sales Editor" puede crear/editar Customers, "Sales Viewer" solo lee)
5. Vuelve a Usuarios y asigna esos roles a cada worker

### Paso 5 — Los workers usan los módulos de negocio

Los workers entran a los módulos que el admin les habilitó:

- **Customers** (y futuros módulos creados con `make:module`)
- **Mi perfil** (siempre)
- **Inbox** (siempre — para recibir mensajes del super)

Pueden hacer las acciones que su rol/permisos custom les habiliten (crear, editar, eliminar, exportar, importar, etc.). Cada acción queda registrada en el audit log.

### Paso 6 — Comunicación super → cliente

Cuando publicas algo importante (cambios de plan, mantenimiento, anuncios):

1. Sidebar → **Mensajes** → **Crear**
2. **Audiencia**: global (todos los usuarios) / workspace específico / usuario específico
3. **Editor TipTap** con formato rich (negritas, links, listas, etc.)
4. Opcional: marcar **permitir respuestas** (genera un hilo de debate)

El usuario lo recibe:
- En **Inbox** (módulo dedicado, persistente)
- Con badge en el ícono de sobre del header

---

## 2. Manual del super

> Tú. Creador y dueño de la plataforma. tenant_id = NULL. Invisible para los clientes — los admins no saben que existes.

**Login**: `/es/login` (o `/en/`) con tu email y password.

### Tus responsabilidades diarias

1. **Crear workspaces** cuando llega un cliente nuevo
   - Sidebar → Workspaces → Nuevo
   - Asignas un admin obligatorio (con sus credenciales iniciales)

2. **Crear y gestionar suscripciones**
   - Workspace → Tab Suscripciones → Nueva / Renew / Cancel / Suspend
   - El plan vigente desbloquea features del cliente

3. **Comunicar a tus clientes**
   - Sidebar → Mensajes → Crear
   - Audiencias: global, workspace específico, usuario específico

4. **Mantener catálogos globales**
   - Sidebar → System Management → Países / Idiomas / Locales / Regiones / Módulos del sistema
   - Solo cuando aparezca un caso nuevo (ej. un país que falta)

5. **Ajustar comportamiento global del sistema**
   - Sidebar → System Management → **Configuración** (Settings)
   - 23 settings editables sin redeploy: nombre de la app, email de soporte, threshold de bulk, frecuencia de polling, retención de audit, etc.

### Lo que NO haces normalmente

- ❌ Crear usuarios del cliente — eso lo hace el admin del workspace
- ❌ Crear roles custom de un cliente — eso lo hace el admin
- ❌ Administrar los registros de negocio (Customers, etc.) del cliente — salvo emergencia
- ❌ Cambiar el plan a través del form del workspace — solo a través del tab Suscripciones

### Capacidades especiales del super

- **Bypaseas todos los gates** de permiso, plan y multi-tenant. Ves cross-tenant.
- **Acceso a la papelera** de cada módulo (registros soft-deleted)
- **Force-delete** habilitado (borrado definitivo, triple guard: nombre + razón + confirmación)
- **Ves los audit logs completos** de todos los workspaces
- **Eres invisible** para los admins — no apareces en los listados de usuarios del workspace

---

## 3. Manual del admin (dueño de un workspace)

> Responsable de su empresa dentro de la plataforma. 1 admin por workspace, obligatorio. Tenant_id apunta a su workspace.

**Login**: con las credenciales que el super le proporcionó. Primera acción: ir a **Mi perfil** y cambiar el password inicial.

### Sus responsabilidades

1. **Crear los usuarios de su empresa**
   - Sidebar → Usuarios → Nuevo
   - Le pone nombre, email, password inicial, locale, country

2. **Crear roles custom** (solo en plan pro+)
   - Sidebar → Perfiles
   - Ej. "Sales Editor" (puede crear/editar Customers), "Sales Viewer" (solo lee)
   - Cada rol tiene un set de permissions Spatie que el admin elige

3. **Asignar roles a usuarios**
   - Sidebar → Usuarios → click en un usuario → Edit → cambiar el campo "Perfil"

4. **Gestionar los módulos de negocio**
   - Customers (y los módulos generados con `make:module` después)
   - El admin puede hacer todo en estos módulos; los workers según lo que les asigne

### Lo que NO puede hacer

- ❌ Ver otros workspaces — está scoped por `tenant_id` automáticamente
- ❌ Editar planes ni suscripciones — lo gestiona el super
- ❌ Acceder a la papelera — es super-only por seguridad
- ❌ Force-delete — es super-only
- ❌ Ver módulos system-level (Regions, Languages, Countries, Locales, etc.) — son catálogos del super

### Las features que tiene dependen del plan de su workspace

| Plan | Lo que el admin puede usar |
|---|---|
| **free** | Solo él mismo. Sin Users (ya que max_users=1), sin Roles, sin Automations, sin Imports, sin Exports avanzados |
| **basic** | + Hasta 5 usuarios + Saved Views + Exports CSV/Excel + Audit log view |
| **pro** | + Roles custom + Automations + Imports + Exports PDF/Word + Bulk operations |
| **enterprise** | + API REST + Branded exports + sin límites de usuarios ni registros |

El plan actual lo ve en el dropdown del avatar (top-right del header) → línea "Plan".

---

## 4. Manual del user (worker)

> Empleado de un workspace con permisos específicos asignados por el admin.

**Login**: con las credenciales que su admin le proporcionó.

### Sus responsabilidades

- **Acceder a los módulos** que el admin le habilitó vía rol custom o permisos directos
- **Mantener su propio perfil** (Mi perfil): foto, password, idioma, timezone

### Lo que ve

- **Solo los módulos** donde su rol tiene `*.view` permission
- **Dentro de cada módulo, solo los registros de su workspace** (multi-tenant scope automático)
- **Las acciones** (crear / editar / eliminar / exportar) están condicionadas a permisos específicos:
  - `customers.create` → puede crear nuevos customers
  - `customers.edit` → puede editarlos
  - `customers.delete` → puede eliminar (soft delete)
  - `customers.view` → puede ver el listado y el detalle

### Lo que NO puede

- ❌ Crear usuarios — solo el admin del workspace
- ❌ Ver workspaces ajenos
- ❌ Crear roles — solo el admin con plan pro+
- ❌ Acceder a system management — es del super
- ❌ Ver la papelera ni hacer force-delete — solo el super
- ❌ Ver el audit log (a menos que el plan + permiso `audit_log_view` lo habiliten)

---

## 5. Módulos del sistema en detalle

### System Management (solo super)

Catálogo global que tú mantienes para todos los clientes.

| Módulo | Para qué | URL |
|---|---|---|
| **Workspaces** (Tenants) | Crear y administrar las empresas-cliente | `/system_management/tenants` |
| **Planes** | Definir tiers de suscripción (free/basic/pro/enterprise): límites de usuarios, features, precio | `/system_management/plans` |
| **Idiomas** | Catálogo de idiomas soportados (es/en + 12 más declarados) | `/system_management/languages` |
| **Locales** | Combinaciones idioma + país (es_ES, en_US, pt_BR, etc.) | `/system_management/locales` |
| **Países** | Catálogo de países con timezone, ISO code, region | `/system_management/countries` |
| **Regiones** | Continentes / regiones geográficas (asociadas a países) | `/system_management/regions` |
| **Módulos del sistema** | Lista de módulos registrados con sus permisos. Se actualiza automáticamente cuando creas un módulo nuevo con `make:module` | `/system_management/system_modules` |
| **Configuración** (Settings) | 23 settings globales editables sin redeploy | `/system_management/settings` |

### User Management (super + admin)

| Módulo | Quién lo usa | URL |
|---|---|---|
| **Usuarios** | Super ve todos cross-tenant. Admin ve solo los de su workspace | `/user_management/users` |
| **Perfiles** (Roles) | Roles custom por workspace + roles del sistema. Admin gestiona los de su workspace | `/user_management/roles` |

### Business Management (super + admin + workers con permisos)

| Módulo | Estado | URL |
|---|---|---|
| **Customers** | Listo. Es el master template del scaffold `make:module` | `/business_management/customers` |
| Products / Sales / Inventory / Categories / etc. | Por construir con `php artisan make:module {Name}` | — |

### Communication

| Módulo | Quién lo usa | URL |
|---|---|---|
| **Inbox** | Todos los usuarios. Recibe mensajes que el super publica (globales, por workspace, o personales) | `/communication/inbox` |
| **Mensajes** | Solo super. Crear y publicar comunicados con editor TipTap | `/communication/messages` |

### Automation Management (super + admin con plan pro+)

| Módulo | Para qué | URL |
|---|---|---|
| **Automatizaciones** | Reglas tipo "todos los lunes a las 9:00 ejecuta X". Trigger: schedule. Acciones: email, in-app notification. Solo en planes con feature `automations` activa | `/automation_management/automations` |

### System Logs

| Módulo | Quién lo ve | URL |
|---|---|---|
| **Audit Logs** | Super (todo) + admin (su workspace). Gated por feature `audit_log_view` | `/system_management/audit_logs` |

---

## 6. Atajos útiles

### Atajos de teclado globales

| Atajo | Acción |
|---|---|
| `Ctrl + N` | Nuevo registro (en listados) |
| `Ctrl + F` | Foco en la barra de filtros (en listados) |
| `Esc` | Cerrar modal / drawer / cancelar acción |

### Dropdown del avatar (top-right)

| Item | Para qué |
|---|---|
| Timezone | Ver el TZ efectivo del usuario actual |
| Plan | Ver el plan del workspace + días restantes |
| Mi perfil | Editar foto, password, idioma, timezone propio |
| Recent items | Últimos 10 registros vistos (cualquier módulo) |
| Logout | Cerrar sesión |

### Header (top-bar)

| Ícono | Para qué |
|---|---|
| Hamburger | Toggle del sidebar |
| Iniciales del workspace | Indicador del workspace actual (admin) |
| 🔔 Bell | Notificaciones del sistema (descargas listas, automatizaciones disparadas) |
| ✉️ Sobre | Mensajes recibidos (módulo Inbox) |
| 🖥️ Monitor | Modo claro / oscuro / auto |
| 🌐 Globo | Switcher de idioma (es/en) |
| Avatar | Dropdown con perfil + plan + recent items + logout |

---

## 7. Multi-idioma — cómo funciona

2 idiomas soportados hoy: **español** (`es`) e **inglés** (`en`).

- Las URLs siempre incluyen el locale: `/es/users`, `/en/users`
- El usuario elige su idioma:
  - En el switcher del header (icono 🌐)
  - O en su Profile (preferencia persistente)
- Las fechas se muestran en el formato `dd-mm-yyyy HH:mm` para ambos (estándar del proyecto)
- Los timestamps en BD están en UTC; el frontend los convierte al timezone del usuario

Para agregar un idioma nuevo:
1. Agregar `'fr' => [...]` en [`config/laravellocalization.php`](../config/laravellocalization.php)
2. Crear los 27 archivos en `resources/lang/fr/` traduciendo de `es/`
3. Agregar `'fr'` como `iso_code` de un `Language` activo en BD

---

## 8. Multi-país — cómo funciona

- Catálogo de países en la tabla `countries` (seedeada con 50+ países)
- Cada país tiene: nombre, ISO code, region_id (continente), default_locale_id, **timezone** (ej. "America/Lima")
- Cada **usuario** tiene `country_id` obligatorio
- Cada **workspace** tiene `timezone` propio (override del país del creador)

### Resolución del timezone efectivo de cada usuario

```
1. user.timezone (si está seteado en Profile)
2. user.tenant.timezone (si el workspace tiene uno propio)
3. user.country.timezone (heredado de su país)
4. config('app.timezone') = 'UTC' (fallback)
```

El TZ efectivo se calcula en [`App\Support\Tz::for($user)`](../app/Support/Tz.php) y se pasa al frontend como shared prop. Las fechas en la UI se renderizan en ese huso horario, sin importar dónde corra el server.

---

## 9. API REST (uso del cliente con plan enterprise)

Vive en `/api/v1/*` con Sanctum bearer tokens. Cada workspace puede emitir múltiples tokens con abilities específicas.

**Plan gating**: las rutas API requieren `plan_feature:api_access`, que solo `enterprise` tiene.

### Generar un token (como super)

1. Login como super
2. Sidebar → Workspaces → click en un workspace
3. Tab **API Keys** → **Generar nueva API key**
4. Marcar las abilities (ej. `customers:read`, `customers:write`, `customers:delete`)
5. Copiar el token — se muestra UNA vez

### Probar el token

```bash
curl -H "Authorization: Bearer <token>" \
     https://midominio.com/api/v1/customers
```

### Documentación interactiva

`https://midominio.com/docs` (Scribe) — accesible solo a super/admin logueados.

Hoy hay un endpoint expuesto vía API como patrón de referencia. Los módulos core (Tenants, SystemModules, Settings, Languages, Countries, Locales) NO se exponen vía API — son super only desde la UI.

---

## 10. Preguntas frecuentes que recibirás del cliente

| Pregunta del cliente | Tu respuesta |
|---|---|
| "Olvidé mi password" | Usar el link "Forgot password" del login. Se le envía un email con reset link (válido 60 min) |
| "No puedo ver el módulo X" | Verificar: (1) que su rol tenga el permiso `X.view`, (2) que el plan de su workspace incluya la feature relacionada |
| "El export tarda" | Es procesado en background. Cuando esté listo aparece notificación en la campana del header + email |
| "Borré algo por error" | Tiene 60s para usar el botón **Deshacer** (toast). Pasados los 60s, solo el super lo puede recuperar desde la papelera |
| "Quiero subir mi plan" | Tienes que comprarlo. Contactar al super (email del Setting `app.support_email`) |
| "Mi suscripción venció" | Su tenant cayó automáticamente al plan `free`. Puede seguir usando lo que ese plan permite. Para volver al plan pago, renovar suscripción |
| "Los emails no llegan" | Verificar spam. Si sigue sin llegar, super puede ver el setting `notifications.email_enabled` y los logs del sistema |

---

## 11. Documentación relacionada

- [`../README.md`](../README.md) — portada general del sistema
- [`MANUAL-CLIENTE.md`](MANUAL-CLIENTE.md) — versión sin jerga técnica para entregar al cliente final
- [`PERMISSIONS.md`](PERMISSIONS.md) — detalle técnico de roles + Spatie + super bypass
- [`plan-features.md`](plan-features.md) — matriz completa plan × feature
- [`CRONS-AND-SETTINGS.md`](CRONS-AND-SETTINGS.md) — schedulers, los 23 settings y background tasks
- [`AUTOMATIONS.md`](AUTOMATIONS.md) — manual de las automatizaciones programadas
- [`CREATE-MODULE.md`](CREATE-MODULE.md) — cómo crear módulos de negocio nuevos
- [`MAIL-SETUP.md`](MAIL-SETUP.md) — configurar SMTP para envío de emails
