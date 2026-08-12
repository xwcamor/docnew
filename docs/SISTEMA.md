# DOCUFIZ — el sistema entero, de punta a punta

Este documento explica **qué hace Docufiz y cómo encaja cada pieza con las demás**, en el orden en
que se usa: desde que el dueño del SaaS entrega un workspace con su administrador, hasta que un plan
de trabajo se cierra solo porque ya no le falta nada.

No es un manual de pantallas ni un tutorial de instalación. Es la explicación de **por qué el sistema
se comporta como se comporta**, con las reglas exactas y los casos límite. Donde una regla vive en un
sitio concreto del código, se cita el archivo.

- Manual para el usuario final → `docs/MANUAL-CLIENTE.md`
- Estándar de interfaz → `docs/UI.md`
- Puesta en marcha → `README.md`

---

## Índice

1. [El mapa: qué módulos hay y en qué orden se tocan](#1-el-mapa)
2. [Paso 0 — El dueño del SaaS entrega el workspace](#2-paso-0--el-dueño-del-saas-entrega-el-workspace)
3. [Usuarios y perfiles](#3-usuarios-y-perfiles)
4. [Ajustes del workspace — y la empresa propia](#4-ajustes-del-workspace--y-la-empresa-propia)
5. [Empresas](#5-empresas)
6. [Catálogos de obra](#6-catálogos-de-obra)
7. [Trabajadores](#7-trabajadores)
8. [El flujo de aprobación](#8-el-flujo-de-aprobación)
9. [Documentos (formatos)](#9-documentos-formatos)
10. [Tipos de trabajo — la matriz](#10-tipos-de-trabajo--la-matriz)
11. [El plan de trabajo](#11-el-plan-de-trabajo)
12. [Llenar un formato](#12-llenar-un-formato)
13. [Firmar](#13-firmar)
14. [El estado del plan: cuándo se cierra, cómo se reabre](#14-el-estado-del-plan)
15. [El expediente: PDF y ZIP](#15-el-expediente-pdf-y-zip)
16. [Cómo interactúan los campos con los módulos](#16-cómo-interactúan-los-campos-con-los-módulos)
17. [Reglas que sorprenden, y huecos conocidos](#17-reglas-que-sorprenden-y-huecos-conocidos)

---

## 1. El mapa

Docufiz documenta la seguridad del trabajo de campo. La unidad es el **plan de trabajo**: una tarea
de un día, con su cuadrilla, sus documentos de seguridad llenados y firmados, y sus aprobaciones.

Todo lo demás existe para que un plan pueda armarse en dos minutos desde una tablet en la puerta de
una subestación.

```
DUEÑO DEL SAAS  ──crea──►  WORKSPACE (tenant) + su ADMIN
                                    │
        ┌───────────────────────────┼───────────────────────────┐
        ▼                           ▼                           ▼
   USUARIOS y PERFILES        AJUSTES del workspace        CATÁLOGOS de obra
   (quién entra al            (mi empresa, membrete,       (sedes → puestos,
    sistema y qué ve)          firmantes del informe)       áreas, cargos)
                                    │
                                    ▼
                              EMPRESAS (contratistas)
                                    │
                                    ▼
                              TRABAJADORES (personas)
                              · documento = identidad
                              · vínculo con empresa + cargo
                              · roles de aprobador
                              · cara, firma y foto
                                    │
        ┌───────────────────────────┼───────────────────────────┐
        ▼                                                       ▼
   DOCUMENTOS (formatos)                              FLUJO DE APROBACIÓN
   · campos, secciones                                · qué firmas exige un plan
   · versiones, publicar                              · en qué orden
        │                                                       │
        └──────────────► TIPOS DE TRABAJO ◄────────────────────┘
                         (qué documentos exige
                          cada clase de maniobra)
                                    │
                                    ▼
                            PLAN DE TRABAJO
                    cuadrilla + documentos + aprobaciones
                                    │
                        ┌───────────┼───────────┐
                        ▼           ▼           ▼
                     LLENAR      FIRMAR      CERRAR
                                                │
                                                ▼
                                    EXPEDIENTE (PDF / ZIP)
```

**Regla de oro que atraviesa todo el sistema**: nada se copia, todo se resuelve en vivo. Un plan no
guarda una lista de documentos: pregunta a su tipo de trabajo cada vez que se abre. Por eso un cambio
en el catálogo alcanza hoy mismo a los planes abiertos, y por eso los planes cerrados se blindan
aparte (§11.4).

---

## 2. Paso 0 — El dueño del SaaS entrega el workspace

Un **workspace** (`tenant`) es un cliente. Solo el rol `super` —el dueño del SaaS— los crea, desde
Administración del sistema → Workspaces.

Al crear un workspace, en una sola transacción (`TenantService::create()`):

1. Se crea el workspace: nombre, logo, dirección, huso horario.
   - Si no se indica huso horario, se toma del país de quien lo crea; si tampoco, `UTC`.
2. **Se crea su usuario administrador**, y es obligatorio: nombre, correo y contraseña. Sin correo,
   la creación falla. Este usuario recibe el perfil global `admin`.
3. Se crea un *system user* interno (`api+…@system.local`) que es el dueño de los tokens de API. No
   aparece en el listado de usuarios y no recibe correos.
4. Si el plan elegido no es `free`, se abre una **prueba de 14 días**.

A partir de aquí el dueño del SaaS se puede retirar: el admin del workspace configura todo lo demás.

### Cómo se aísla un workspace de otro

Dos mecanismos, según el tipo de dato:

| Trait | Qué significa `tenant_id = NULL` | Modelos |
|---|---|---|
| `BelongsToTenant` | **De nadie.** No lo ve ningún workspace | `User`, `Company`, `Comment`, `Automation` |
| `BelongsToTenantOrGlobal` | **De todos.** Catálogo compartido que solo el super puede tocar | `Person`, `WorkPlan`, `WorkType`, `FormTemplate`, `Position`, `WorkLocation`, `Workstation`, `WorkArea`, `DocumentType`, `ApprovalRule`, `ApproverRole`, `Customer`, `Brand` |

Reglas que se derivan de eso:

- Al crear cualquier registro, el `tenant_id` **se fuerza** al del usuario. Un `tenant_id` enviado a
  mano en el formulario se ignora, salvo que quien lo mande sea super.
- Si al crear un registro `BelongsToTenant` no hay workspace resoluble, salta un error explícito
  («Entra primero en un workspace»). La única excepción es `User`, porque un usuario sin workspace es
  precisamente un super.
- Un admin **no puede editar ni borrar un registro global** del catálogo: salta una excepción de
  autorización. Puede crear el suyo propio al lado.
- **El super entra en un workspace** (`Workspaces → Entrar`) y a partir de ese momento ve y crea
  exactamente como el admin de ese cliente. Fuera de un workspace —consola, colas— lo ve todo.
- Los usuarios con rol `super` (y las cuentas `api`) **no salen en el listado de usuarios** de un
  cliente: `HideSuperScope` los oculta.

---

## 3. Usuarios y perfiles

### 3.1 Usuarios

Son las personas que **entran al sistema**. Ojo: son otra cosa que los **trabajadores** (§7). Un
trabajador de cuadrilla no necesita cuenta; firma con su cara desde la tablet del supervisor.

El alta de usuario pide nombre, correo, contraseña, país, idioma y perfil. Al guardar:

- Se comprueba el **tope de usuarios del plan** contratado. El super no pasa por ese tope.
- Se envía un correo de bienvenida **con la contraseña en claro** (si el envío de correo está
  habilitado en Ajustes del sistema), y una notificación en la campana, en el idioma del usuario.

**Estado**: un usuario `inactivo` no puede entrar, y si estaba dentro cuando lo desactivaron, el
middleware `EnsureUserActive` lo expulsa en la siguiente petición. En la desactivación masiva, uno no
se puede desactivar a sí mismo.

**Borrado**: exige un motivo escrito (mínimo 3 caracteres), pone el usuario inactivo y lo manda a la
papelera. Hay **60 segundos para deshacer**. La papelera, restaurar y el borrado definitivo son
**solo del super**. Un usuario restaurado vuelve *inactivo*: el borrado lo apagó y el restaurar no lo
vuelve a encender.

**Correos provisionales**: los usuarios que llegaron sin correo tienen uno `@pendiente.local`. Esas
cuentas **no pueden entrar ni recuperar contraseña**: hay que ponerles un correo real. El listado los
marca y se pueden filtrar.

### 3.2 Perfiles (roles) y permisos

Un **perfil** es un conjunto de permisos. Los hay de dos clases:

- **De sistema** (`super`, `admin`, `api`): globales, y **no se pueden editar, borrar ni eliminar
  definitivamente ni siendo super**.
- **Plantillas globales** que vienen sembradas y sí se pueden copiar y ajustar: *Supervisor de obra*,
  *Usuario de campo*, *Auditor HSE (solo lectura)*, *Soporte (editor)*.
- **Propios del workspace**: los que crea el admin. Dos clientes pueden tener perfiles con el mismo
  nombre sin chocar.

Los permisos siguen el patrón `modulo.accion`, con siete acciones canónicas: `view, show, create,
edit, delete, export, import`. Se generan solos cuando se da de alta un módulo del sistema.

Además hay **permisos transversales** que no siguen ese patrón porque no son un módulo:

| Permiso | Qué habilita |
|---|---|
| `form_submissions.sign` | firmar |
| `signature_events.review` | resolver la bandeja de firmas dudosas y autorizar una firma manual |
| `people.view_private_info` | ver el documento sin enmascarar, y descargar el PDF de los formatos |
| `people.view_media` | ver la foto y la firma de referencia de una persona |
| `comments.*` | comentarios |

Dos detalles que importan:

- **`people.view_media` es el único permiso que el `admin` NO recibe por defecto.** Hay que dárselo a
  mano si se quiere que vea las fotos y firmas guardadas.
- **`people.view_private_info` ignora el atajo del super.** Se comprueba con `hasPermissionTo()`, no
  con `can()`, así que ni el dueño del SaaS ve un documento completo si no tiene el permiso asignado.

**Quién puede crear perfiles**: solo `super` y `admin`, y el módulo entero está detrás de la feature
de plan `team_management` (planes *pro* y *enterprise*). Un admin no puede otorgar permisos de
`users` ni de `roles` a un perfil que cree: la gestión de accesos no se delega.

**Quién puede asignar qué perfil**: el super, cualquiera menos `super`. El admin, los suyos y los
globales, menos `super` y `api`.

Un perfil con usuarios asignados **no se puede borrar** (409).

---

## 4. Ajustes del workspace — y la empresa propia

En **Mi workspace** el admin configura, sin pasar por el dueño del SaaS:

- **Logo y membrete** — salen en la cabecera del PDF de cada formato.
- **Dirección** y **descargo de responsabilidad** de los informes.
- **Firmantes del informe** (hasta 8): los cargos que aparecen al pie del expediente.
- **Mi empresa** — la pieza importante.

### 4.1 «Mi empresa»: por qué existe y qué gobierna

El sistema necesita saber **cuál de las empresas del catálogo es la del propio cliente**, para poder
distinguir *«este es de los míos»* de *«este es de la contratista»*.

Se elige en Mi workspace. Si está vacía, el sistema **propone** la empresa cuyo nombre más se parezca
al del workspace (a partir de un 55% de parecido); propone, no guarda.

Y esto es lo que gobierna:

> **Solo a una persona de la empresa del workspace se le pueden asignar roles de aprobador.**

La cadena completa, que es la que responde a *«¿por qué esta persona no me sale para firmar?»*:

```
tenants.company_id  (Mi workspace → Mi empresa)
        │
        │  la ficha de la persona solo deja marcar «¿qué aprueba?»
        │  si su empresa es esta
        ▼
person_roles        (supervisor · supervisor HSE · los que definas)
        │
        │  la regla de aprobación del plan exige un rol concreto
        ▼
ApprovalRule.approver_role
        │
        │  el buscador de aprobadores filtra por ese rol…
        │  …y assignApprover() lo vuelve a comprobar en el servidor
        ▼
WorkPlanApproval.person_id
```

**Caso límite que conviene conocer**: si «Mi empresa» está vacía, esa primera puerta **no valida
nada** y cualquier persona activa puede recibir un rol de aprobador. Es una decisión deliberada —
bloquear altas por un ajuste que nadie sabe que existe sería peor— pero significa que dejar «Mi
empresa» sin marcar abre el flujo de aprobación a las contratistas.

En la ficha de la persona esto se ve en directo: al cambiar la empresa a una contratista, las casillas
de roles se limpian solas y aparece la explicación.

### 4.2 Ajustes del sistema (solo super)

Son **globales a la instalación**, no por workspace. Los que gobiernan el trabajo de campo:

| Ajuste | Por defecto | Qué hace |
|---|---|---|
| `docufiz.num_doc_minimum` | 7 | cuántos caracteres hay que teclear antes de que el buscador de personas conteste. Puesto a 0 se ignora y manda el 7: no hay forma de volcar el padrón |
| `docufiz.face_threshold` | 0.50 | umbral de reconocimiento facial. Se acota siempre a `[0.30, 0.65]` |
| `docufiz.face_timeout_seconds` | 20 | cuánto se intenta reconocer antes de capturar evidencia |
| `docufiz.face_liveness` | sí | pedir el reto de vida (girar la cabeza o asentir) |
| `docufiz.always_store_photo` | sí | guardar la foto incluso cuando el reconocimiento salió bien |
| `docufiz.sequential_approvals` | **no** | si se activa, una aprobación no se puede firmar mientras quede pendiente otra de nivel anterior |

### 4.3 Husos horarios

La hora que ve cada usuario se resuelve en cascada: **su huso → el del workspace → el de su país →
UTC**. Al cambiar el huso del workspace se refresca para todos sus usuarios.

Hay dos clases de fecha en el sistema y no se tratan igual:

- **Lo que escribió una persona** (inicio y fin del trabajo): es hora de obra, se guarda y se enseña
  literal, sin convertir.
- **Lo que estampó el servidor** (una firma, una confirmación): es un instante real, y se convierte
  al huso de quien mira.

---

## 5. Empresas

Son las **contratistas**, más la propia (§4.1).

- Identidad: **país + número de documento**. En Perú es el RUC; el catálogo trae el tipo por país
  (RUT en Chile, NIT en Colombia, CNPJ en Brasil, CUIT en Argentina, RFC en México…).
- El número se **normaliza siempre**: se quitan espacios y guiones al guardar, al importar y también
  al buscar. Buscar `20-5123 45678` encuentra a `20512345678`.
- La forma del número la valida el tipo de documento del país: longitud mínima, máxima y qué
  caracteres admite. Si el país no tiene tipos sembrados, no se valida la forma.
- El **nombre** también es único dentro del workspace, sin distinguir mayúsculas ni tildes.
- Hay **consulta a SUNAT** por RUC si hay token configurado; si no responde, se escribe a mano. Nunca
  bloquea el alta.

**Borrar una empresa**: no se puede si tiene planes de trabajo o personas vinculadas. La salida que
ofrece el sistema es **desactivarla**: una empresa inactiva deja de ofrecerse en los selectores, pero
sus planes y su gente siguen intactos.

---

## 6. Catálogos de obra

Se configuran una vez y alimentan el formulario del plan.

```
País
 ├── Sede (WorkLocation)
 │     └── Puesto de trabajo (Workstation)     ← cuelga de la SEDE, no del país
 ├── Área de trabajo (WorkArea)
 ├── Cargo (Position)
 └── Tipo de documento (DocumentType)          ← con scope: «persona» o «empresa»
```

- **Puesto** es el único que cuelga de la sede: al elegir la sede en el plan, el desplegable de
  puestos se filtra solo.
- **Cargo** es el puesto que ocupa una persona («Técnico electricista»). No tiene columna `name`: el
  nombre del cargo es su `code`.
- **Tipo de documento** guarda longitudes y qué caracteres admite, y una marca `for_foreigners`. Esa
  marca es la que responde a *«¿es extranjero?»*: ya no hay campo nacionalidad, lo dice el documento
  (CE, PTP o pasaporte en Perú).

Un catálogo con uso **no se borra**, se desactiva. Un cargo asignado a alguien, una sede con planes,
un país con personas: todos bloquean el borrado y proponen desactivar.

---

## 7. Trabajadores

Son las **personas de obra**. No son usuarios del sistema (§3.1).

### 7.1 La identidad es el documento, no el nombre

Esta es la decisión de fondo del módulo. Una persona es única por **(workspace, país, tipo de
documento, número)**. El nombre no identifica a nadie.

Por eso el alta —y sobre todo el alta desde el plan— **empieza buscando por documento**:

```
¿Existe alguien con ese documento en este país?
 ├─ No           → se crea la identidad
 ├─ Sí           → NO se crea otra: se le añade el vínculo con la empresa
 └─ Sí, borrada  → se avisa de que está de baja, con su nombre
```

Un trabajador que rota de contratista **conserva su identidad, su biometría y su firma**. Es lo que
hace que el segundo año de uso sea más rápido que el primero.

### 7.2 Empresa y cargo: el vínculo

Una persona no tiene columna `company_id`. Tiene **vínculos** (`person_company_link`): una fila por
cada empresa en la que ha estado, con su cargo.

- El formulario pide empresa y cargo, y **los dos son obligatorios**: sin vínculo la persona no puede
  entrar en ningún plan.
- El formulario **solo edita el vínculo de la empresa elegida**. Los demás se conservan.
- En la ficha del plan se enseña **el vínculo con la empresa de ese plan**, no cualquiera: el cargo
  que sale al lado del nombre es el que tiene esa persona *en esa contratista*.
- En el listado de personas, en cambio, se ordena por la primera empresa alfabéticamente.

> **Hueco conocido**: las columnas `started_on`, `ended_on` e `is_active` del vínculo existen pero
> **hoy no las escribe nadie desde la aplicación**. No hay forma de cerrar un vínculo: nadie deja
> nunca una contratista. Está anotado como pendiente.

### 7.3 Roles de aprobador

Una persona nace **sin ningún rol**. Los roles se marcan en su ficha y solo si es de la empresa del
workspace (§4.1). El catálogo de roles (`ApproverRole`) es configurable: hoy trae *supervisor* y
*supervisor HSE*.

Al quitar un rol **no se borra, se desactiva**: una firma vieja apunta al rol con el que se firmó, y
esa historia no se reescribe.

> El rol `worker` existía y **se eliminó del catálogo**: el responsable de la cuadrilla dejó de ser
> una aprobación para convertirse en una columna del plan (§11.3).

### 7.4 Cara, firma y foto

Tres cosas distintas que se guardan por separado:

| Qué | Dónde | Cómo se obtiene |
|---|---|---|
| **Biometría facial** | `person_biometrics` | 3 muestras de 128 números. **No se guarda ninguna foto**: solo los números con los que después se compara. Exige consentimiento |
| **Firma de referencia** | `person_signatures` | el trazo en el lienzo, la primera vez que la persona firma. PNG con transparencia, sin comprimir |
| **Foto de referencia** | `person_photos` | la sube un administrador, o **se adopta la primera captura de obra** si no había ninguna |

Las tres están **versionadas**: al guardar una nueva, la anterior no se pisa — se cierra con
`valid_to` y se conserva. Un documento firmado hace un año sigue apuntando a la firma con la que se
firmó. Si el archivo nuevo es idéntico al vigente (mismo hash), no se crea versión.

Los archivos viven en disco privado, fuera de `public/`, y se sirven autenticados. Ver foto o firma
exige `people.view_media`.

### 7.5 Privacidad del documento

El número de documento **nunca se serializa completo** hacia el navegador. Sale enmascarado
(`*******78`, los dos últimos visibles) salvo que el usuario tenga `people.view_private_info`.

Y se aplica en todas partes, no solo en la ficha: listado, papelera, buscador de la cuadrilla,
pantalla de firma, historial de auditoría, exportaciones (donde el trabajo corre sin sesión y el
permiso se resuelve contra el usuario que la encargó) y hasta la confirmación de borrado definitivo,
que se compara contra el documento enmascarado.

Quien no puede verlo **tampoco lo reescribe**: el campo se bloquea y el servidor repone país, tipo y
número desde la base antes de validar.

### 7.6 Importar

Personas y empresas se importan desde `.xlsx` o `.csv`, con plantilla descargable y **dos fases**:
primero una previsualización que no toca nada, y después la confirmación.

Lo que conviene saber:

- La identidad al importar personas es **(país del usuario, tipo, número)**. Nunca el nombre. En
  empresas, en cambio, el cruce es **por nombre**.
- **El cero que Excel se come**: si la celda llegó como *número*, el tipo de documento es solo
  dígitos y tiene longitud mínima, se rellena con ceros a la izquierda — y se avisa en la
  previsualización. Un documento escrito como texto no se toca.
- Empresa y cargo son **obligatorios en las altas**; en las actualizaciones, dejarlos en blanco
  conserva lo que había.
- Se saltan, y se cuentan aparte con su motivo: las filas globales (si quien importa no es super) y
  las bloqueadas con candado.
- El importador **no enrola caras ni carga firmas**, y no gestiona el estado activo/inactivo.

---

## 8. El flujo de aprobación

Es **configuración, no código**: añadir una cuarta firma a un procedimiento es una fila más.

Una **regla de aprobación** (`ApprovalRule`) dice: *«en este país, para este tipo de trabajo, hace
falta la firma de alguien con este rol, en esta posición del orden, y es obligatoria o no»*.

| Campo | Qué significa |
|---|---|
| `name` | cómo se llama esa firma en obra: «Supervisor Autorizante — HITACHI». No es el rol genérico |
| `country_id` | las reglas son por país: cada país tiene su procedimiento |
| `work_type_id` | en nulo, vale para todos los tipos de trabajo |
| `approver_role` | qué clase de persona puede firmarla |
| `priority_level` | el orden en que se piden |
| `is_required` | si el plan puede cerrarse sin ella |

**Cómo se eligen las reglas de un plan** (`ApprovalRule::paraPlan()`): manda la más específica. Si
hay reglas para el tipo de trabajo del plan, se usan **solo esas**; si no hay ninguna, se usan las del
país sin tipo. Así, acotar un tipo de trabajo no obliga a repetir el flujo general en cada uno.

**El orden se muestra siempre; se impone solo si se pide.** Por defecto, las aprobaciones se listan
por `priority_level` pero se pueden firmar en cualquier orden. Con el ajuste
`docufiz.sequential_approvals` activado, el servidor rechaza una firma mientras quede pendiente una
obligatoria de nivel anterior.

**Lo que sí es incondicional**: ninguna aprobación se puede firmar mientras el plan no tenga
**representante de la cuadrilla** (§11.3). En la pantalla salen en gris, con el motivo escrito una
sola vez y un botón que lleva a designarlo.

**Las aprobaciones no se borran.** No existe endpoint para ello, a propósito: la aprobación pertenece
al flujo del país, no al plan. Quitar la fila no quitaría la obligación, solo la escondería.

---

## 9. Documentos (formatos)

Un «documento» es un formato de seguridad: AST, PTF, EPP, IHM, y los que la empresa defina. **No hay
ninguno cableado en el código**: todos son datos.

### 9.1 Las tres clases

| Clase (`kind`) | Para qué | ¿Necesita campos? |
|---|---|---|
| `structured` | AST, PTF, EPP, IHM: campos, secciones, matriz de riesgo, checklists | **Sí**, al menos uno |
| `upload_only` | «la HOJA X»: el papel existe, solo se le toma una foto | **No.** Se publica vacío |
| `hybrid` | unos cuantos campos **más** el papel adjunto | **Sí**, al menos uno |

> Esto responde a la pregunta *«¿puedo crear un formato que solo diga subir documento?»*: sí, es
> `upload_only`. Se crea, se publica sin una sola sección, y en obra sale una zona de arrastre donde
> se sueltan las imágenes o el PDF. Con un archivo, ya se puede confirmar.

La clase **solo se puede cambiar mientras el documento está en borrador**.

### 9.2 Estructura: secciones y campos

Un documento tiene secciones, y cada sección campos. Cada campo tiene código, etiqueta (en castellano
y en inglés), tipo, si es obligatorio, posición y su configuración.

Los **17 tipos de campo**:

| Tipo | Control en obra | Configuración obligatoria |
|---|---|---|
| `text` | caja de texto | — |
| `textarea` | caja de varias líneas | — |
| `number` | número | — |
| `date` | selector de fecha | — |
| `time` | selector de hora | — |
| `select` | desplegable con búsqueda | `options` |
| `multiselect` | desplegable múltiple | `options` |
| `checkbox` | casilla | — |
| `radio` | grupo de opciones | `options` |
| `table` | *(sin control propio — ver §17)* | `columns` |
| `photo` | zona de arrastre, solo imágenes | `max_files` |
| `file` | zona de arrastre, imágenes y PDF | `max_files`, `mimes` |
| `signature` | lienzo de firma | — |
| `person_checklist` | **el EPP**: una fila por trabajador del plan | `items` |
| `tool_checklist` | **el IHM**: una fila por herramienta | `items` |
| `risk_matrix` | **el AST/PTF**: la matriz de riesgo | `severities`, `probabilities` |
| `question_bank` | banco de preguntas | `questions` |

### 9.3 Los cuatro campos compuestos

Reproducen lo que en el sistema anterior eran formatos enteros programados a mano.

**`risk_matrix` — la matriz de riesgo (AST, PTF)**
Una fila por peligro: actividad, peligro, riesgo (el texto de la consecuencia), control, severidad y
probabilidad. El **valor del riesgo no es severidad × probabilidad**: se lee de la tabla `matrix` de
la configuración (el ranking 1–25 donde 1 es lo peor), y solo si no hay tabla se cae al producto. El
nivel (alto / medio / bajo) sale de las bandas configuradas. Las filas se agrupan por actividad **solo
para presentarlas**; el dato guardado es plano. Si la pantalla es estrecha se pinta como tarjetas en
lugar de tabla.

**`person_checklist` — el EPP: de dónde salen los trabajadores**
Las filas **no las elige nadie: son los trabajadores del plan**. El servidor manda la cuadrilla actual
y el componente recompone las filas contra ella. Un trabajador **añadido al plan después de guardar
aparece con su fila vacía**, en vez de desaparecer. Cuando una fila sale no conforme, se despliegan
los campos de corrección.

**`tool_checklist` — el IHM**
La misma anatomía, pero la herramienta la escribe el usuario: no viene del plan.

**`question_bank`**
El único compuesto que **no va por filas**: una sola respuesta con la lista completa de preguntas.

**Regla común: el tono se deduce del texto, no de la posición.** «No aplica» / «N/A» se comprueba
*antes* que la regla genérica «empieza por no». Así, «No aplica» no cuenta como observación y «No
conforme» sí. La misma regla vive en el servidor y en el navegador, y hay una prueba que las compara.

### 9.4 Textos libres que aprenden

Cinco textos del sistema (actividad, peligro, riesgo, control y herramienta) admiten **cualquier
texto**, pero proponen lo ya escrito antes.

Cómo funciona: al abrir el formato, el servidor mira las **300 últimas entregas confirmadas** de ese
mismo documento (de cualquier versión), cuenta las apariciones de cada texto y sugiere las 100 más
frecuentes. El catálogo fijo que definió el administrador va primero; lo aprendido, detrás.

**No se persiste nada.** Es presentación pura: se calcula al abrir la pantalla. Y solo mira entregas
confirmadas — un borrador puede tener cualquier cosa a medio teclear.

### 9.5 Versiones y ciclo de vida

```
BORRADOR ──publicar──► PUBLICADO ──(publicar la v+1)──► ARCHIVADO
    ▲                       │
    └────despublicar────────┘
```

**Cada versión es una fila distinta.** La identidad de un documento a través de sus versiones es
**(workspace, país, código)**.

- **Nueva versión** duplica el documento entero —secciones y campos— con la versión siguiente, en
  borrador.
- **Publicar** archiva las versiones anteriores publicadas **y se lleva con ellas los tipos de
  trabajo**: el pivote apunta a una fila concreta, o sea a una versión concreta, así que publicar la
  v2 traslada a la v2 todos los tipos que exigían la v1. Sin ese traslado, publicar dejaría a los
  planes pidiendo la versión vieja.
- **Despublicar** es el inverso exacto: vuelve a borrador, resucita la predecesora archivada y le
  devuelve los tipos de trabajo.
- **Lo ya entregado no se toca.** Cada entrega guarda con qué versión se llenó, y el PDF reconstruye
  esa estructura.

**La estructura solo se edita en borrador y sin ninguna entrega.** Es lo que tapa el agujero de
«despublicar → quitar un campo → republicar», que se habría llevado las respuestas por delante.

**El listado enseña por defecto solo la versión vigente** de cada documento, y no filtra por estado:
borradores, publicados y archivados salen juntos. Hay un interruptor para ver todas las versiones.

---

## 10. Tipos de trabajo — la matriz

Un **tipo de trabajo** es la clase de maniobra («Estándar», «Izaje», «Trabajo en altura») y, con ella,
**los papeles que esa maniobra obliga a llenar**. Es lo que el supervisor elige al abrir un plan.

Los tipos son **por país**: solo se pueden exigir documentos del mismo país.

### 10.1 Los tres estados de un documento frente a un tipo

| Estado | En el plan |
|---|---|
| **No exigido** | no entra. Aparece en la lista del plan con el interruptor apagado, y se puede encender para ese plan suelto |
| **Exigido — opcional** | entra en el plan, y **se puede quitar** de un plan concreto si ese día no aplica. Pero si se deja puesto, **hay que llenarlo igual** para poder cerrar |
| **Exigido — obligatorio** | entra en el plan y **no se puede quitar**, ni siquiera de uno suelto. Es lo que evita que un trabajo en altura salga sin su AST porque alguien iba con prisa |

> Esto responde a *«¿qué diferencia hay entre exigido-opcional y no exigido?»*: **quién decide**. Un
> opcional se propone en todos los planes y hay que quitarlo activamente; un no-exigido no aparece y
> hay que ponerlo activamente. Y una vez dentro del plan, los dos pesan igual para cerrarlo.

### 10.2 El orden

Los documentos de un tipo de trabajo se **arrastran para ordenarlos** (o con Alt + flecha arriba /
abajo). Ese orden es el orden en que se piden en el plan.

### 10.3 El cambio llega en vivo

Al guardar la matriz se dice **a cuántos planes abiertos alcanza el cambio**, antes de guardar. Los
planes cerrados no se tocan nunca: su documentación ya está firmada.

Un documento que el tipo exige y que ya no está disponible (se desactivó, o el tipo cambió de país)
**se sigue enseñando** marcado como «no disponible». Si desapareciera de la lista, el siguiente
guardado dejaría de exigirlo sin que nadie lo hubiera decidido.

Un documento **en borrador no se puede exigir como obligatorio**: todavía no se puede llenar.

---

## 11. El plan de trabajo

### 11.1 Crearlo

El formulario pide: **descripción del trabajo, empresa, tipo de trabajo, sede, puesto, área y fecha
de inicio**. Son obligatorios. Son opcionales la **orden de servicio** (hay trabajos de emergencia
que no la tienen) y la **fecha de fin**.

El **código lo pone el sistema**: `PE26-0608-0001` = país + año + día y mes + correlativo del día.

Al guardar, el sistema:

1. Genera el código.
2. **Siembra las aprobaciones** que el flujo exige para ese país y ese tipo de trabajo — con la
   persona en blanco. La regla fija *que la firma hace falta*; quién firma como supervisor cambia
   cada día y lo elige quien arma el plan.
3. Lleva directamente a la **ficha del plan**, no al listado: el plan nace vacío y hay que armarlo.

**No se copia ningún documento.** Los documentos del plan se derivan en vivo del tipo de trabajo.

### 11.2 La cuadrilla

Se añade **buscando por documento**, nunca por nombre. En la puerta se escanea el DNI y la persona
entra sola:

```
Se teclea el documento
   │
   ├─ menos de 7 caracteres  → no se consulta nada (no hay forma de volcar el padrón)
   │
   ├─ coincidencia EXACTA y única  → se añade sola a la cuadrilla
   │
   ├─ hay parecidos, ninguno exacto → «sigue escribiendo»
   │
   └─ no hay nada  → aparece el botón «dar de alta»
```

> **Ese último caso es el que pediste recordar**: si no encuentra el documento, sale la opción de dar
> de alta al trabajador ahí mismo, sin salir del plan. Pide cuatro cosas —nombre, apellidos,
> documento y cargo— y el servidor pone el resto: **la empresa y el país los toma del plan**. La
> persona se crea y entra en la cuadrilla en el mismo gesto. El botón solo aparece cuando el servidor
> ya contestó que ese documento no existe, y solo si el usuario tiene permiso para crear personas.

**Quitar a alguien de la cuadrilla**: se puede, mientras no haya firmado. En cuanto firma, no — la
firma es la prueba de que estuvo y recibió la charla.

### 11.3 El representante de la cuadrilla

Es **quién responde por la cuadrilla**. No es una aprobación: es una columna del plan.

Por qué se sacó del flujo de aprobaciones:

- Nunca recogía una firma nueva: la evidencia válida es la que esa persona ya dio como trabajador.
  Pedirle otra haría parecer que hubo dos comprobaciones donde hubo una.
- Siendo una regla configurable, se podía borrar, y el plan se quedaba sin responsable sin que nadie
  lo notara.
- Su selector es otro: sale de **esta** cuadrilla, hoy, no del padrón entero.

Reglas: tiene que estar en la cuadrilla de este plan, y **tiene que haber firmado ya**. Y hasta que no
lo haya, **ninguna aprobación se puede firmar**.

### 11.4 Los documentos del plan

El conjunto se calcula cada vez que se abre la ficha. En orden:

1. **El estándar**: los documentos *publicados* que exige el tipo de trabajo, en su orden.
2. **Si el plan está cerrado**, del estándar solo sobreviven los que ya tienen entrega o los que se
   ajustaron a mano. *Es lo que evita que un documento añadido hoy al catálogo aparezca como
   pendiente en un plan firmado hace un año.*
3. **Exclusiones**: los opcionales que se quitaron de este plan se saltan. Los obligatorios del tipo
   **ignoran la exclusión**: no se pueden quitar.
4. **Un ajuste del plan puede subir la exigencia, nunca bajarla.**
5. **Extras**: documentos que no exige el tipo pero se encendieron para este plan.
6. **Red de seguridad**: cualquier documento que ya tenga entrega vuelve a la lista aunque el catálogo
   haya cambiado. Lo que ya se llenó no desaparece nunca de la ficha.

Quitar un documento de un plan solo se puede si **su entrega está vacía**: sin respuestas, sin
adjuntos, sin firmas y sin confirmar.

### 11.5 La ficha

Cabecera con el código, el estado y las acciones. Debajo, la tarjeta **«El trabajo»** (resumen o
ficha completa), la franja **«Qué falta»**, y un tablero de tres columnas:

```
┌─────────────────┬─────────────────┬─────────────────┐
│  REPRESENTANTE  │                 │                 │
│  (si ya lo hay) │   DOCUMENTOS    │  APROBACIONES   │
├─────────────────┤                 │  (si ya hay     │
│                 │  todo el        │   representante)│
│   CUADRILLA     │  catálogo, con  │                 │
│                 │  su interruptor │  …si no, aquí   │
│  firmados/total │  y su estado    │  va el          │
│                 │                 │  representante  │
└─────────────────┴─────────────────┴─────────────────┘
```

La columna de la derecha cambia a propósito: **sin representante no hay nada que firmar**, así que en
su lugar se pone la tarjeta que resuelve el bloqueo.

---

## 12. Llenar un formato

### 12.1 Un solo botón

No hay «guardar» y «confirmar» por separado. **«Guardar cambios» hace las dos cosas**:

- Si falta algo obligatorio → se guarda lo escrito, la entrega sigue en borrador, y se dice
  exactamente qué falta (con los campos marcados en rojo, pero **solo después del primer intento**).
- Si no falta nada → se confirma y se vuelve a la ficha del plan.

### 12.2 Qué se comprueba al confirmar

| Clase | Qué se exige |
|---|---|
| `structured` | todos los campos obligatorios respondidos |
| `upload_only` | **al menos un adjunto**, y nada más |
| `hybrid` | los campos obligatorios **más** al menos un adjunto |

Los campos de tipo `photo`, `file` y `signature` **no guardan su valor en las respuestas**: su valor
son los adjuntos. Cuentan como respondidos si tienen un archivo colgado.

**Confirmar no exige que el formato salga limpio.** Un EPP con una observación se confirma
perfectamente: encontrar un problema es el trabajo, no un error. Lo que bloquea el cierre del plan es
otra cosa (§14).

### 12.3 Cómo se guarda cada respuesta

Cada respuesta va **a la columna que le corresponde a su tipo**, no todo como texto:

| Tipo | Columna |
|---|---|
| `number` | `value_number` |
| `date`, `time` | `value_datetime` |
| `checkbox` | `value_boolean` |
| `multiselect`, `table` y los cuatro compuestos | `value_json` |
| el resto | `value_text` |

Un valor vacío **borra la fila** en vez de guardarla vacía. Ojo: `false` y `0` no son valores vacíos.

Los compuestos guardan **una respuesta por fila** (una por peligro, una por trabajador, una por
herramienta), con su índice. El banco de preguntas es la excepción: una sola respuesta con todo.

### 12.4 Adjuntos

- Se arrastran o se eligen. **Múltiples archivos a la vez**, hasta 20 por tanda, 8 MB cada uno.
- Solo **imágenes (jpg, png, webp) y PDF**. Un campo `photo` estrecha a solo imágenes; un campo `file`
  puede estrechar más con su `mimes`. Un campo nunca puede *ampliar* la lista general.
- `max_files` cuenta los que ya hay más el lote nuevo. Al llenarse el cupo, la zona de arrastre
  desaparece.
- **Se deduplican por hash**: la misma imagen no se guarda dos veces en disco. Al quitar un adjunto,
  el archivo solo se borra si no queda ninguna otra fila apuntando a él.
- Se recuerda **cómo se llamaba el archivo** cuando se subió.
- Viven en disco privado y se sirven autenticados con `form_submissions.view`.

La **firma dentro de un formato** reutiliza toda esta maquinaria: el trazo del lienzo se convierte en
PNG y se sube como un adjunto más de ese campo.

### 12.5 Reabrir un formato

Un formato confirmado se puede volver a abrir para corregirlo, con el mismo permiso que llenarlo.
Lo que protege el documento no es este permiso: es el **cierre del plan**. Con el plan cerrado no se
puede escribir nada, ni siquiera en una entrega que quedó en borrador.

---

## 13. Firmar

Es el momento delicado del sistema, y tiene tres reglas que no se negocian:

1. **La comparación la hace el servidor.** El navegador manda el descriptor y la foto; el servidor
   calcula la distancia contra la biometría enrolada, la guarda y decide.
2. **La foto se guarda siempre que no hubo reconocimiento.** Sin foto no hay evidencia de nada.
3. **Nunca se bloquea el trabajo en campo.** Si no reconoce, se firma igual y queda marcado para
   revisión.

### 13.1 El flujo

La pantalla se abre **sobre una sola persona**, desde la fila concreta de la ficha del plan.

```
La persona ya está identificada (se pulsó Firmar en su fila)
        │
        ▼
El servidor entrega SOLO los descriptores de esa persona   ← verificación 1:1
        │
        ├─ no tiene biometría → se enrola en el momento: 3 muestras, con consentimiento
        │
        ▼
La cámara compara en vivo y da feedback
        │
        ├─ reto de vida (girar la cabeza o asentir), si está activado
        │
        ├─ coincide (distancia ≤ umbral) ──────► firma verificada
        │                                        método: face_recognition
        │
        └─ se agota el tiempo sin coincidir ───► se captura la foto igual
                                                 método: timeout_capture
                                                 pendiente de revisión: sí
```

### 13.2 Cara y firma: cuándo se pide cada una

> Esto responde a *«para firmar se usa la cara y la firma; si ya hay firma, solo la cara»*. Es
> exactamente así:

- **La primera vez**: se pide el **trazo de la firma** en el lienzo **y** la cara. Sin trazo no se
  puede firmar, y el servidor lo vuelve a exigir aunque el navegador lo deje pasar.
- **A partir de ahí**: **solo la cara**. La firma guardada se reutiliza. Hay un botón «Actualizar mi
  firma» para cambiarla cuando haga falta.

### 13.3 Qué queda guardado

Por cada firma se registra: quién, sobre qué, cuándo, con qué **método**, si hubo reconocimiento, la
**distancia medida**, el **umbral aplicado**, si queda pendiente de revisión, la geolocalización, el
dispositivo, la IP y el navegador.

La foto se comprime a 320 px en WebP y **se deduplica**: si la misma persona firma tres cosas del
mismo plan el mismo día, se guarda una sola imagen y los tres eventos apuntan a ella.

Si la persona **no tenía foto de referencia**, la captura de obra se adopta como suya. Nunca pisa una
que subió un administrador.

### 13.4 La firma manual

Existe, pero **exige un motivo escrito**, deja constancia de quién la autorizó, y solo la puede
autorizar alguien con permiso de revisión.

### 13.5 La bandeja de revisión

Lista las firmas que quedaron pendientes, con la foto capturada, la distancia y el umbral. El
supervisor acepta o rechaza; **si rechaza, la aprobación se revierte**.

Las evidencias no son públicas: se sirven autenticadas, solo con permiso de revisión, y una evidencia
de otro workspace devuelve **404, no 403** — decir «no puedes» ya confirmaría que existe.

---

## 14. El estado del plan

Un plan tiene **tres estados**, y son los que dan las tres vistas del listado:

| Estado | Qué significa |
|---|---|
| **Pendiente** | se está trabajando en él |
| **Terminado** | se cerró solo porque ya no le faltaba nada |
| **Reabierto** | estaba terminado y alguien lo abrió para corregirlo |

### 14.1 Cuándo se cierra solo

El plan se cierra **solo**, sin que nadie pulse nada, cuando se cumplen **las cinco condiciones**:

1. **Hay hora de fin** del trabajo.
2. **Hay cuadrilla, y todos han firmado.**
3. **Hay representante** de la cuadrilla.
4. **Todos los documentos que el plan exige están confirmados.**
5. **Ninguna aprobación obligatoria sin firmar.**

Y una sexta que no es una condición de forma sino de fondo:

6. **Ninguna observación sin corregir.** La regla **no es «cero observaciones»** —eso sería una
   trampa: el día que encuentras un arnés roto ese plan no cerraría nunca, ni cambiando el arnés—
   sino *ninguna observación cuya corrección no esté verificada*. Encontrar un problema no atrapa el
   plan; no arreglarlo, sí.

La evaluación se dispara sola en cada firma, en cada formato confirmado, al designar representante y
al guardar el plan. Es idempotente y **no genera auditoría**: lo decide el sistema, no una persona.

La franja **«Qué falta»** de la ficha enseña esa lista en todo momento.

### 14.2 Reabrir

Un plan terminado se puede reabrir para corregirlo. Queda **quién y cuándo** — un plan terminado es
el documento que acaba delante de un inspector, y que alguien lo haya reabierto después es justo lo
que hay que poder explicar.

**Un plan reabierto no se vuelve a cerrar solo.** Sin esa marca, reabrir sería un botón inútil: las
condiciones se siguen cumpliendo —por eso estaba cerrado— así que el primer guardado lo cerraría otra
vez y expulsaría a quien entró a corregir.

Quien decide que ya terminó de corregir es **«Dar por terminado»**. Si todavía falta algo, no cierra
y lo dice, en vez de dejar el plan en un limbo silencioso.

### 14.3 Qué se bloquea con el plan cerrado

- **Toda** la composición: añadir o quitar trabajadores, documentos y aprobaciones, asignar
  aprobadores, designar representante.
- Editar el plan.
- Escribir en cualquier formato, incluidos los que quedaron en borrador.
- El plan deja de heredar novedades del catálogo (§11.4).

En pantalla **se enseña un solo control cada vez**: si el plan está terminado sale «Reabrir» y el
candado administrativo se esconde; si está en curso, sale el candado.

---

## 15. El expediente: PDF y ZIP

**El PDF de un formato** se genera al vuelo, no se guarda en disco. Lleva:

- El **membrete del workspace** con su logo.
- La cabecera del plan y la del formato, con la **versión** con la que se llenó.
- Las secciones y campos, con los compuestos pintados como tablas y las fotos incrustadas.
- **Las firmas**, con la foto de la cara de cada firmante, marcando las que quedaron pendientes.
- Los firmantes formales configurados en Mi workspace.

Dos detalles: el PDF sale **en el idioma del país del plan**, no en el del usuario que lo descarga. Y
usa **la versión congelada** de la plantilla: si el formato se llenó con la v1 y hoy va por la v3, se
imprime la v1.

**El ZIP del plan** junta el PDF de **todas las entregas confirmadas**. Si una revienta, no se entrega
el ZIP: mejor no darlo que darlo incompleto.

**Permisos**: descargar el PDF o el ZIP exige `form_submissions.export` **y** `people.view_private_info`,
porque el documento lleva las firmas y los documentos completos de toda la cuadrilla, sin enmascarar.
Consecuencia práctica: el *Supervisor de obra* y el *Auditor HSE* tienen el permiso de exportar pero
no el de datos privados, así que **no pueden descargar el PDF** salvo que se les conceda a mano.

---

## 16. Cómo interactúan los campos con los módulos

Esta es la tabla que explica **de dónde saca sus datos cada campo y a qué afecta lo que se escribe en
él**. Es lo que hace que el motor de formatos no sea un formulario suelto.

| Campo / dato | De dónde le llega | A qué afecta |
|---|---|---|
| `person_checklist` (EPP) | **la cuadrilla del plan**, en vivo. Añadir un trabajador al plan le añade su fila | cada fila no conforme suma una **observación**, y sin corrección verificada **bloquea el cierre del plan** |
| `tool_checklist` (IHM) | el catálogo de herramientas del campo **+ lo aprendido** de entregas anteriores. La herramienta la escribe el usuario | igual: observaciones y bloqueo del cierre |
| `risk_matrix` (AST/PTF) | actividad, peligro, riesgo y control se **autocompletan con lo escrito antes** en ese mismo documento | el nivel de riesgo sale de la tabla configurada, no de multiplicar. Suma observaciones si es no tolerable |
| `question_bank` | el catálogo de preguntas del campo | **no cuenta como observación**, a propósito |
| `photo`, `file`, `signature` | nada: los llena el usuario | su valor **son los adjuntos**, no una respuesta. Un `photo` obligatorio se satisface con un archivo |
| Adjunto del formato entero | solo existe en `upload_only` y `hybrid` | en `upload_only` es **lo único** que se exige para confirmar |
| **Empresa** del plan | catálogo de empresas | filtra el cargo que se enseña de cada trabajador (el vínculo de *esa* empresa), y es la empresa que se asigna al dar de alta a alguien desde el plan |
| **Tipo de trabajo** del plan | catálogo de tipos | **decide qué documentos se piden** y **qué reglas de aprobación se siembran** |
| **País** del plan | del usuario que lo crea | decide las reglas de aprobación, los tipos de documento del alta rápida, los cargos ofrecidos y **el idioma del PDF** |
| **Sede** del plan | catálogo de sedes | filtra los **puestos de trabajo** que se ofrecen |
| **Fecha de fin** | la escribe el supervisor | es **condición 1 de cierre**: sin ella el plan no se cierra nunca |
| **Documento** de la persona | lo teclea o escanea el supervisor | es **la identidad**: decide si se reutiliza a alguien o se crea. Y sale enmascarado en todas partes |
| **Roles** de la persona | solo asignables si es de la empresa del workspace | deciden **qué aprobaciones puede firmar** en cualquier plan |
| **Firma de un trabajador** | la cámara + el lienzo | marca su fila como firmada, **habilita designarlo representante** y dispara la evaluación de cierre |
| **Representante** | de entre los que ya firmaron | **desbloquea todas las aprobaciones** del plan |
| **Firma de una aprobación** | la cámara | marca la aprobación y dispara la evaluación de cierre |
| **Confirmar un formato** | el botón «Guardar cambios» | recuenta las observaciones y dispara la evaluación de cierre |
| **Publicar un documento** | el botón Publicar | archiva la versión anterior **y se lleva sus tipos de trabajo**, con lo que los planes abiertos empiezan a pedir la nueva |
| **La matriz de un tipo de trabajo** | el admin | alcanza **hoy mismo** a todos los planes abiertos de ese tipo. Los cerrados no se tocan |
| **«Mi empresa»** del workspace | Ajustes | decide **a quién se le pueden dar roles de aprobador** |

---

## 17. Reglas que sorprenden, y huecos conocidos

Cosas verdaderas del sistema tal y como está hoy. Las primeras son decisiones; las últimas, deuda
anotada.

### Decisiones deliberadas

- **Un documento opcional que se deja en el plan hay que llenarlo igual.** «Opcional» significa que se
  puede quitar, no que se pueda dejar a medias.
- **Confirmar un formato con observaciones se puede.** Lo que bloquea el cierre es la observación *sin
  corregir*, no la observación.
- **Las aprobaciones no se borran nunca.** Pertenecen al flujo del país, no al plan.
- **El buscador de personas no tiene desplegable.** En la puerta se escanea el DNI y la persona entra
  sola; un desplegable obligaría a teclear el documento entero *y además* elegir de una lista de un
  solo elemento.
- **`people.view_private_info` no lo hereda el super.** Es el único permiso que ignora el atajo.
- **`people.view_media` no lo tiene el admin por defecto.** Hay que concederlo.
- **Los planes migrados del sistema anterior conservan el estado con el que llegaron.** Se cerraron
  bajo la regla vieja (solo hora de fin y aprobaciones) y no se reabren; la regla nueva, más estricta,
  rige de aquí en adelante.

### Huecos anotados

- **El campo `table` no tiene control de llenado.** Se declara, el PDF lo pinta como tabla, pero en la
  pantalla de llenado cae a una caja de texto de una línea.
- **El IHM no tiene dónde escribir la verificación de la corrección.** El EPP sí. Como el cierre del
  plan exige ese campo, una fila de herramienta no conforme puede quedar bloqueando el cierre sin que
  haya forma de resolverla desde la pantalla.
- **Una matriz de riesgo no tolerable no bloquea el cierre**, aunque sí cuente como observación: la
  comprobación mira solo los checklists.
- **En `upload_only`, si el documento tuviera campos obligatorios, no se comprobarían.** La
  comprobación sale antes de mirarlos.
- **La empresa que se enseña de un trabajador en el listado es la primera alfabéticamente**, no la
  vigente. Y **no hay forma de cerrar un vínculo**: nadie deja nunca una contratista.
- **La coincidencia exacta del buscador compara la cadena cruda**: un documento tecleado con guiones
  no dispara el alta automática.
- **El alta rápida desde el plan valida menos que el formulario completo**: no comprueba el tipo de
  documento contra el catálogo del país ni la forma del número.
- **El PDF ignora las etiquetas bilingües de los campos y los nombres de sección**: usa el código
  humanizado y numera las secciones.
- **Duplicar una versión de un documento no copia los nombres en inglés** de secciones y campos.
- **El índice que impide dos filas con el mismo código y versión solo existe en PostgreSQL.**
- **`requires_signature` y `pdf_template` se guardan y hoy no deciden nada.**
- **Cinco componentes de interfaz no los monta nadie** (`CommentThread`, `AdvancedFilterDrawer`,
  `LazyMount`, `StatusBadge`, `AmGauge`), y tres librerías de gráficos (`ag-grid`, `amCharts`,
  `echarts`) están instaladas sin uso.
