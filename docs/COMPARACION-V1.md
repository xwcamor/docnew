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

### 1.6 Los formatos obligatorios del tipo de trabajo no se quitan

`work_type_documents` tiene `is_required`, y en los datos reales casi todas las
filas están en 1 con alguna en 0. El tipo de trabajo decide qué papeles exige esa
clase de maniobra: eso es lo que impide que un trabajo salga sin AST.

DOCUFIZ dejaba quitar **cualquier** formato del plan; sólo lo impedía si ya
tenía respuestas. Así que un plan podía quedarse sin su AST estando vacío, que es
justo cuando más fácil es hacerlo.

Ahora:

- El **obligatorio del tipo** no se quita. El botón sale con candado y el mensaje
  dice dónde se cambia (en el tipo de trabajo, que afecta a todos los planes y
  deja rastro).
- El **opcional del tipo** (`is_required = 0`) sí se descarta en el plan del día.
- El **añadido a mano a este plan** también.

La comprobación que vale está en `WorkPlan::expectedFormTemplates()`, no sólo en
el servicio: hay planes migrados con exclusiones hechas antes de que existiera la
regla, y un AST excluido en su día no puede seguir sin aparecer.

### 1.7 El plan se cierra solo

`Plan#lock_plan_if_all_conditions_met`, repetido en `PlanApproval#lock_plan`:

```ruby
if date_end.present? && plan_approvals.where(is_required: true, is_approved: false).none?
  update_columns(is_locked: true, is_done: true)
```

**Cerró 3 297 de los 3 653 planes vivos. Ninguno a mano.** Aquí `is_done` sólo se
ponía editando el plan, así que el 90% se habría quedado abierto para siempre y
la lista de pendientes no habría servido de nada.

Portado en `WorkPlanCompletionService`, disparado al guardar el plan, al firmar
y al confirmar un formato: cualquiera de las tres puede ser la última en llegar.

**Aquí la regla es más estricta que la de la v1, por decisión del dueño.** No
basta con la hora de fin y las aprobaciones: se exige el plan entero.

| | v1 | DOCUFIZ |
| --- | --- | --- |
| Hora de fin | sí | sí |
| Aprobaciones obligatorias firmadas | sí | sí |
| Al menos 1 trabajador y 1 formato | *(garantizado al crear)* | sí |
| **Todos** los trabajadores firmaron | no | **sí** |
| **Todos** los formatos confirmados | no | **sí** |

La v1 cerraba el plan con la firma del autorizante aunque quedaran formatos en
borrador. Un AST en borrador no es un AST, y el documento va a acabar delante de
un inspector.

Los 3 297 planes migrados conservan el estado con el que llegaron —cerrados bajo
la regla vieja— y **no se reabren**: la regla nueva rige de aquí en adelante.

### 1.8 El nombre se lista por el apellido

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

## 4. La lógica de cálculo de los cuatro formatos

Comparada. Los cuatro tenían un `set_completed` que corría después de cada
guardado y escribía dos columnas: `observations` (un ENTERO: cuántas casillas
salieron mal) e `is_confirmed`. Las reglas:

| Formato | Regla de la v1 | Aquí |
| --- | --- | --- |
| AST (`f1`) | cuenta los peligros con `risk_value <= 15` | igual, pero contra la última banda que declare la plantilla, no contra un 15 escrito a mano: un formato de otro país puede traer otra matriz |
| PTF (`f2`) | lo mismo — el banco de preguntas **no** cuenta | igual, incluido que el banco de preguntas no cuenta |
| EPP (`f3`) | cuenta las respuestas con valor `0` | cuenta las **negativas**. Ver abajo |
| IHM (`f4`) | igual que EPP | igual que EPP |

`observations` no se pudo reutilizar como nombre porque en DOCUFIZ ya existe y
es otra cosa —texto libre, donde la migración escribe los permisos y
herramientas adicionales del AST—. La columna nueva es
`form_submissions.nonconformities`, la calcula `FormFindingsService` y nadie la
escribe a mano.

### La divergencia deliberada: qué respuesta cuenta

En la v1, `set_completed` contaba `answers.count(0)`. El `0` del formulario de
EPP es el botón **«No aplica»**, y el `2` —**«No conforme»**— quedaba fuera. Un
trabajador sin arnés porque el trabajo no es en altura sumaba observación; uno
con el arnés roto, no. Es un error, no una regla, y aquí se cuenta lo que no
está conforme.

