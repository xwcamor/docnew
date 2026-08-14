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

Con `php artisan setup:project` se crean:

- **Catálogos globales**: idiomas, locales, países y regiones
- **Planes**: free / basic / pro / enterprise con sus features
- **Ajustes globales** con valores por defecto sensatos
- **Roles del sistema** (super / admin / api) y los **cuatro perfiles globales** de obra: Supervisor de obra, Usuario de campo, Auditor HSE y Soporte
- **Módulos del sistema** con sus 7 permisos cada uno
- **Workspaces, suscripciones y usuarios de demostración**

> El comando **se niega a correr con `APP_ENV=production`**. Es una protección
> de día uno, no un parche puesto después de un accidente.

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

El admin recibe las credenciales que le pasaste:

1. Entra a `/es/login`, se autentica
2. **Mi perfil** → cambia su contraseña inicial, agrega foto y firma manuscrita, ajusta idioma y huso horario
3. **Usuarios** → crea las cuentas de quienes van a usar el sistema
4. **Perfiles** → normalmente **no hace falta crear ninguno**: los cuatro globales cubren los papeles reales de obra. Solo si necesita algo que no encaja
5. Vuelve a Usuarios y asigna el perfil a cada uno

> Ojo con la diferencia entre **usuario** y **persona**. Un usuario es una cuenta
> que entra al sistema; una persona es un trabajador que aparece en el plan y
> firma con la cara. La cuadrilla entera son personas, y casi ninguna tiene
> usuario. Se dan de alta en **Maestros → Personas**, no en Usuarios.

### Paso 5 — El equipo empieza a documentar

Con el workspace listo, el recorrido de un día de obra —dar de alta las empresas y
personas, enrolar caras, definir los formatos, armar el plan, llenarlo, firmarlo y
revisar lo que quedó pendiente— está contado paso a paso en
[`FLUJO.md`](FLUJO.md).

Cada acción queda registrada en el registro de auditoría.

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
   - Ajustes editables sin redeploy: nombre de la app, email de soporte, threshold de bulk, frecuencia de polling, retención de audit, etc.

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

2. **Asignar perfiles a sus usuarios**
   - Sidebar → Usuarios → abrir un usuario → Editar → campo "Perfil"
   - Los cuatro perfiles globales (Supervisor de obra, Usuario de campo, Auditor HSE, Soporte) están publicados y listos; el admin los asigna pero no los edita

3. **Crear perfiles propios** — solo si hace falta, y solo en plan pro+
   - Sidebar → Perfiles
   - Cada perfil es un conjunto de permisos que el admin elige

4. **Gestionar los maestros de obra**
   - Empresas, Personas, Planes de trabajo, Plantillas de formato
   - El admin puede hacer todo en estos módulos; el resto, según su perfil

### Lo que NO puede hacer

- ❌ Ver otros workspaces — está scoped por `tenant_id` automáticamente
- ❌ Editar planes ni suscripciones — lo gestiona el super
- ❌ Acceder a la papelera — es super-only por seguridad
- ❌ Force-delete — es super-only
- ❌ Ver módulos system-level (Regions, Languages, Countries, Locales, etc.) — son catálogos del super

### Las features que tiene dependen del plan de su workspace

| Plan | Lo que el admin puede usar |
|---|---|
| **free** | Solo él mismo (`max_users` = 1). Sin Usuarios, sin Perfiles, sin automatizaciones, sin importar, sin exportar más allá de CSV. Tope de 50 registros por módulo |
| **basic** | + Vistas guardadas + exportar a Excel/PDF/Word. **Sigue siendo un solo usuario** |
| **pro** | + Usuarios y Perfiles + automatizaciones + importar + masivas. 10 usuarios, 50.000 registros |
| **enterprise** | + API REST + sin límite de usuarios ni de registros |

**Para una obra real hace falta `pro` como mínimo.** Un plan de trabajo lo arma un
supervisor y lo firma una cuadrilla: sin `team_management` no hay a quién dar de
alta.

El plan vigente se ve en el menú del avatar, arriba a la derecha, en la línea "Plan".

---

## 4. Manual del usuario con perfil

> Quien trabaja en el sistema con los permisos que le dio su admin. En obra, casi
> siempre es un **Supervisor de obra** o un **Usuario de campo**.

**Login**: con las credenciales que le dio su admin.

### El supervisor de obra

Arma el plan del día, registra a su cuadrilla, llena y firma los formatos, y
puede autorizar una firma manual. No elimina registros ni toca las
plantillas de formato.

### El usuario de campo

Llena y firma los formatos del plan al que está asignado. No crea planes ni da de
alta personas. No ve las evidencias de las firmas.

### Lo que ve cualquiera de los dos

- **Solo los módulos** donde su perfil tiene `.view`
- **Dentro de cada módulo, solo los registros de su workspace** — el filtrado por tenant es automático, no depende de que nadie se acuerde
- **Las acciones** dependen del permiso concreto:
  - `work_plans.create` → armar el plan del día
  - `form_submissions.edit` → llenar los formatos
  - `form_submissions.sign` → firmar con la cámara
  - `signature_events.review` → autorizar una firma manual y ver las fotos de evidencia

### Lo que NO puede

