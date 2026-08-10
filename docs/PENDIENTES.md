# Pendientes y decisiones abiertas

Todo lo que quedó sin confirmar, sin terminar o marcado para mejorar. Nada se da por cerrado en
silencio.

## Preguntas abiertas: te las hice y siguen sin respuesta

Esta tabla existe porque en una conversación larga se pierde el hilo de qué se preguntó y qué se
contestó. **Mientras algo esté aquí, NO está hecho** — y lo que sí se hizo asumiendo una respuesta
lo dice explícitamente.

| Asunto | Qué hace falta decidir | Qué pasa mientras tanto |
| --- | --- | --- |
| **`Brand` y la familia `Customer`** | ¿Se borran o se dejan desactivados? Ninguna columna del dominio de DOCUFIZ apunta a `brands`, no existe equivalente en la v1, y `Customer` + sus ubicaciones/áreas/subestaciones es una isla del producto de diagnóstico de transformadores del que se clonó esto | Siguen registrados como módulos activos y aparecen en el menú |
| **Planes cerrados** | Un plan cerrado ¿congela su lista de documentos, o se corrigen los cuatro mensajes de la pantalla que hoy dicen cosas distintas? | Reservado para la sesión de Planes, que dijiste que haríamos juntos |
| **Personas por workspace** | Empresas quedó estricto por workspace («cada workspace tiene sus propias empresas»). ¿Personas igual, o una persona puede trabajar para contratistas de workspaces distintos? | Hoy es por workspace, heredado; sin confirmar |
| **150 416 observaciones del histórico de EPP** | ¿Se migran, se resumen o se quedan en la v1? | No se migran |
| **Herramientas y Comentarios** | Los dos módulos están muertos: `CommentController::TYPES` está vacío y los permisos no gatean nada | Siguen en el menú sin hacer nada |
| **Estado `archived` de los formatos** | No se llega a él desde ninguna pantalla, y `nuevaVersion()` es código que nadie llama | Código muerto |
| **`requires_signature` de un formato** | Existe en la base y no hay forma de marcarlo desde la interfaz | Siempre falso |
| **Editor de campos de un formato** | Sin pantalla para definir los campos, un formato estructurado o híbrido no se puede publicar nunca | El módulo de plantillas no se puede cerrar |

## Lo que depende de ti, fuera del código

| Qué | Por qué | Cómo |
| --- | --- | --- |
| **Poner `APIS_NET_PE_TOKEN` en el `.env`** | Sin él no se consultan ni el RUC en SUNAT ni el DNI en RENIEC. No falla nada: todo se escribe a mano | Es el mismo token que la v1 guardaba en las credenciales de Rails como `pe_reniec_token` |
| **Borrar `users.real_password` de la v1** | Hay 20 contraseñas guardadas en texto plano | `UPDATE users SET real_password = NULL;` en la base vieja |
| **Copiar los archivos de firmas y fotos** | 3 801 evidencias apuntan hoy a rutas con `byte_size = 0` | `php artisan docufiz:migrate-data archivos --desde=…` |
| **Probar el ingreso con un usuario migrado real** | Los 26 correos son provisionales (`usuarioN@pendiente.local`) | Antes de abrir el sistema |

## Para revisar a mano tras la migración

**Los 13 documentos con nombres distintos entre las tablas del sistema viejo: cerrado.** Se
corrigieron uno por uno en la base maestra de la v1 (agosto de 2026) y el listado de conflictos ya
devuelve cero. Conviene saber por qué hubo que hacerlo a mano: la regla de «me quedo con el nombre
más largo» solo resolvía bien 2 de los 13. Cuando los dos nombres miden lo mismo —que es justo lo
que pasa con las tildes, `fernández` y `fernandez` tienen las mismas letras— ganaba el primero por
orden de tabla, o sea al azar. Y en dos casos elegía lo peor: el que tenía seis espacios seguidos y
uno donde ninguna de las dos versiones era un nombre.

