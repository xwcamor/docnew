# Migración de datos y control del corte

Origen: la base del sistema anterior (MySQL 8, Rails). Destino: DOCUFIZ (PostgreSQL 16, Laravel).
Cifras del volcado del 06-08-2026, verificadas contra la base cargada.

## Cómo se controla

1. **Un comando, un paso**, idempotente: `php artisan docufiz:migrate-data {paso}`.
2. **Trazabilidad**: cada fila migrada guarda `legacy_id` (y `legacy_table` / `legacy_source` donde
   puede venir de varias tablas), así siempre se puede volver al origen.
3. **Conteos origen contra destino** en cada paso, impresos por el propio comando. Lo que se
   descarta se dice, con cuántas filas y por qué. Nada se trunca en silencio.
4. **La v1 solo se lee.** Ningún paso escribe en la base vieja: ni un `UPDATE`, ni una tabla
   temporal. La conexión `legacy` de `config/database.php` existe solo para leer.
5. **Ensayos completos** antes del corte: la migración se corre entera sobre una copia, se revisa y
   se descarta. Se repite hasta que salga limpia dos veces seguidas.
6. **Corte**: se congela la v1 (solo lectura), se corre la migración final, se verifica el checklist
   de corte y se abre la v2. La v1 queda accesible en solo lectura durante 3 meses.

## En local: un solo comando

```bash
php artisan migrate:fresh --seed
```

Deja la base **lista para trabajar**: esquema, catálogos, permisos, ajustes,
tipos de documento, usuarios — y los datos del sistema anterior si el MySQL
viejo está levantado. No hay seeders sueltos que recordar ni orden que
respetar; si se añade uno nuevo, va dentro de `DatabaseSeeder` y no en estas
instrucciones.

Si la base vieja no responde, se salta esa parte y lo dice por pantalla. No es
un error: es que no hay nada que traer, y la base queda sembrada pero sin
planes ni personas.

Los pasos sueltos de abajo son para producción y para volver a correr **una**
parte sin tocar el resto.

## Los pasos

```
php artisan docufiz:migrate-formats            # las plantillas AST, PTF, EPP e IHM
php artisan docufiz:migrate-data empresas
php artisan docufiz:migrate-data usuarios
php artisan docufiz:migrate-data personas
php artisan docufiz:migrate-data planes
php artisan docufiz:migrate-data documentos
php artisan docufiz:migrate-data evidencias
php artisan docufiz:migrate-data archivos --desde=/ruta/v1/public/images_uploads
php artisan docufiz:migrate-data todo           # todos en orden, menos archivos
```

`todo` respeta el orden: `usuarios` va antes que `planes` porque cada plan recuerda quién lo
registró, y `planes` antes que `documentos` y `evidencias` porque todo cuelga del plan.
`--lote=N` controla cuántas filas de la v1 se leen de una vez (500 por defecto).

| Paso | Origen → destino | Origen | Destino | Estado |
| --- | --- | ---: | ---: | --- |
| `empresas` | `companies` | 22 | 22 | hecho |
| `usuarios` | `user_details` → `users` | 26 | 26 | hecho |
| `personas` | `workers` + `supervisors` + `hse_supervisors` → `people` | 402 | 228 | hecho |
| `planes` | catálogos (`work_types`, `locations`, `areas`, `workstations`, `approval_rules`, `work_type_documents`) | 34 | 34 | hecho |
| `planes` | `plans` → `work_plans` | 3 722 | 3 722 | hecho |
| `planes` | `plan_workers` → `work_plan_people` | 9 186 | 9 186 | hecho |
| `planes` | `plan_approvals` → `work_plan_approvals` | 11 166 | 11 166 | hecho |
| `documentos` | `f1_documents` → entregas AST | 3 657 | 3 657 | hecho |
| `documentos` | `f2_documents` → entregas PTF | 3 662 | 3 662 | hecho |
| `documentos` | `f3_documents` → entregas EPP | 3 659 | 3 659 | hecho |
| `documentos` | `f4_documents` → entregas IHM | 3 457 | 3 457 | hecho |
| `evidencias` | firmas de trabajador → `signature_events` | 9 061 | 9 042 | hecho |
| `evidencias` | firmas de aprobación → `signature_events` | 8 238 | 8 234 | hecho |
| `archivos` | imágenes de `public/images_uploads` | 3 797 refs | — | **pendiente: faltan los ficheros** |

