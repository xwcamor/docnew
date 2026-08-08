# El sistema anterior, lógica por lógica

Este documento existe porque el dueño del producto dijo, con razón:

> «analiza bien el funcionamiento del sistema antiguo, estás quitando varias
> lógicas, compáralas con cómo las estás haciendo»

Se leyó el código Rails completo —`app/models/plan.rb`, los controladores de
`plan_management`, las vistas de la ficha y las etiquetas reales de la tabla
`translations`— y se comparó con lo construido aquí. Cada fila dice qué hacía la
v1, qué hace DOCUFIZ, y **si el cambio fue deliberado o un descuido**.

Regla que sale de aquí y vale para todo lo que quede por portar: **una lógica de
la v1 sólo se cambia con un motivo escrito.** Si no hay motivo, no es una mejora,
es una pérdida.

---

## 1. Lo que se perdió sin querer, y ya está devuelto

### 1.1 Las fechas llevaban hora

| | |
| --- | --- |
| **v1** | `date_start datetime(6) NOT NULL`, `date_end datetime(6)`. Las etiquetas lo dicen: «Fecha y Hora de Inicio», «Fecha y Hora de Fin» |
| **Se hizo** | `date('date_start')`, `date('date_end')` |
| **Daño** | 3 712 planes con la hora truncada a medianoche; 3 600 fines de jornada perdidos; «Tiempo Trabajado» sin sentido |
| **Arreglado** | `2026_08_08_150000_restore_datetime_on_work_plan_dates`, `MigrateLegacyDataCommand::fechaHora()`, re-migrado |

Verificado tras el arreglo: 3 712 con hora de inicio, 3 600 con hora de fin.
Coincide con el origen fila por fila.

No era sólo un tipo de columna. El código del plan de la v1 **se construía con
la hora de inicio** (`PE24-0412-0458` = país + año + día/mes + hora/minuto), así
que sin hora el código original tampoco se puede reproducir.

### 1.2 «Tiempo Trabajado» era una tarjeta propia

`Plan#worked_time` devolvía «2 horas y 30 minutos» y `Plan#worked_time_hours` el
decimal. La ficha le daba su propia tarjeta, con reloj de arena.

Portado como `WorkPlan::getWorkedTimeAttribute()` y `getWorkedHoursAttribute()`.
Una duración negativa (fin antes que inicio) devuelve `null` en vez de «-3
horas»: es un dato malo, no un trabajo de duración negativa.

### 1.3 El DNI se enseñaba tapado

Esto es lo más grave que se había perdido. La v1:

```erb
<%= "#{"*" * (worker.num_doc.to_s.length - 2) + worker.num_doc.to_s[-2..]} - #{worker.name}" %>
<%= approver.num_doc.gsub(/.(?=..)/, '*') %>
```

Todo asteriscos menos los dos últimos dígitos, y el número entero sólo para
usuarios con `users.display_private_info`. La foto salía borrosa y la firma se
sustituía por una imagen genérica para los demás.

DOCUFIZ mandaba el `num_doc` completo en el JSON de Inertia desde **17 archivos**.

Portado como `App\Support\PrivateInfo` + `Person::getSafeNumDocAttribute()` +
permiso `people.view_private_info`. Se concede por perfil en vez de por usuario,
que es como se dan los permisos en esta base.

**El enmascarado va en el servidor.** Un `v-if` en Vue esconde el número en
pantalla pero lo manda igual en el JSON, y ahí lo lee cualquiera que abra las
herramientas del navegador.

### 1.4 Nunca se listaba a las personas

La v1 añadía trabajadores con un campo `dni-scanner` —«Escanea DNI ó documento
del trabajador aquí»— contra `search_num_docs`, que hacía **búsqueda exacta por
documento acotada a la empresa del plan**. Los aprobadores iban por un select2
con `minimumInputLength: 8`. Si no aparecía: «¿No se encuentra el documento del
trabajador?» y se daba de alta.

DOCUFIZ tenía `personCandidates`, que **con la búsqueda vacía devolvía 25
personas con su documento completo** — y la pantalla lo llamaba sola al recibir
el foco. Era un volcado del padrón a un clic.

Arreglado: mínimo de 8 caracteres, búsqueda sólo por documento, respuesta con el
nombre y el documento tapado. Fijado por `test_el_buscador_de_personas_no_vuelca_el_padron`.

### 1.5 Las aprobaciones no se borran

La ficha de la v1 no tenía botón de borrar en las aprobaciones, y es coherente:
las genera `ApprovalRule` por país en un `after_create`. Quitar la fila del
supervisor HSE no quita la obligación de que firme, sólo la esconde — y el plan
pasa por completo sin estarlo.

Se quitaron `removeApproval()` y su ruta. En su lugar está `assignApprover()`,
que es lo único que la v1 hacía: ponerle nombre a una aprobación pendiente.

`test_no_existe_manera_de_borrar_una_aprobacion` lo comprueba **sobre el
enrutador**, no dentro del servicio: la protección de verdad es que la operación
no exista.

### 1.6 El nombre se lista por el apellido

`Worker#str_complete_name_pro` devolvía `lastname + name`. Portado como
`Person::getListNameAttribute()`.

---

