# Modelo de accesos — permisos, roles, planes y restricciones

Referencia única de la lógica de permisos del sistema. Explica **qué controla
cada permiso**, las **3 capas de gateo** (permiso / rol / plan), los **perfiles**
sembrados y las **restricciones especiales** (registros globales, clientes
asignados, aislamiento por tenant).

> Regla mental: para que alguien pueda hacer algo, **las 3 capas deben dar OK**.
> Permiso del perfil **Y** rol (si la sección es por rol) **Y** plan (si la
> feature es premium). Falla una → bloqueado.

---

## 1. Las 3 capas de gateo

| Capa | Qué decide | Ejemplo |
|---|---|---|
| **Permiso** (Spatie, `{modulo}.{accion}`) | Si el perfil tiene la acción en ese módulo. | `customers.create` para crear clientes. |
| **Rol** (super / admin) | Lo que NO se delega a un perfil custom (core, tipos, reglas, logs…). | Solo super ve Workspaces. |
| **Plan** (`plan_feature:*`) | Si el workspace pagó esa feature. | `imports` para poder importar. |

Ejemplo combinado — importar clientes exige: `customers.create` **+** plan con
`imports` **+** `bulk_operations`. Si el perfil tiene el permiso pero el plan no
trae `imports` → bloqueado igual.

---

## 2. Las 7 acciones por módulo — qué gatea CADA una de verdad

Cada módulo de negocio (desde `system_modules`) genera 7 permisos. **La ruta es
la fuente de verdad**, no el nombre del permiso:

| Acción | Qué habilita realmente |
|---|---|
| **view**   | El **sidebar** del módulo + el **índice/listado**. Sin `view` el módulo no aparece en el menú y el index responde 403 → redirect amigable. |
| **show**   | Ver la **ficha/detalle** de un registro. Sin esto, entrar por URL a `/x/{id}` se bloquea. |
| **create** | Botón **Nuevo** (lo esconde de todos lados y bloquea por URL si no lo tiene) + **Duplicar**. |
| **edit**   | Botón **Editar** + **"Editar todo"** (edición masiva). En trafos, además habilita el editor de muestras (vía OR con `transformers.samples`). |
| **delete** | **Eliminar** (papelera/soft-delete) + **masivas** (borrar lote, activar/desactivar lote). |
| **export** | **Exportar** el listado (CSV/Excel/PDF/Word). Gate REAL (activado): `permission:{modulo}.export` **+** `plan_feature` por formato (CSV libre). Un rol puede VER pero NO exportar. |
| **import** | **Importar** registros. Gate REAL (activado): `permission:{modulo}.import` **+** `create` **+** `plan_feature:imports`/`bulk_operations`. |

> **export / import están ACTIVOS** (Debate B implementado) en los 7 módulos de
> negocio (customers, transformers, brands, laboratories, tap_changer ×3). Ya NO
> son permisos muertos: marcarlos/desmarcarlos en un perfil cambia el acceso real.
> Los 4 perfiles sembrados ya los traen donde corresponde.

### Aclaración sobre "Editar todo", import y export (dudas frecuentes)

- **"Editar todo"** NO está gateado por rol admin/super. Se gatea por
  `permission:{modulo}.edit` **+** `plan_feature:edit_all`. Parece "de admin"
  solo porque admin/super tienen todos los permisos; un perfil custom con `.edit`
  y plan `edit_all` también la usa.
- **Exportar** ahora exige `{modulo}.export` (además del plan por formato). Antes
  bastaba `view`; con la activación un rol puede VER pero NO exportar. El **CSV
  sigue libre de plan** (pero igual exige `.export`); Excel/PDF/Word dependen de
  `export_excel` / `export_pdf` / `export_word`.
- **Importar** ahora exige `{modulo}.import` (además de `create` + plan `imports`/
  `bulk_operations`). Antes bastaba `create`.

---

## 3. Permisos transversales (no pertenecen a un módulo CRUD)