Los 14 435 formatos llenados producen **48 522 respuestas** en el motor. En la v1 esas mismas
respuestas eran 431 098 filas repartidas en once tablas (226 875 solo de EPP): lo que antes era una
fila por casilla marcada aquí es una respuesta por trabajador, por herramienta o por peligro, que es
la unidad con la que se lee el documento.

## Personas: la parte delicada

La misma persona está repartida en tres tablas y repetida por empresa:

- 391 filas de `workers` para **231 documentos distintos**
- 95 personas aparecen en 2 a 5 empresas
- 21 documentos coinciden entre `workers` y `supervisors`, 4 con `hse_supervisors`
- 1 duplicado exacto: dos filas con el mismo documento y la misma empresa

El script agrupa por `(país, tipo de documento, número)` y genera un informe de conflictos cuando
los nombres no coinciden exactamente. **Esos conflictos se resuelven a mano antes de continuar**;
el script no adivina.

## Usuarios: lo que no vino en el volcado

La tabla `users` de la v1 se excluyó del volcado a propósito porque llevaba contraseñas: tiene **0
filas**. Los 26 usuarios se reconstruyen desde `user_details`, que sí vino completa (26 filas,
`user_id` de 1 a 26). Consecuencias, todas visibles y todas pendientes de que el dueño las cierre:

- **Correo**: no hay ninguno en el origen. Se genera `usuarioN@pendiente.local` a partir del
  `legacy_id`, determinista y evidentemente provisional. **Hay que reemplazarlos por los reales
  antes de dar acceso**, o nadie podrá recuperar su contraseña.
- **Contraseña**: no hay ninguna que migrar. Se pone una aleatoria de 48 caracteres que no queda
  registrada en ningún sitio. `users` no tiene columna para forzar el cambio en el primer ingreso,
  así que el camino es "olvidé mi contraseña" una vez corregidos los correos.
- **Rol**: el origen no dice quién es qué. Se asigna el rol de menos privilegios
  (`Usuario de campo`) y **no se inventa ningún permiso**. Hay que revisarlos uno a uno.
- De los 26, solo 3 aparecen registrando planes (`plans.user_id` ∈ {2, 4, 21}; el usuario 2 registró
  3 715 de los 3 722). Los otros 23 se migran igual: existieron.

## Planes: los códigos repetidos

En la v1 el código de plan no era único: **3 526 códigos distintos para 3 722 planes**. En DOCUFIZ
el código es único por país y workspace (índice parcial sobre `UPPER(code)`), así que **196 planes
recibieron un sufijo** (`PE25-0807-0700-2`, `-3`, `-4`). El sufijo se calcula recorriendo el origen
por id, o sea que sale igual en cada pasada, y el código original siempre se recupera por el
`legacy_id`. El comando lo avisa por pantalla.

Otras decisiones del paso:

- Los catálogos son multipaís en la v1. Solo se migra el de Perú (`country_id = 1`), que es el que
  usan los 3 722 planes: 6 tipos de trabajo, 1 sede, 1 área, 16 puestos y 3 reglas de aprobación.
  Las filas de Brasil se quedan fuera a propósito.
- `work_type_documents` (36 filas) pasa a `work_type_form_templates`: qué formatos exige cada tipo
  de trabajo. Quedan 24 (las 12 restantes son de los tipos de Brasil o del formato F5, que nunca
  se usó).
- Los 69 planes marcados `is_deleted` llegan con `deleted_at`, no se pierden.
- `plan_documents` (14 687 filas, qué formatos se seleccionaron plan a plan) **no se migra**: en
  DOCUFIZ lo que un plan exige se deduce del tipo de trabajo, y lo que se llenó son las entregas.
  Es información redundante con `form_submissions`.

### Lo que el paso `planes` descarta

| Cuántas | Qué | Por qué |
| ---: | --- | --- |
| 4 | aprobaciones sin persona | 2 apuntan a supervisores que ya no existen en la v1 (ids 7 y 32) y 2 a trabajadores marcados como borrados, cuyo documento no tiene otra fila viva. La aprobación **sí se migra**, pero sin aprobador. |