## 2. Lo que se cambió a propósito, con motivo

### 2.1 El código del plan

| | |
| --- | --- |
| **v1** | `PE24-0412-0458` — país, año, día+mes, **hora+minuto de inicio** |
| **Aquí** | `PE26-0608-0001` — país, año, día+mes, **correlativo del día** |

Dos planes que empiezan en el mismo minuto reciben el mismo código en la v1, y
pasaba: hay códigos repetidos en los datos reales. El dueño lo aprobó
explícitamente («aquí eso lo generaba el sistema»). El código original se
recupera siempre por `legacy_id`.

### 2.2 `is_active` en los planes

La v1 tiene `plans.is_active NOT NULL` y un `active_status` que pinta
Activo/Bloqueado. **No se usa en ninguna vista de planes**, no está en los
filtros de búsqueda, y en 3 722 planes reales su valor es `true` en todos.

No se portó. Una columna que nunca cambia de valor no es un estado, es ruido. El
estado de un plan aquí es `is_done` (avance) e `is_closed` (el `is_locked` de la
v1). Si algún día hace falta bloquear un plan, existe el candado del trait
`Lockable`, que además deja rastro de quién y cuándo.

### 2.3 El orden de las firmas se enseña en vez de esconderse

La v1 escondía las aprobaciones que aún no tocaban:

```erb
<% next if approver_type != "worker" && !all_required_workers_signed %>
```

Aquí salen en gris, con candado y con el motivo escrito («Primero tienen que
firmar los trabajadores»). Se ve el camino entero sin poder saltárselo. Esconder
deja al supervisor sin saber cuántos pasos le quedan, que en obra es la pregunta
«¿y ahora qué?».

### 2.4 La evidencia no se guarda por trabajador

La v1 guardaba `photo` y `signature` como columnas de `plan_workers` y de
`plan_approvals`, con los archivos en `public/images_uploads/`. Aquí hay una sola
tabla `signature_events` + `evidence_files`, con deduplicación por sha256,
compresión a WebP y servida autenticada.

Motivo: 500 fotos diarias a tamaño original son ~15 GB al año. Comprimidas y
reutilizadas por plan y día, ~1 GB. Y `public/` significa que cualquiera con la
URL ve la cara de un trabajador.

### 2.5 `user_id` pasa a llamarse `created_by`

En la v1 **todas** las tablas llevan `user_id` con el sentido de «quién lo
registró». Aquí es `created_by`, que es como se llama en la base heredada de
TRAFODEX y lo que evita confundirlo con «el usuario al que pertenece».

En `work_plans` se conservan las dos: `user_id` es el `plans.user_id` migrado tal
cual (quién armó el plan, dato del negocio) y `created_by` el de auditoría.

---

## 3. Los nombres, tomados de la tabla `translations`

No inventados. Leídos de la v1 en producción:

| Clave v1 | Texto | Dónde se usa aquí |
| --- | --- | --- |
| `plans.workers` | **Trabajadores del Proveedor** | `work_plans.crew_title` |
| `plans.card_approval` | **Flujo de Aprobaciones** | `work_plans.approvals_title` |
| `plans.formats_to_do` | Formatos | `work_plans.forms_title` |
| `plans.period_work` | Período de Trabajo | `work_plans.period_work` |
| `plans.time_worked` | Tiempo Trabajado | `work_plans.worked_time` |
| `plans.date_start` | **Fecha y Hora de Inicio** | `work_plans.date_start` |
| `plans.company_id` | Proveedor de Hitachi | — |
| `plans.description` | Trabajo a Realizar | — |
| `plans.title_plural` | Documentación HSE | — |
| `plans.type_document` | Escanea ó digite DNI | `work_plans.crew_search_placeholder` |

Sobre la pregunta «¿es correcto poner Trabajadores o ponemos otro nombre?»: la
respuesta es del propio sistema. Se llamaban **«Trabajadores del Proveedor»**, y
eso además dice de quién son —los pone la contratista, no Hitachi—. «Cuadrilla»
no aparece en ningún sitio de la v1: la inventé yo.

---

## 4. Lo que queda por comparar

Honestamente, esto cubre el plan y su flujo de firmas. **No** se ha comparado
todavía, y hasta que se haga no se puede dar por portado:

- [ ] `f1_document`…`f4_document` — la v1 tiene una tabla por formato con su
      lógica propia (`recalculate_observations_and_confirmation`,
      `sync_f3_document_workers`). Aquí es un motor genérico. Hay que comprobar
      formato por formato que no se perdió ninguna regla de cálculo.
- [ ] `plan_exports_controller` — la exportación a ZIP y los PDF por formato.
- [ ] `Plan#lock_plan_if_all_conditions_met` — la v1 cierra el plan sola cuando
      hay `date_end` y no quedan aprobaciones obligatorias sin firmar. Aquí el
      cierre es manual. **Falta decidirlo**, y probablemente hay que portarlo.
- [ ] `must_have_at_least_one_document_and_worker` — la v1 no deja guardar un
      plan sin al menos un formato y un trabajador. Aquí sí se puede.
- [ ] `settings.num_doc_minimum` por país — aquí está fijo en 8 (Perú).
      `WorkPlanSetupController::MINIMO_DOCUMENTO`.