| Permiso | Qué controla |
|---|---|
| **transformers.samples** | Cargar / editar / borrar **muestras de ensayo** (cromas, furanos, fisicoquímico, factor de potencia), el editor estilo Excel (batch/preview) y seleccionar muestras para la Tabla 4 IEEE. **NO** permite tocar la ficha (placa) del trafo — eso es `transformers.edit`. El editor de la grilla aparece con `samples` **o** `edit`. |
| **comments.view**   | **VER** comentarios (ambos contextos: nota del diagnosticador y comentarios de muestra). |
| **comments.create** | **ESCRIBIR** un **comentario de MUESTRA** (no la nota del diagnosticador). |
| **comments.delete** | **BORRAR** comentarios (ambos contextos). |
| **diagnosis_notes.create** | **ESCRIBIR la "Nota del diagnosticador"** (hilo a nivel del transformador). Separado de `comments.create` a propósito. |

### Detalle de los comentarios — DOS contextos, escritura separada

Hay **una sola** ruta `comments.store` polimórfica, pero la **escritura** está
separada por contexto (la ruta acepta `comments.create|diagnosis_notes.create` y
el `CommentController@store` exige el permiso correcto según el objeto comentado):

1. **"Nota del diagnosticador"** — el hilo a nivel del **transformador**
   (`TestDiagnosis.vue` → `CommentThread`, `commentable = Transformer`). Es la
   nota del especialista sobre el análisis. Escribirla = **`diagnosis_notes.create`**.
2. **"Comentario de muestra"** — el botón de comentario por fila de la grilla
   (`DiagnosticGrid.vue` → `CommentThread`, `commentable = Sample`). Anotación
   operativa de esa muestra puntual ("vino mal sellada"). Escribirlo =
   **`comments.create`**.

| Acción del usuario | Permiso que la controla |
|---|---|
| Registrar / editar / borrar **una muestra** | `transformers.samples` (o `transformers.edit`) |
| Escribir un **comentario en la muestra** | `comments.create` |
| **Escribir en "Nota del diagnosticador"** | **`diagnosis_notes.create`** |
| Ver cualquier comentario | `comments.view` |
| Borrar cualquier comentario | `comments.delete` |

> **Por qué están separados:** así un perfil "carga de muestras" puede comentar
> SU muestra (`comments.create`) sin poder firmar la nota del especialista
> (`diagnosis_notes.create`). **Ver** y **borrar** NO se separaron (siguen
> compartiendo `comments.view` / `comments.delete`): si podés ver el trafo, ver
> sus notas no molesta, y borrar notas es raro — separarlos sería superficie de
> permisos sin un caso que la pida.
>
> Dato que pesa: las notas/comentarios **NO salen en el PDF del informe** — son
> anotaciones internas de la UI, no parte del documento firmado/compartido.

---

## 4. Lo que se gatea por ROL, no por permiso (un perfil custom NUNCA entra)

| Sección | Quién entra |
|---|---|
| **Core**: Workspaces, Regiones, Idiomas, Países, Locales, Settings, System Modules | **Solo super** |
| **Tipos** de aceite / transformador / conmutador | **Solo super** |
| **Perfiles** (Roles) | **super o admin** (+ plan `team_management`) |
| **Reglas de diagnóstico** (semáforos, datasets) | **super o admin** |
| **Logs / auditoría** | **super o admin** |
| **Automatizaciones** | **super o admin** (+ plan `automations`) |

> Aunque un perfil custom tuviera permisos de `users`/`roles`, no vería esos
> menús: están gateados por rol. Por eso `users`/`roles` se sacaron del form de
> perfiles. **Usuarios** es el caso mixto: `users.view` **+** plan
> `team_management` (permiso + plan, no rol).

---

## 5. Gates de PLAN (aunque tengas el permiso, el plan puede bloquear)

| `plan_feature` | Bloquea |
|---|---|
| `imports` | Importar (cualquier módulo) |
| `export_excel` / `export_pdf` / `export_word` | Exportar a ese formato (CSV siempre libre) |
| `bulk_operations` | Acciones masivas + import |
| `edit_all` | "Editar todo" |
| `team_management` | Usuarios y Perfiles |
| `automations` | Automatizaciones |
| `report_sharing` | Compartir informe con cliente externo |

---

## 6. Restricciones especiales (casos que sobreescriben los permisos)

### 6.1 Registros GLOBALES (`tenant_id = NULL`) — solo-lectura para todos menos super

Un registro global lo **ven** todos los workspaces, pero un no-super **no puede
editar, eliminar ni duplicar** ni acceder por URL a esas acciones. Triple
bloqueo:

- **Índice**: iconos de editar/borrar ocultos + tag "Global" + checkbox de
  selección masiva deshabilitado para esas filas.
- **Ficha (Show)**: acciones ocultas + tag "Protegido / Global".
- **Backend**: el trait `BelongsToTenantOrGlobal` lanza `AuthorizationException`
  en `updating` / `deleting` si no es super → el handler de `bootstrap/app.php`
  lo convierte en **redirect amigable al dashboard con toast de error** (no un
  403 crudo). El registro queda intacto.

Solo el super crea/edita/borra globales.

### 6.2 Override por clientes asignados → solo-lectura en Clientes

Si un usuario tiene empresas asignadas (pivot `customer_user` no vacío), el trait
`RestrictedToAssignedCustomers` lo limita a SU cartera, y además **no puede
crear, duplicar, editar ni eliminar clientes** — **aunque su perfil tenga**
`customers.create/edit/delete`. El override gana sobre el permiso (decisión
explícita: "así el perfil esté mal creado y tenga acceso a crear clientes").
Implementado en los `authorize()` de los FormRequests de Customer
(Store/Update/Delete/Import/BulkDelete/BulkSetActive/EditAllUpdate) vía
`empty($this->user()?->assignedCustomerIds())`.

### 6.3 Aislamiento por tenant

Cada workspace ve solo sus propios registros (`BelongsToTenant`), con bypass del
super. Un tenant nunca ve datos de otro. Los globales (6.1) son la excepción
visible-para-todos.

### 6.4 Asignación de roles

Un admin puede asignar a sus usuarios cualquier perfil global no-`api`. El super
asigna cualquiera.

### 6.5 Lock por registro (congelar) — trait `Lockable`

Un registro **bloqueado** queda inmutable para el usuario: no se puede editar,
eliminar, duplicar-sobre, pisar por import, edit-all ni bulk **hasta que se
desbloquee**. Distinto de "global" (6.1): global = *no es tuyo* (propiedad);
lock = *es tuyo pero está congelado* (estado). Conviven.

- **Quién bloquea:** super o admin (rutas `role:super|admin`).
- **Niveles (`lock_scope`):** un admin bloquea a nivel `tenant` (lo saca ese admin
  o el super); el super bloquea a nivel `super` (**solo el super lo saca**; el
  admin lo ve con candado "del sistema" pero no lo puede quitar).
- **Se puede desbloquear:** sí, es reversible — quien tenga el nivel suficiente.
- **Dónde se aplica:** en la **capa de request** (FormRequests + controllers),
  NO en eventos de modelo. Esto es deliberado: el motor de diagnóstico le hace
  `save()` directo a los transformers (caché health_index, etc.); un guard en
  `updating` frenaría ese recálculo. Así el lock bloquea las acciones del usuario
  sin romper las escrituras internas del sistema.
- **Módulos:** `customers`, `transformers` y los catálogos `brands`,
  `laboratories`, `tap_changer_brands`, `tap_changer_models`,
  `tap_changer_technologies` (7 en total). El trait es genérico; los catálogos
  super-only globales (oil_types/transformer_types/tap_changer_types) NO lo
  llevan (los gestiona solo el super, no aplica el lock per-tenant).
- **Límite V1 en trafos:** el lock congela la FICHA (editar/eliminar/bulk/edit-all/
  import). NO bloquea la carga de MUESTRAS (`transformers.samples`), que es otro
  permiso. Si se quiere "freeze total" (sin muestras nuevas), es una extensión.
- **UI:** botón Bloquear/Desbloquear + tag de candado en la ficha (`EntityShowActions`);
  en el índice se ocultan editar/eliminar y se muestra el candado. Los bulks
  saltean los bloqueados y avisan cuántos omitieron.
- Implementación: `app/Traits/Lockable.php` + `HandlesRecordLocking` (concern de
  controller). Columnas `locked_at` / `locked_by` / `lock_scope`.

---

## 7. Resumen de una línea por rol del sistema

- **super**: todo, sin restricción. Único que gestiona lo global y el core.
- **admin**: su tenant. No core, no tipos. Sí ve reglas de diagnóstico, logs,
  usuarios/perfiles, automatizaciones. Tiene todos los permisos de su tenant.