**Aviso para cualquier comprobación de documentos:** un DNI peruano **puede empezar por cero**
(`06842865` es válido y real). Una consulta que use `^[1-9][0-9]{7}$` para «buscar los que no son
DNI» devuelve falsos positivos; lo correcto es `^[0-9]{8}$`.

## Necesito que lo confirmes

| # | Asunto | Estado |
| --- | --- | --- |
| 1 | **Jerarquía duplicada.** TRAFODEX trae `Customer → ubicación → área → subestación`. El dominio nuevo trae `companies` + `work_locations` + `work_areas` + `workstations`. Se solapan | conservé las dos: la de TRAFODEX está entretejida con usuarios, roles, buscador y bloqueo de registros, y borrarla ahora rompía el núcleo. Hay que unificarlas |
| 2 | **`Brand`** (marca de transformador) sigue en el proyecto porque es la plantilla que clona `make:module`. Borrarla deja el generador inservible | ¿lo renombramos a algo neutro tipo `Sample`/`Plantilla` o lo dejamos oculto? |
| 3 | **`Customer`**: ¿es la empresa contratista de DOCUFIZ, o el cliente del SaaS y las contratistas van en `companies`? | hoy conviven las dos cosas |
| 4 | ~~**Usuarios**: ¿26 o 29?~~ | **cerrado: son 26.** `user_details` tiene 26 filas con `user_id` del 1 al 26, y solo tres de ellos aparecen registrando planes (el usuario 2 registró 3 715 de los 3 722) |
| 5 | ~~**Contraseñas**~~ | **cerrado por los hechos: no hay hashes que migrar.** La tabla `users` vino vacía del volcado, así que los 26 se reconstruyeron desde `user_details` con contraseña aleatoria. Falta decidir cómo entran la primera vez (ver abajo) |
| 6 | **Suscripciones y planes de facturación** de TRAFODEX se conservaron | ¿DOCUFIZ se vende como SaaS a varias empresas o es instalación única? |
| 7 | ~~**Formatos AST y PTF fuera del motor por volumen**~~ | **cerrado: entraron los cuatro.** Las 431 098 filas de la v1 se convierten en 48 522 respuestas, porque lo que allí era una fila por casilla marcada aquí es una respuesta por trabajador, herramienta o peligro. No hizo falta la excepción |
| 9 | **Correos de los 26 usuarios**: son provisionales (`usuarioN@pendiente.local`) | **hay que reemplazarlos por los reales antes de abrir el sistema**, o nadie puede entrar |
| 10 | **Primer ingreso**: `users` no tiene columna para forzar el cambio de contraseña | hoy la vía es «olvidé mi contraseña» una vez puestos los correos reales. ¿Añadimos la columna o basta con eso? |
| 11 | **Rol de los usuarios migrados**: todos entraron como «Usuario de campo», el de menos privilegios | hay que asignar los reales; el origen no traía esa información |
| 8 | **Multi-país**: el 100 % de los planes del sistema viejo son de Perú | se mantiene la estructura, sin invertir más |

## Decisiones tomadas que conviene recordar

| Asunto | Decisión |
| --- | --- |
| Empresas | **por workspace**: cada workspace tiene las suyas, no hay catálogo compartido |
| Roles | `super` = administrador del SaaS, `admin` = administrador de su workspace, y los perfiles con permisos a medida. El super puede **entrar** en un workspace y mientras está dentro se comporta como su admin |
| DNI | **8 dígitos exactos y solo cifras**. Se había dejado en 7 por dos peruanos del volcado; al repasar la base maestra no queda ninguno |
| Reglas del número de documento | van en el **catálogo de tipos de documento**, por tipo y por país: cuántos caracteres y cuáles. No escritas en el código |
| Nombres de personas | se escriben en **Mayúsculas y minúsculas** (`José Luis Benavides`), no todo en mayúsculas |
| Consulta a RENIEC | se recupera la de la v1 (apis.net.pe). Si encuentra el DNI, rellena el nombre y lo bloquea; si no, se escribe a mano. **Nunca bloquea el alta** |
| Umbral facial | arranca en 0,50 (el de tenkofiz) y se ajusta con las distancias reales que registre el sistema, no por corazonada |
| Firma por tiempo de espera | no bloquea el trabajo: firma, guarda la foto y queda pendiente de revisión |
| Evidencias de firma | no se purgan a los 90 días como en tenkofiz: son documentación legal |
| Motor de formatos | se usa para los formatos nuevos; los históricos se evalúan después |
| Trabajo en campo | sigue en navegador (tablets Android); sin app nativa ni API por ahora |

