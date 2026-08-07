# Modelo de accesos — permisos, roles, planes y restricciones

Referencia única de la lógica de permisos. Explica **qué controla cada permiso**,
las **3 capas de gateo** (permiso / rol / plan), los **perfiles** sembrados y las
**restricciones especiales** (registros globales, clientes asignados, aislamiento
por tenant, bloqueo de registros).

> Regla mental: para que alguien pueda hacer algo, **las 3 capas deben dar OK**.
> Permiso del perfil **Y** rol (si la sección es por rol) **Y** plan (si la
> feature es de pago). Falla una → bloqueado.

---

## 1. Las 3 capas de gateo

| Capa | Qué decide | Ejemplo |
|---|---|---|
| **Permiso** (Spatie, `{modulo}.{accion}`) | Si el perfil tiene la acción en ese módulo. | `work_plans.create` para armar el plan del día. |
| **Rol** (super / admin) | Lo que NO se delega a un perfil custom (core, papelera, auditoría, automatizaciones). | Solo super ve Workspaces. |
| **Plan** (`plan_feature:*`) | Si el workspace pagó esa feature. | `imports` para poder importar. |

Ejemplo combinado — importar personas exige `people.create` **+** plan con
`bulk_operations`. Si el perfil tiene el permiso pero el plan no trae la feature,
la ruta responde igual con un bloqueo.

---

## 2. Las 7 acciones por módulo

Cada módulo registrado en `system_modules` genera **7 permisos**, no 4: los define
`SystemModuleObserver::CANONICAL_ACTIONS` y los crea el observer en el momento en
que se inserta la fila del módulo. **La ruta es la fuente de verdad**, no el
nombre del permiso.

| Acción | Qué habilita realmente |
|---|---|
| **view**   | El **sidebar** del módulo + el **listado**. Sin `view` el módulo no aparece en el menú y el index responde 403. |
| **show**   | Ver la **ficha** de un registro. Sin esto, entrar por URL a `/x/{id}` se bloquea. |
| **create** | Botón **Nuevo** + **Duplicar**, y bloqueo por URL si no lo tiene. |
| **edit**   | Botón **Editar** + **"Editar todo"** (edición masiva). |
| **delete** | **Eliminar** (soft-delete) + las masivas (borrar lote, activar/desactivar lote) + el **Deshacer** de 60 s. |
| **export** | **Exportar** el listado (CSV/Excel/PDF/Word). Ver el aviso de abajo: hoy no está cableado en todos los módulos. |
| **import** | **Importar** registros. Mismo aviso. |

### Aviso: `export` / `import` no gatean en los módulos de DOCUFIZ

El diseño es que exportar exija `{modulo}.export` además del plan por formato, de
modo que un perfil pueda **ver sin poder exportar** (el caso de cumplimiento y
protección de datos). Eso **está cableado solo en `customers` y `brands`**.

En `companies`, `people`, `work_plans`, `form_templates` y `form_submissions` las
rutas de exportación solo exigen `{modulo}.view`, y las de importación solo
`{modulo}.create` + `plan_feature:bulk_operations`. Es decir: **quien puede ver,
puede exportar**, aunque su perfil no tenga marcada la casilla.

Se comprueba en `routes/business_management.php`: los bloques de `customers` y
`brands` llevan `permission:{modulo}.export` en cada ruta de export; los cuatro
bloques generados con `make:module` no. Marcarlo o desmarcarlo en un perfil no
cambia nada en esos módulos hasta que se añada el middleware.

### "Editar todo"

No está gateado por rol. Se gatea por `permission:{modulo}.edit` **+**
`plan_feature:edit_all`. Parece "de admin" solo porque admin y super tienen todos
los permisos; un perfil custom con `.edit` y el plan adecuado también la usa.

---

## 3. Permisos transversales (no salen de un módulo CRUD)

Los siembra `RolesAndPermissionsSeeder` aparte de los canónicos.

| Permiso | Qué controla |
|---|---|
| **form_submissions.sign** | **Firmar** un formato, con reconocimiento facial o con captura por tiempo de espera. Está separado del CRUD a propósito: se puede llenar un formato sin poder firmarlo. |
| **signature_events.review** | Resolver la **bandeja de firmas pendientes**, autorizar una firma manual, quitar el enrolamiento de una persona y **ver los archivos de evidencia**. Es el permiso más sensible del sistema: es quien puede mirar las fotos de las caras. |
| **comments.view / create / delete** | Ver, escribir y borrar comentarios de usuario. |
| **diagnosis_notes.create** | **Permiso muerto.** Venía del hilo "Nota del diagnosticador" de TRAFODEX. Se sigue sembrando y sigue apareciendo en el formulario de perfiles, pero no habilita nada. |