Ningún plan, ninguna asignación de cuadrilla y ninguna aprobación se pierden: 3 722 / 9 186 / 11 166
entran enteros. Las **548 aprobaciones obligatorias sin firmar** de la v1 siguen sin firmar.

## Formatos llenados: cómo se traduce cada uno

La correspondencia entre las tablas de la v1 y los campos de la plantilla:

| v1 | DOCUFIZ | Forma de la respuesta |
| --- | --- | --- |
| `f1_document_activities` + `f1_document_dangers` | AST · `matriz_de_riesgo` | una fila (`row_index`) por peligro, con su actividad, severidad, probabilidad y valor |
| `f1_document_equipments` / `_objetives` | AST · `equipos` / `objetivos` | una lista |
| `f1_documents.adrights` / `.eqtools` | AST · `observaciones` | texto etiquetado (la plantilla no reproduce esos dos campos) |
| `f2_document_answers` | PTF · `preguntas` | **una** respuesta con las 17 preguntas dentro |
| `f2_document_activities` + `_dangers` | PTF · `matriz_de_riesgo` | igual que el AST |
| `f3_document_workers` + `f3_document_answers` | EPP · `epp_por_trabajador` | una fila por trabajador, con sus ítems y la medida de corrección |
| `f4_document_tools` + `f4_document_items` | IHM · `inspeccion_de_herramientas` | una fila por herramienta, con sus puntos de inspección |

**Las respuestas se guardan por su etiqueta, nunca por el número de la v1.** El mismo entero
significa cosas distintas en cada formato, y está escrito en las vistas originales
(`show_pdf_page1.erb` de cada uno):

- PTF: `1` = Sí, `0` = No
- EPP: `1` = Conforme, `2` = No conforme, `0` = No aplica
- IHM: `1` = Cumple, `2` = No cumple, `0` = No aplica

Ojo con el IHM: el catálogo de la plantilla lista las opciones en el orden
`No cumple, Cumple, No aplica`, que **no** coincide con los números de la v1. Por eso se guarda el
texto: si se guardara el índice, cambiar el orden del catálogo cambiaría el significado de miles de
documentos ya firmados. `LegacyFormMapper` es el único sitio donde vive este mapeo y está cubierto
por pruebas.

Cosas que no se traen y por qué:

- `f1..f4_documents.observations` era un **entero** (0, 1 o 3), no un texto: no significa nada fuera
  de aquel formulario.
- 2 700 respuestas de EPP vienen nulas. Nulo es "no se contestó", que no es "no aplica": se quedan
  nulas. Rellenarlas sería inventar el documento.
- Las actividades del AST que no tenían ningún peligro sí se migran, como fila de la matriz con el
  peligro vacío: existían en la v1 y perderlas cambiaría el documento.

## El paso de las evidencias, que es el incómodo

De las 34 492 referencias a firma o foto que hay en la v1, **30 695 no son un archivo**: son las
cadenas `detected_by_IA` y `signed_by_IA` que la aplicación escribía en la columna del fichero
cuando el navegador creía haber reconocido a la persona. **El 89 % de las firmas y fotos históricas
no tiene nada detrás.**

Eso no es evidencia y no se migra como si lo fuera:

- Todas las firmas llegan con `method = 'migrated'`. Lo que la v1 llamaba `face_recognition` lo
  decidía el navegador y no dejó prueba, así que no puede llamarse igual que una firma verificada
  por el servidor. El `used_ai` del origen sí se conserva: es lo que la v1 creía.
- Las que no tienen archivo llevan `evidence_missing = true` y **no** generan `evidence_files`.
- Lo migrado no entra en la cola de revisión (`pending_review = false`): se marca, no se revisa.
  Meter 14 336 firmas históricas en la bandeja de un supervisor no sirve de nada.
- El comando imprime siempre cuántas referencias eran reales y cuántas marcadores.

| Referencias de la v1 | Cuántas |
| --- | ---: |
| archivo real | 3 797 |
| marcador `detected_by_IA` / `signed_by_IA` | 30 695 |

De dónde salen los eventos:

- `worker_signature_events` (9 059 filas) es la única tabla de eventos que la v1 llegó a usar. 19 de
  ellas apuntan a un `plan_worker` que ya no existe: se descartan y se cuentan.
- 2 trabajadores firmaron sin dejar evento; se les crea uno a partir de las columnas de
  `plan_workers`.
- `approval_signature_events` existía en la v1 con el diseño correcto pero **nunca se conectó**:
  0 filas. La única huella de que alguien aprobó son las columnas `signature`/`photo` de
  `plan_approvals`, así que el evento se sintetiza desde ahí (8 238 filas, 4 descartadas por no
  tener a quién atribuir la firma).

Resultado: **17 276 eventos**, 2 940 con archivo (3 797 ficheros entre caras y firmas) y 14 336 sin
nada detrás.

## Los archivos físicos: lo que falta

Las imágenes no están en la base ni en este repositorio. Viven en el `public/images_uploads` del
servidor viejo. Lo que DOCUFIZ apunta hoy son **4 027 ficheros distintos** en 4 037 referencias:
3 797 de evidencia de firma y 240 de firma de referencia de persona (unas cuantas reutilizan un
nombre ya usado). En la v1 había 4 189 referencias en total; las que faltan son las de las filas
descartadas y las firmas de persona que se consolidaron al unificar las identidades.

Mientras no se copien, cada `evidence_file` apunta a `legacy/images_uploads/<nombre>` con
`byte_size = 0` y un `sha256` **provisional** (el hash del nombre, no del contenido). Es una marca,
no una comprobación.

Para cerrarlo:

```bash
# 1. traerse la carpeta del servidor viejo
rsync -av usuario@servidor-v1:/var/www/app/public/images_uploads/ /tmp/v1-images/

# 2. copiar, pesar y hashear de verdad
php artisan docufiz:migrate-data archivos --desde=/tmp/v1-images
```

El paso copia cada fichero a `evidencias/legacy/<2 primeros del hash>/<hash>.<ext>` (deduplicado por
contenido: un fichero reutilizado se guarda una sola vez), recalcula `sha256`, `byte_size`, `width`
y `height`, y hace lo mismo con las firmas de referencia de las personas. Lo que no aparezca queda
marcado como evidencia perdida y se cuenta en pantalla.

Un evento con foto y firma solo se marca como perdido si **ninguno** de sus dos ficheros existe.

El paso **no falla si la carpeta no está**: avisa y sale bien. La ausencia de los ficheros no puede
tumbar la migración.

## La nacionalidad y el tipo de documento

`workers.nationality_id` es NOT NULL en la v1 —los 391 trabajadores traen una— y
no se migraba: la tabla quedaba vacía y la columna de la persona en nulo. Ya se
trae, con su catálogo.

El reparto real de los 391: **380 Perú, 9 Venezuela, 1 Chile, 1 Argentina.**

Arrastra una segunda cosa. La v1 **no tiene tipo de documento** —todo es
`num_doc` a secas— así que aquí se escribía «DNI» para los 391. Para 380 es
cierto; para los 11 extranjeros no, porque un extranjero no puede tener DNI.
Ahora el tipo se deduce de la nacionalidad: la del país donde se trabaja → DNI,
cualquier otra → CE.

> **Es una deducción, no un dato del origen.** La sostiene el propio volcado:
> los 11 extranjeros tienen documento de nueve caracteres y los peruanos de
> ocho, sin una sola excepción en cinco años. Aun así, si alguno lleva PTP o
> pasaporte en vez de carné, hay que corregirlo a mano.

En pantalla la nacionalidad **sólo se enseña cuando no es la del país donde se
trabaja**. La v1 ponía una banderita en las 391 filas y con el 97 % peruanos eso
no informa: es la misma bandera repetida y el ojo deja de verla. Lo que hay que
ver es el que viene de fuera.

De paso salió que la persona se buscaba por `(tipo, documento)` en dos sitios,
con el tipo fijo en `DNI`. En cuanto el extranjero pasa a `CE` eso lo dejaba
fuera y su plan se quedaba sin él. En la v1 el tipo no existe, así que la
identidad es el número: ahora se busca sólo por documento.

## Los catálogos llegan bloqueados