- ❌ Crear usuarios — solo el admin del workspace
- ❌ Ver workspaces ajenos
- ❌ Crear roles — solo el admin con plan pro+
- ❌ Acceder a system management — es del super
- ❌ Ver la papelera ni borrar definitivamente — solo el super
- ❌ Ver el registro de auditoría — está gateado por **rol** (`super` o `admin`), no por permiso ni por plan. Un perfil custom no entra nunca

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
| **Configuración** (Settings) | Ajustes globales editables sin redeploy | `/system_management/settings` |

### User Management (super + admin)

| Módulo | Quién lo usa | URL |
|---|---|---|
| **Usuarios** | Super ve todos cross-tenant. Admin ve solo los de su workspace | `/user_management/users` |
| **Perfiles** (Roles) | Roles custom por workspace + roles del sistema. Admin gestiona los de su workspace | `/user_management/roles` |

### Business Management — los maestros de DOCUFIZ

Lo que se prepara en oficina, antes de que nadie salga a obra.

| Módulo | Para qué | URL |
|---|---|---|
| **Empresas** | Las contratistas que ejecutan el trabajo, con su RUC | `/business_management/companies` |
| **Personas** | Trabajadores, supervisores y aprobadores. El alta **busca primero por documento**: si la persona ya existe en otra empresa, se reutiliza su identidad, su biometría y su firma en vez de duplicarla | `/business_management/people` |
| **Planes de trabajo** | La tarea del día: empresa, tipo de trabajo, ubicación, cuadrilla y aprobadores | `/business_management/work_plans` |
| **Plantillas de formato** | AST, PTF, EPP, IHM y las HOJA X del cliente. Un formato nuevo es configuración, no programación | `/business_management/form_templates` |
| **Clientes** | Heredado de la base SaaS. Sigue ahí porque está entretejido con usuarios, permisos y automatizaciones | `/business_management/customers` |

Catálogos de obra pendientes de tener módulo propio (sedes, puestos, áreas,
cargos, nacionalidades, tipos de trabajo): se crean con
`php artisan make:module`. Ver [`CREATE-MODULE.md`](CREATE-MODULE.md).

### Trabajo en obra

Lo que se usa desde la tablet, en el sitio. No son módulos CRUD: son pantallas de
trabajo colgadas de un plan.

| Pantalla | Para qué | URL |
|---|---|---|
| **Formatos del plan** | La lista de formatos con su estado: pendiente, borrador o confirmado | `/field_work/work_plans/{plan}/forms` |
| **Firma** | Cada trabajador y cada aprobador firma con la cámara | `/field_work/work_plans/{plan}/sign` |
| **Fotos de firmas** (solo super) | El álbum: plan, trabajador y la foto tomada al firmar, con la marca «Sin reconocimiento» cuando la cara no se verificó. Desde ahí el super puede anular una firma falsa | `/field_work/signature_photos` |

Las evidencias exigen `signature_events.review`. Las fotos **no son
públicas**: se sirven autenticadas y solo a quien tiene ese permiso.

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
2. Crear en `resources/lang/fr/` un archivo por cada uno de los de `es/` (hoy son unos 40)
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

El **único** módulo expuesto hoy es `customers`, como patrón de referencia. Ningún módulo del dominio de obra tiene API: ni planes, ni personas, ni formatos, ni firmas. Los módulos core (Tenants, SystemModules, Settings, Languages, Countries, Locales) tampoco se exponen, por decisión de diseño.

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
| "Los emails no llegan" | Revisar el correo no deseado. Si sigue sin llegar, el super comprueba el ajuste `notifications.email_enabled` y los logs |
| "La cámara no abre en la tablet" | Casi siempre es que entró por `http://` con la IP de la red local. `getUserMedia` solo existe en HTTPS o en `localhost`. Ver [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) |
| "El sistema no me reconoce la cara" | No bloquea nada: pasados los segundos de espera toma la foto igual y deja firmar. La firma vale con su foto como constancia y queda marcada «Sin reconocer» en el plan. Ver [`BIOMETRIA.md`](BIOMETRIA.md) |
| "¿Puedo firmar por otro?" | No. La firma manual existe, pero exige motivo, deja constancia de quién la autorizó y solo la autoriza alguien con `signature_events.review` |
| "Cambié el formato AST, ¿se me estropean los antiguos?" | No. Cada entrega guarda la **versión de la plantilla** con la que se llenó. Publicar una versión nueva no altera lo ya firmado |

---

## 11. Documentación relacionada

- [`FLUJO.md`](FLUJO.md) — el día de obra de punta a punta, paso a paso
- [`DOMINIO.md`](DOMINIO.md) — el modelo de datos en una página
- [`BIOMETRIA.md`](BIOMETRIA.md) — cómo funciona la firma con la cara
- [`../README.md`](../README.md) — portada general del sistema
- [`MANUAL-CLIENTE.md`](MANUAL-CLIENTE.md) — versión sin jerga técnica para entregar al cliente final
- [`PERMISSIONS.md`](PERMISSIONS.md) — detalle técnico de roles + Spatie + super bypass
- [`plan-features.md`](plan-features.md) — matriz completa plan × feature
- [`CRONS-AND-SETTINGS.md`](CRONS-AND-SETTINGS.md) — schedulers, los ajustes y tareas de fondo
- [`AUTOMATIONS.md`](AUTOMATIONS.md) — manual de las automatizaciones programadas
- [`CREATE-MODULE.md`](CREATE-MODULE.md) — cómo crear módulos de negocio nuevos
- [`MAIL-SETUP.md`](MAIL-SETUP.md) — configurar SMTP para envío de emails