## Defectos heredados que siguen abiertos

Salieron al poner la documentación al día. Ninguno impide trabajar, pero conviene no olvidarlos:

| Qué | Dónde |
| --- | --- |
| `CommentController::TYPES` está **vacío**: los comentarios no se pueden usar en ningún módulo, y los permisos `comments.*` no gatean nada | `app/Http/Controllers/.../CommentController.php` |
| El seeder de roles asigna a tres usuarios de demostración perfiles con nombres que ya no existen; se saltan en silencio | `RolesAndPermissionsSeeder` |
| ~~`export` e `import` gateados por `.view`~~ — **corregido**: los cuatro piden `.export`. Y en personas el fichero salía además con el documento en crudo, saltándose el enmascarado de la pantalla; ahora se tapa en `buildQuery()`, que es por donde pasan los cuatro formatos | `routes/business_management.php` |
| `edit_all` y `audit_log_view` están declarados como características de plan pero **ninguna ruta las comprueba**: solo se esconde el botón | `config/features.php` |
| Tres ajustes heredados sembrados que nadie lee (`fleet_report.pdf_max_transformers`, `reports.frozen_retention_years`, `diagnostics.cell_alert_sev`) | `SettingsSeeder` |
| El freno del login es por **email + IP**: para la misma contraseña repartida entre muchos correos no sirve. La defensa real sería 2FA, que no hay | `LoginController` |
| `docs/brain/`, `docs/manual-usuario.html` y `docs/mockups/` no se revisaron: probablemente sigan describiendo transformadores | `docs/` |

## Lo que la migración dejó a la vista

**El 89 % de las firmas de cinco años no tiene prueba detrás.** De las 30 695 referencias a fotos y
firmas del sistema anterior, 27 000 son literalmente las cadenas `detected_by_IA` y `signed_by_IA`:
el archivo nunca se escribió. Solo 3 797 tienen algo real. Esas firmas se migran marcadas con
`evidence_missing`, porque inventar un archivo que no existió sería peor que reconocer el hueco.

Es exactamente el problema que motivó el rediseño, y ahora está medido: por eso en DOCUFIZ la foto
se guarda **siempre** y la comparación la hace el servidor.

**El riesgo del AST se calculaba mal en la primera versión del motor.** Se puso
`severidad × probabilidad`, y la matriz real de la v1 es un ranking del 1 al 25 donde el 1 es lo
peor. Doce de las veinticinco celdas caían en banda distinta. Corregido: `docufiz:migrate-formats`
copia la tabla real. **Si alguien llenó un AST con la versión anterior del motor, hay que
recalcularlo.**

**Llenar un formato no funcionaba.** `FormSubmission` era el único modelo sin `getRouteKeyName()`,
así que sus rutas resolvían por id mientras la pantalla mandaba el slug. Guardar respuestas,
adjuntar la foto y cerrar el formato fallaban los tres, y de paso el id correlativo sí funcionaba,
o sea que contando se podía confirmar la entrega de otro. Corregido y con prueba.

## Trabajo técnico pendiente