Las cuatro rutas que gobierna la firma están en `routes/field_work.php`, y todas
llevan además un `throttle` propio: 30/min para pedir descriptores, 10/min para
enrolar, 60/min para firmar.

### Los comentarios están desconectados

`CommentController` existe, sus rutas existen y sus permisos se siembran, pero su
allowlist de tipos comentables (`CommentController::TYPES`) **está vacía**: la
purga se llevó los dos únicos tipos que había (transformador y muestra) y no se
puso ninguno del dominio nuevo. Hoy cualquier llamada a comentar falla la
validación de `type`.

Decidir a qué se comenta en DOCUFIZ —el plan de trabajo es el candidato obvio— es
lo que reactiva `comments.*`. Mientras tanto, esos permisos no gatean nada.

---

## 4. Lo que se gatea por ROL, no por permiso

Un perfil custom **nunca** entra aquí, tenga los permisos que tenga.

| Sección | Quién entra | Dónde se comprueba |
|---|---|---|
| **Core**: Workspaces, Regiones, Idiomas, Países, Locales, Ajustes, Módulos del sistema | **Solo super** | `routes/system_management.php` |
| **Papelera, restaurar y borrado definitivo** de cualquier módulo | **Solo super** | `role:super` al inicio de cada bloque de módulo |
| **Perfiles** (Roles) y **Usuarios** | **super o admin** (+ plan `team_management`) | `routes/user_management.php` |
| **Auditoría** (`audit_logs`) | **super o admin** | `routes/system_management.php` |
| **Automatizaciones** | **super o admin** (+ plan `automations`) | `routes/automation_management.php` |
| **Bloquear / desbloquear** un registro | **super o admin** | `role:super\|admin` en cada módulo |

> **La auditoría no se gatea por plan.** La feature `audit_log_view` existe en la
> tabla `plans` y se puede marcar desde el formulario de planes, pero **ninguna
> ruta la usa**: `/audit_logs` está protegido solo por `role:super|admin`. Un
> workspace en plan `free` ve su auditoría igual. Es una feature declarada sin
> gate; o se cablea o se quita del plan.

Los módulos core no generan permisos a propósito (ver el comentario de
`SystemModulesSeeder`): si los generaran, un admin podría asignárselos a un rol
suyo y quedaría con permisos que ninguna ruta respeta. Filas fantasma.

---

## 5. Gates de PLAN

| `plan_feature` | Bloquea | Dónde |
|---|---|---|
| `imports` | Importar usuarios | `routes/user_management.php` |
| `bulk_operations` | Acciones masivas + importar en los módulos de negocio | `routes/business_management.php` |
| `export_excel` / `export_pdf` / `export_word` | Exportar a ese formato. **CSV siempre libre.** | ídem |
| `edit_all` | "Editar todo" | ídem |
| `team_management` | Usuarios y Perfiles enteros | `routes/user_management.php` |
| `automations` | Automatizaciones | `routes/automation_management.php` |
| `saved_views` | Vistas guardadas | `routes/saved_views.php` |
| `api_access` | Todo `/api/v1` | `routes/api.php` |

Declaradas en la tabla `plans` pero **sin gate en ninguna ruta**: `audit_log_view`
(ver arriba), `report_sharing` (era compartir un informe de diagnóstico con un
cliente externo — ese flujo ya no existe), `customer_scoped_users`,
`branded_exports`, `scheduled_exports`, `export_webhook_delivery`,
`export_email_delivery`, `extended_retention`, `higher_export_rate_limit`.

`report_sharing` es la única de esa lista que ya no tiene futuro: sobra y se puede
quitar de `PlansSeeder` y de `config/features.php`.

---

## 6. Restricciones especiales (casos que sobreescriben los permisos)

### 6.1 Registros GLOBALES (`tenant_id = NULL`) — solo-lectura para todos menos super

Un registro global lo **ven** todos los workspaces, pero un no-super **no puede
editar, eliminar ni duplicar** ni llegar por URL a esas acciones. Triple bloqueo:

- **Listado**: iconos de editar/borrar ocultos, etiqueta "Global", y la casilla de
  selección masiva deshabilitada para esas filas.
- **Ficha**: acciones ocultas + etiqueta de protegido.
- **Backend**: el trait `BelongsToTenantOrGlobal` lanza `AuthorizationException`
  en `updating` / `deleting` si no es super. El handler de `bootstrap/app.php` lo
  convierte en una redirección al dashboard con aviso, no en un 403 crudo. El
  registro queda intacto.

Solo el super crea, edita o borra globales.

En DOCUFIZ esto encaja con los catálogos de obra: un catálogo de cargos o de
nacionalidades que publica el super lo usan todos los workspaces sin poder
tocarlo.

### 6.2 Override por clientes asignados → solo-lectura en Clientes

Si un usuario tiene clientes asignados (la pivote `customer_user` no está vacía),
el trait `RestrictedToAssignedCustomers` lo limita a su cartera y además **no
puede crear, duplicar, editar ni eliminar clientes** — aunque su perfil tenga
`customers.create/edit/delete`. El override gana sobre el permiso, a propósito:
"así el perfil esté mal creado".

Se aplica **solo al módulo Customers**: el trait lo usa `App\Models\Customer` y
nadie más. Los módulos de DOCUFIZ no tienen equivalente; si algún día hace falta
limitar a un usuario a un subconjunto de empresas contratistas, esto es el patrón
a copiar.

### 6.3 Aislamiento por tenant

Cada workspace ve solo sus registros (`BelongsToTenant`), con bypass del super. Un
tenant nunca ve datos de otro. Los globales (6.1) son la excepción
visible-para-todos.

### 6.4 Asignación de roles

Un admin puede asignar a sus usuarios cualquier perfil global que no sea `api`. El
super asigna cualquiera.

### 6.5 Lock por registro (congelar) — trait `Lockable`

Un registro **bloqueado** queda inmutable para el usuario: no se puede editar,
eliminar, duplicar-sobre, pisar por import, edit-all ni bulk **hasta que se
desbloquee**. Distinto de "global" (6.1): global = *no es tuyo* (propiedad); lock
= *es tuyo pero está congelado* (estado). Conviven.

- **Quién bloquea**: super o admin (rutas `role:super|admin`).
- **Niveles (`lock_scope`)**: un admin bloquea a nivel `tenant` (lo saca ese admin
  o el super); el super bloquea a nivel `super`, y **solo el super lo saca**.
- **Es reversible**: lo desbloquea quien tenga el nivel suficiente.
- **Dónde se aplica**: en la **capa de request** (FormRequests + controllers), no
  en eventos de modelo, para no frenar escrituras internas del sistema.
- **Implementación**: `app/Traits/Lockable.php` + el concern
  `HandlesRecordLocking`. Columnas `locked_at` / `locked_by` / `lock_scope`.

> **Solo funciona en `customers`.** El trait está puesto en `Customer`, `Brand`,
> `Company`, `Person`, `WorkPlan` y `FormTemplate`, y los seis tienen sus rutas
> `lock` / `unlock` en `routes/business_management.php`. Pero la única migración
> que añade las tres columnas es
> `2026_06_26_120000_add_lock_columns_to_lockable_modules.php`, y su lista de
> tablas es `['customers']`. En los otros cinco módulos el bloqueo va contra
> columnas que no existen. **Sin comprobar en ejecución**: no se ha probado qué
> hace exactamente al pulsar el botón, pero no puede funcionar. Antes de
> documentarlo como una capacidad de DOCUFIZ hay que añadir las columnas.

---

## 7. Resumen de una línea por rol

- **super**: todo, sin restricción. Único que gestiona lo global, el core y la
  papelera.
- **admin**: su tenant. Todos los permisos de su workspace, más usuarios,
  perfiles, auditoría, automatizaciones y bloqueo de registros. No entra al core
  ni a la papelera.
- **perfil custom**: solo lo que sus permisos habiliten dentro de los módulos
  delegables. Nunca core, papelera ni auditoría.
- **+ clientes asignados**: además, solo-lectura en Clientes.

---

## 8. Los 4 perfiles globales sembrados