Tipos de trabajo, sedes, puestos, áreas, cargos y reglas de aprobación entran con el candado puesto
(`Lockable`, nivel `super`). No es celo: el catálogo se lee **en vivo** desde los planes, así que
renombrar «Izaje» o quitarle el AST cambia de golpe lo que dicen los 3 712 planes que lo citan,
cerrados y firmados incluidos. El candado no impide corregirlo — obliga a quitarlo primero, que es
la pausa que faltaba. Un admin de workspace no puede sacarlo; sólo el super.

Los **planes no** entran aquí: tienen su propio `is_closed`, que es otra cosa y la pone el
supervisor al terminar la jornada.

Se bloquea la primera vez que una fila se declara «viene de la v1»: al crearla, y también cuando ya
existía a mano y la migración la reconoce por su código y le pone el `legacy_id`. Sólo esa primera
vez — volver a migrar no le devuelve el candado a lo que alguien quitó a propósito.

> **Si migraste cargos antes de agosto de 2026**, `Position` no tenía `legacy_id` en su `$fillable`
> y Eloquent lo descartaba en silencio: los cargos llegaron, pero sin marca de origen, y por eso no
> se bloquearon. Está arreglado. Vuelve a correr `docufiz:migrate-data catalogos` y los reconocerá
> por el código, les pondrá el `legacy_id` y los bloqueará.

## Qué queda pendiente

- [ ] **Copiar los 4 027 ficheros de imagen** y correr `docufiz:migrate-data archivos`. Es lo único
      que falta para que la migración de datos esté completa.
- [ ] Volver a correr `docufiz:migrate-data catalogos` para que los cargos ya migrados recuperen su
      `legacy_id` y queden bloqueados (ver el aviso de arriba).
- [ ] **Reemplazar los 26 correos `usuarioN@pendiente.local`** por los reales, y revisar el rol de
      cada usuario (todos entraron con el de menos privilegios).
- [ ] Revisar los 196 planes con código renombrado y decidir si el sufijo se queda o el dueño
      prefiere otro criterio.
- [ ] Decidir qué se hace con las 4 aprobaciones que se migraron sin aprobador.
- [ ] Los descriptores biométricos de las personas (`person_biometrics`) no existen en la v1: el
      enrolamiento facial se hace de cero en DOCUFIZ.
- [ ] `audits` (36 239 filas) no se migra: el registro de auditoría de DOCUFIZ arranca limpio. Si
      hace falta consultar el histórico, está la v1 en solo lectura durante 3 meses.

## Checklist de corte

- [x] Los conteos de cada paso cuadran con el origen, y lo que no cuadra está contado y explicado
- [x] Cero registros huérfanos (`people`, `work_plans`, `form_submissions`, `signature_events`)
- [ ] Los 4 027 archivos de imagen existen en el almacenamiento nuevo y su `sha256` coincide
- [ ] Los PDF de 20 planes elegidos al azar salen iguales que en la v1
- [x] Las 548 aprobaciones obligatorias pendientes siguen marcadas como pendientes
- [ ] Todos los usuarios pueden entrar y ven solo lo suyo (bloqueado por los correos provisionales)
- [ ] La v1 queda en modo lectura y con aviso visible

## Cómo se prueba

Sin MySQL de por medio: `tests/Feature/Migration/LegacyDatabaseFixture.php` levanta el mismo esquema
de la v1 sobre sqlite en memoria y lo rellena con datos inventados que reproducen las rarezas del
origen (códigos repetidos, la misma persona en dos empresas, un aprobador que ya no existe, fotos
que son el marcador, planes de otro país).

- `tests/Feature/Migration/LegacyFormMapperTest.php` — la traducción de los formatos, sin base de
  datos. Es lo que impide que un cambio de mapeo convierta miles de "Cumple" en "No cumple".
- `tests/Feature/Migration/MigrateLegacyDataTest.php` — la migración entera de punta a punta,
  incluida la idempotencia (correrla dos veces no duplica nada) y el paso de archivos.

**Ningún dato real entra en el repositorio.** Los nombres y documentos de la v1 son de personas
reales; en docs, pruebas y semillas solo hay cifras agregadas y datos inventados.