1. ~~Adaptar `CLAUDE.md`~~ — hecho.
2. Revisar los documentos heredados en `docs/` que aún hablan de transformadores
   (`ARCHITECTURE.md`, `MANUAL-CLIENTE.md`, `PERMISSIONS.md`, `FRONTEND.md`…).
3. ~~`npm install` y compilar el front~~ — hecho, compila sin errores.
4. ~~Menú lateral y traducciones~~ — limpiados; faltan las entradas de los módulos nuevos,
   que se añaden cuando cada módulo exista (una entrada a una ruta inexistente rompe la página).
5. ~~Seeder de roles y permisos~~ — reescrito con los módulos y perfiles de DOCUFIZ.
6. ~~Tests: los del dominio viejo se borraron; faltan los del dominio nuevo.~~ — hecho: **603 pruebas
   en verde, 0 rojas**. Cubren firma y evidencia, tipos de campo compuestos, PDF, enlace de rutas,
   buscador y las transformaciones de la migración.
7. Flujo de aprobación de documentos: el de TRAFODEX se borró con los informes de diagnóstico.
   La estructura ya está (`approval_rules` + `work_plan_approvals`), falta la pantalla.
10. ~~Los módulos `Companies`, `People` y `WorkPlans` se generaron clonando `Brand`~~ — hecho, y era
    peor de lo que parecía: el listado de planes mostraba 366 páginas de filas en blanco, crear una
    empresa devolvía un 500 (`StoreCompanyRequest` validaba el RUC contra una columna `code` que no
    existe), el filtro de documento nunca se aplicaba, y el importador de personas emparejaba por
    nombre, fusionando homónimos. Todo corregido y comprobado en un navegador real.
11. `DocufizDemoSeeder` no está en `DatabaseSeeder`: se ejecuta a mano con
    `php artisan db:seed --class=DocufizDemoSeeder`. Decidir si entra en el sembrado por defecto.
8. Índices únicos que faltan en el sistema viejo: hay que resolver antes el duplicado `47019239`.
9. **Copiar los 4 027 archivos de `public/images_uploads` del servidor viejo.** El comando
   `docufiz:migrate-data archivos --desde=…` está escrito y probado: pesa cada fichero y calcula su
   hash de verdad. Hasta que se copien, esas 3 797 evidencias apuntan a una ruta con `byte_size = 0`.
12. **PDF a nivel de plan**: hoy el PDF es por entrega, y cada uno repite el bloque de firmas del
    plan entero. Si se quiere un solo documento con los cuatro formatos, falta hacerlo.
13. **Adjuntos que no son imagen** (un PDF escaneado): dompdf no puede incrustar un PDF dentro de
    otro, así que sale una nota con su hash. Si eso no vale, hace falta fusionar PDFs aparte.
14. **`form_templates.pdf_template`** (plantilla propia para formatos con diseño fijo por ley) existe
    en la base pero no se usa: todos salen con el mismo diseño genérico.
15. **Editor de formatos desde la interfaz**: el servicio está (`FormTemplateBuilder`), falta la
    pantalla para crear un formato sin tocar código.
16. **Probar la cámara en una tablet real.** No es verificable desde aquí, y es lo único del flujo
    que nadie ha visto funcionar sobre el hardware de obra.

## Hallazgos de esta tanda

- El `.git/config` del repositorio se corrompió durante `composer install` (dejó `origin` apuntando
  a `symfony/routing`). Se reparó, pero conviene revisarlo si vuelve a fallar un `push`.
- `resources/js/Utils` se importaba como `@/utils` en dos archivos: funcionaba en macOS y falla en
  Linux por mayúsculas. Corregido.
- El seeder de roles usaba `updateOrCreate` con `tenant_id => null`, que nunca casa en SQL y
  duplicaba filas. Es un defecto heredado de TRAFODEX: sigue ahí para los roles del núcleo.

## Corregido sobre la marcha