**Consecuencia sobre los datos migrados, que hay que decidir.** Al recontar los
cinco años con la regla correcta salen **150 416 observaciones en EPP y 43 523
en IHM**. El motivo es que la v1 no distinguía: de 100 000 respuestas de EPP,
66 827 dicen «No conforme», 32 623 «Conforme» y **«No aplica» no aparece ni una
sola vez**. Es decir, los operarios usaron el botón de «no conforme» también
para «este trabajador no lleva ese equipo», porque el otro botón nunca se usó.

El número es una lectura fiel de lo que quedó registrado —en los PDF de la v1
esas casillas dicen «No conforme» literalmente—, pero no es un historial de
seguridad utilizable. Tres salidas posibles, y es decisión del dueño:

1. dejarlo como está y tratar el número como válido sólo de aquí en adelante;
2. reinterpretar el histórico (marcar los EPP anteriores al corte como no
   comparables);
3. no contar el histórico y arrancar el contador en cero para lo migrado.

De aquí en adelante el dato sí es limpio: el formulario tiene los tres botones
y «No aplica» no suma.

### Lo que NO bloquea

Tener observaciones no impide confirmar el formato ni cerrar el plan. En la v1
tampoco: `lock_plan_if_all_conditions_met` sólo mira `date_end` y las
aprobaciones obligatorias firmadas, así que un plan con un EPP observado se
cerraba igual. Y tiene que ser así — un arnés en mal estado hay que poder
registrarlo y cerrar la jornada, con su medida de corrección al lado.

### `sync_f3_document_workers`

No hace falta portarlo. Allí las filas del EPP eran registros propios
(`f3_document_workers`) que había que sincronizar a mano cuando cambiaba la
cuadrilla. Aquí `PersonChecklistField.vue` recompone las filas contra la
cuadrilla actual en cada apertura: quien entró después sale con su fila vacía y
quien salió deja de aparecer.

---

## 5. Lo que salió al comparar: las respuestas migradas no se veían

Buscando el cálculo de arriba salió un defecto que no tiene que ver con la v1
sino con la migración a este sistema. **Las 14 435 entregas migradas se abrían
en blanco.**

`LegacyFormMapper` escribía las filas en castellano —`respuesta`, `pregunta`,
`herramienta`— y los campos compuestos del motor leen `answer`, `question` y
`tool`. El nombre del ítem sí cuadraba, porque esa clave se llama igual en los
dos sitios, así que la pantalla mostraba la lista de EPP completa y **ninguna
respuesta marcada**.

No saltó antes porque el PDF aplana el JSON tal cual, sin buscar claves
concretas: el documento impreso salía perfecto. Y no era cosmético — abrir un
EPP migrado y pulsar Guardar sobrescribía con nulos cinco años de respuestas,
porque la pantalla creía que no había ninguna.

Lo mismo en la matriz de riesgo, por el lado contrario: la v1 tiene cuatro
columnas de texto —actividad → peligro → **riesgo** → control, donde el riesgo
es la consecuencia («Agotamiento de recurso natural»)— y aparte el valor de la
matriz. La migración usaba esos nombres, que son los correctos; el componente
llamaba `riesgo` al número. Además:

- la migración nunca escribía `nivel`, así que los 3 657 AST y 3 662 PTF salían
  sin banda de color y con cero observaciones;
- **`ast_risks` no se traía**: el catálogo de consecuencias de la v1 se perdió
  entero, y con él la columna que la pantalla podía ofrecer.

Corregido: manda el nombre del dominio (`riesgo` = texto, `valor_riesgo` =
número, `nivel` = banda), el componente tiene su cuarta columna, el catálogo se
migra, y una migración de datos renombra las claves y calcula el `nivel` de lo
ya migrado. La prueba `test_las_claves_son_las_que_lee_el_motor` fija el
contrato entre las dos mitades.

---

## 6. Lo que queda por comparar

Honestamente, esto cubre el plan, su flujo de firmas y el cálculo de los cuatro
formatos. **No** se ha comparado todavía, y hasta que se haga no se puede dar
por portado:

- [ ] `plan_exports_controller` — la exportación a ZIP y los PDF por formato.
- [ ] `settings.num_doc_minimum` por país — aquí está fijo en 8 (Perú), en
      `WorkPlanSetupController::MINIMO_DOCUMENTO`. **Ojo: la v1 lo siembra en 7
      para los siete países**, así que el mínimo de aquí es más estricto que el
      de allá y puede estar rechazando documentos válidos.