Plantillas que publica el super (`tenant_id = NULL`); todos los workspaces las ven
y las asignan a sus usuarios. Solo-lectura para los admin; solo el super las
edita. Salen de `RolesAndPermissionsSeeder`.

| Perfil | Permisos | Para quién |
|---|---|---|
| **Supervisor de obra** | `work_plans` y `form_submissions` todo salvo `delete`; `people` view/show/create/edit/export; `companies` y `form_templates` solo lectura; `form_submissions.sign`; `signature_events.review`; `comments.view/create` | Quien arma el plan del día, registra a su cuadrilla, llena y firma los formatos, y resuelve la bandeja de firmas pendientes. No elimina nada ni toca las plantillas de formato. |
| **Usuario de campo** | `work_plans` view/show; `form_submissions` view/show/create/edit; `people` view/show; `form_submissions.sign`; `comments.view/create` | El trabajador que llena y firma los formatos del plan al que está asignado. No crea planes ni da de alta personas. |
| **Auditor HSE (solo lectura)** | view/show/export sobre `companies`, `people`, `work_plans`, `form_templates`, `form_submissions`; `signature_events.review`; `comments.view` | Consulta y exporta todo. Puede abrir la bandeja de revisión y ver las evidencias, que es la razón de ser del perfil. No modifica nada. |
| **Soporte (editor)** | Todos los módulos de negocio salvo `delete`; `comments.view/create` | Crea y edita cualquier dato de negocio, no elimina. No firma. |

Nótese que `signature_events.review` lo llevan **dos** perfiles: el supervisor,
porque resuelve las firmas que quedaron pendientes en su cuadrilla, y el auditor,
porque revisar la evidencia es su trabajo. Nadie más ve las fotos.

> **El seeder tiene un resto de la versión anterior.** Al final asigna perfiles a
> cuatro usuarios de demostración, y tres de esas asignaciones nombran perfiles
> que ya no existen: "Empresa (solo lectura)", "Empresa (carga de muestras)" y
> "Soporte (editor full)". Como no están en la lista de perfiles creados, el
> `isset()` falla y **se saltan en silencio**: esos tres usuarios quedan sin rol.
> Solo `luis@example.com` recibe el suyo, porque "Soporte (editor)" sí existe. No
> rompe nada, pero al sembrar de cero hay que asignar los otros tres a mano.

---

## 9. Cómo aprobar en DOCUFIZ (y por qué no se parece a lo anterior)

En TRAFODEX la aprobación era un informe de diagnóstico que circulaba por correo:
se enviaba una solicitud, el aprobador respondía desde su bandeja, y el sistema
marcaba el informe. Ese flujo **no existe**.

En DOCUFIZ la aprobación es una fila de `work_plan_approvals` y **se firma con la
cara**, en la misma pantalla y con el mismo mecanismo que la firma de un
trabajador: `field_work/work_plans/{plan}/sign`. La fila apunta a la persona que
aprueba y a la `approval_rule` que la exige; el evento de firma cuelga de ella por
relación polimórfica (`signable`). Quién aprueba no es un permiso del sistema: es
una persona designada en el plan.

El permiso que gobierna el acto de firmar es `form_submissions.sign`, y el que
gobierna revisar lo que quedó dudoso es `signature_events.review`. No hay correo
en ninguna parte del camino.

Lo que sí sobrevivió es **`ReportSigner`**: los cargos de firma formal del
workspace (preparó, revisó, aprobó, autorizó…) que se estampan al pie del **PDF
generado**. Es otra cosa que la aprobación del plan: `ReportSigner` no aprueba
nada, solo dice qué nombres y qué cargos van impresos bajo las líneas de firma del
documento. La firma manuscrita solo se estampa si ese usuario lo autorizó
expresamente (`users.auto_sign_reports`).

El flujo completo, paso a paso, está en [`FLUJO.md`](FLUJO.md); el detalle del
reconocimiento facial, en [`BIOMETRIA.md`](BIOMETRIA.md).

---

## 10. Documentación relacionada

- [`PERMISSIONS.md`](PERMISSIONS.md) — cómo está implementado Spatie + el bypass del super
- [`plan-features.md`](plan-features.md) — la matriz plan × feature completa
- [`FLUJO.md`](FLUJO.md) — el recorrido de un día de obra y qué permiso gobierna cada paso
- [`CREATE-MODULE.md`](CREATE-MODULE.md) — qué permisos aparecen al crear un módulo nuevo