- **La purga se llevó por delante dos archivos de rutas enteros.** `routes/system_management.php`
  pasó de 289 líneas a 14 y `routes/api.php` de 92 a 34, porque el grupo exterior mencionaba un
  controlador borrado y mi script eliminaba la sentencia completa. Resultado: 167 rutas dejaban de
  existir, y `route('report.verify')` en la pantalla de login lanzaba un error de Ziggy que dejaba
  **toda la aplicación en blanco**. Restaurados desde git y recortados solo en las rutas muertas.
- El panel de flota del dashboard y el payload de aprobaciones seguían consultando modelos borrados.
  El primero se reemplazó por el panel del día de DOCUFIZ; el segundo devuelve vacío.
- Se retiraron los últimos enlaces al dominio viejo en el buscador global, la ficha de cliente y los
  atajos de teclado.

**Lección para la siguiente purga**: borrar por marcador de sección es seguro; borrar sentencias
`Route::` que *contengan* un texto no lo es, porque un grupo entero es una sola sentencia.


- `evidence_files.sha256` estaba declarado único, lo cual contradecía la propia deduplicación: si un
  archivo se guarda una vez y lo referencian varios eventos, el hash **se repite**. Ahora el hash va
  indexado y lo único es el par (evento, tipo).

## Nota operativa

`make:module` consulta la base al registrar el módulo en `system_modules`. Si PostgreSQL no está
levantado, el comando **revierte todo lo generado** (lo hace bien: deja el proyecto limpio), pero hay
que volver a ejecutarlo con la base arriba.

- El nombre del producto vive en la tabla `settings` (`app.name`). El seeder ya dice DOCUFIZ, pero
  en una base ya sembrada la fila conserva el valor anterior: se cambia desde Configuración o
  volviendo a sembrar desde cero.

## Comparación con el sistema anterior (2026-08-08)

Se leyó el código Rails completo y se escribió `docs/COMPARACION-V1.md`, que dice
lógica por lógica qué hacía la v1, qué hace DOCUFIZ y si el cambio fue
deliberado. **Lo que queda por comparar está listado al final de ese documento**,
y hasta que se haga no se puede dar por portado:

- ~~Los cuatro formatos (`f1_document`…`f4_document`) tenían lógica propia por
  tabla~~ — **comparada**. El cálculo de `observations` está portado como
  `form_submissions.nonconformities` (`FormFindingsService`) y
  `sync_f3_document_workers` no hace falta: el campo compuesto recompone las
  filas contra la cuadrilla actual. Ver §4 de `COMPARACION-V1.md`.
  **Queda una decisión del dueño**: la v1 contaba como observación el «No
  aplica» en vez del «No conforme», y al recontar con la regla correcta salen
  150 416 observaciones en EPP porque los operarios usaron «No conforme» para
  las dos cosas (de 100 000 respuestas, «No aplica» no aparece ni una vez).
- ~~`plan_exports_controller`~~ — **portado** (`field_work.forms.zip`), con los
  cuatro formatos en vez de dos. Ver §4 de `COMPARACION-V1.md`.
- ~~`Plan#lock_plan_if_all_conditions_met`~~ — portado y ampliado
  (`WorkPlanCompletionService`).
- `must_have_at_least_one_document_and_worker`: la v1 no deja guardar un plan sin
  al menos un formato y un trabajador. Aquí sí se puede (sí se exige para
  **cerrarlo**).
- ~~`settings.num_doc_minimum` por país~~ — **portado** como el ajuste
  `docufiz.num_doc_minimum`, con 7 por defecto (lo que siembra la v1). Estaba
  fijo en 8 y por tanto era más estricto que el sistema anterior.

### Estado de la interfaz

Ningún módulo está cerrado al 100% contra el checklist de `docs/UI.md`. El plan
—ficha, flujo de firmas, formatos— sí está revisado **en un navegador con datos
reales**, que es el punto que más veces se había saltado. El resto de módulos
(personas, empresas, plantillas, catálogos de obra) siguen tal y como los dejó
`make:module` y no se han mirado en pantalla.