- **perfil custom**: solo lo que sus permisos habiliten dentro de los módulos
  delegables (customers, transformers + samples, brands, laboratories,
  conmutador-marcas/modelos/tecnologías, comments). Nunca core/tipos/reglas/logs.
- **+ clientes asignados**: además, solo-lectura en Clientes.

---

## 8. Los 4 perfiles globales sembrados

Plantillas que publica el super (`tenant_id = NULL`); todos los workspaces las
ven y las asignan. Solo-lectura para los admin, solo el super las edita.

| Perfil | Permisos | Qué puede |
|---|---|---|
| **Empresa (solo lectura)** | `transformers` view/show/export + `comments.view` | Ve dashboard y trafos con su diagnóstico. No crea/edita/elimina nada. |
| **Empresa (carga de muestras)** | lo anterior + `transformers.samples` + `comments.view/create` (**sin** `diagnosis_notes.create`) | Carga muestras de ensayo y comenta SUS muestras. Lee la nota del diagnosticador pero NO la escribe. |
| **Soporte (editor)** | todos los módulos de negocio salvo `delete` + `transformers.samples` + `comments.view/create` + `diagnosis_notes.create` | Crea y edita cualquier dato, no elimina. Firma la nota del diagnosticador. Sin reglas ni logs. |
| **Soporte (editor full)** | todo sobre datos de negocio incl. `delete` + `transformers.samples` + `comments.view/create/delete` + `diagnosis_notes.create` | Gestión completa de datos de negocio. No gestiona accesos ni ve reglas/logs. |

> **Tu caso:** un perfil **solo-lectura puro** funciona perfecto (no permite
> crear nada). El caso "solo lectura + escribir algunas cosas de trafos" ya está
> resuelto: `comments.create` (comentar muestra) y `diagnosis_notes.create`
> (escribir la nota del diagnosticador) son permisos distintos (ver sección 3).

---

## 9. Debates abiertos (no implementados — decidir)

### Debate A — separar "Notas del diagnosticador" de "Comentarios de muestra" — RESUELTO

**Implementado:** se creó el permiso `diagnosis_notes.create` para escribir la
nota del diagnosticador, dejando `comments.create` solo para los comentarios de
muestra (ver sección 3). Se separó **solo el `create`**; `comments.view` y
`comments.delete` siguen compartidos (separar ver/borrar sería superficie sin un
caso que la pida). Cableado: ruta `comments.store` con
`permission:comments.create|diagnosis_notes.create` + chequeo por tipo en
`CommentController@store`; `CommentThread.vue` elige el permiso según el tipo;
perfiles sembrados actualizados (carga de muestras = `comments.create`;
editor/full = + `diagnosis_notes.create`). Test:
`test_sample_commenter_cannot_write_diagnostician_note`.

### Debate B — `import`/`export` como permisos reales — RESUELTO (activados)

**Implementado (opción B1):** los permisos `{modulo}.export` / `{modulo}.import`
ahora son gates reales en los 7 módulos de negocio (customers, transformers,
brands, laboratories, tap_changer ×3). Habilita el caso compliance/LPDP: un rol
que VE pero NO exporta.

- **Rutas** (`routes/business_management.php`): cada export (excel/pdf/word/csv)
  exige `permission:{modulo}.export` (+ throttle + `plan_feature` por formato,
  CSV sin plan); el grupo de import exige `permission:{modulo}.import` (+ `create`
  + `plan_feature:bulk_operations`). Enfoque por-ruta (no se tocaron los grupos
  `view`, que en trafos comparten rutas report/explain).
- **Frontend**: el botón Exportar se gatea por `can('{modulo}.export')`; Importar
  por `can('{modulo}.import')` (en Index + drawers móviles). super = bypass.
- **Perfiles sembrados**: ya traían `.export`/`.import` donde corresponde
  (Empresa = export; Soporte = export + import), así que NO hubo re-seed.
- **Test**: `ImportExportPermissionTest` (view sin export → 403; con export → OK;
  create sin import → 403). Suite completa verde.
- **PDF del informe** del trafo sigue con `transformers.view` (no es export de
  listado) → no se rompió.

> Los módulos super-only (oil_types/transformer_types/tap_changer_types) NO se
> tocaron: su export/import sigue por `view`/`create` (no se delegan a perfiles,
> los gestiona solo super).
