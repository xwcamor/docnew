# Los cuatro formatos, de la v1 al motor

En obra, cada plan de trabajo lleva unos **formatos**: los documentos de
seguridad que la gente rellena y firma antes y durante la faena. En el sistema
anterior eran cuatro, y estaban cableados a fuego: cada uno tenía su familia de
tablas (`f1_documents` y sus hijas, `f2_documents`…), su modelo, su controlador
de doscientas líneas, entre ocho y doce vistas y dos plantillas de PDF. Añadir un
quinto formato eran tres o cinco días de trabajo y un despliegue.

Aquí un formato es configuración: `form_templates` → `form_sections` →
`form_fields`, y al rellenarlo `form_submissions` → `form_answers`.

Este documento dice **qué era cada uno**, **a qué se traduce**, y —lo más
importante— **qué no encajó y qué se hizo con ello**.

Fuentes: `db/schema.rb`, `app/models/f[1-4]_document*.rb`, las vistas de
`app/views/fm_management/` (que es donde se ve el orden, la etiqueta y qué es
obligatorio de verdad), las plantillas de PDF `show_pdf_page1.erb`, y
`db/seeds.rb` para los catálogos.

> **La base MySQL de la v1 no estaba disponible.** `DB::connection('legacy')`
> devuelve `SQLSTATE[HY000] [2002] Connection refused` contra
> `127.0.0.1:3306/viejo`. El análisis se hizo sobre el esquema, los modelos y
> las vistas, y los datos reales salieron de dos sitios que sí están en el
> repositorio: `db/seeds.rb` de la v1 (la línea base del producto, en tres
> idiomas) y `database/seeders/data/legacy-docufiz.json` (una exportación de la
> base viva, en castellano). Dónde se cogió cada cosa está en §6.

---

## 1. Qué es cada formato

La correspondencia F1–F4 no hay que adivinarla: `db/seeds.rb:264` la deja
escrita, y las vistas de la ficha del plan la confirman
(`_format_ast_pe.html.erb`, `_format_ptf_pe.html.erb`, …).

| v1 | Documento | Es | Secciones |
| --- | --- | --- | --- |
| F1 | `ast_documents` | **AST — Análisis de Seguridad en el Trabajo.** La matriz de actividad → peligro → riesgo → control, con severidad y probabilidad por fila. Es el formato gordo. | Permisos · Objetivos · Trabajos a realizar |
| F2 | `ptf_documents` | **PTF — Pare y Tome 5.** Las 17 preguntas de «antes de empezar», más una matriz de riesgo para lo que el AST no había contemplado. | Cuestionario · Riesgos no contemplados |
| F3 | `epp_documents` | **EPP — Inspección de equipos de protección.** Una fila por trabajador del plan y 25 items de protección por fila. | Inspección |
| F4 | `ihm_documents` | **IHM — Inspección de herramientas manuales y eléctricas portátiles.** Una fila por herramienta y 10 puntos de inspección por fila. | Inspección |

Sí: **F1 es el AST**, confirmado.

---

## 2. Tabla de correspondencias

### 2.1 AST (F1)

`f1_documents` → `f1_document_activities` → `f1_document_dangers`, más
`f1_document_equipments` y `f1_document_objetives`.

| Sección | Campo | v1 | Tipo del motor | Oblig. | Config |
| --- | --- | --- | --- | --- | --- |
| Permisos | `permisos` | `f1_documents.adrights` | `text` | no | — |
| Permisos | `herramientas_adicionales` | `f1_documents.eqtools` | `text` | no | — |
| Objetivos | `objetivos` | `f1_document_objetives` → `ast_objetives` | `multiselect` | no | `options` (10) |
| Objetivos | `equipos` | `f1_document_equipments` → `ast_equipments` | `multiselect` | no | `options` (10) |
| Trabajos a realizar | `matriz_de_riesgo` | `f1_document_activities` + `f1_document_dangers` | `risk_matrix` | **sí** | `activities` (126), `dangers` (83), `risks` (84), `controls` (40), `severities` (5), `probabilities` (5), `matrix`, `levels` |
| Trabajos a realizar | `observaciones` | — | `textarea` | no | — |

Las tres tablas encadenadas del AST caben en un solo campo `risk_matrix`, que
guarda una fila por peligro con su actividad al lado. Una actividad sin peligros
también sale como fila: en la v1 existía, y perderla sería cambiar el documento.

Obligatorio sólo hay uno, y no es una interpretación: `F1Document` valida
`validate_activities_presence` y `validate_activities_have_dangers`, y nada más.

### 2.2 PTF (F2)

| Sección | Campo | v1 | Tipo del motor | Oblig. | Config |
| --- | --- | --- | --- | --- | --- |
| Cuestionario | `preguntas` | `f2_document_answers` → `ptf_questions` | `question_bank` | **sí** | `questions` (17), `answers` (2) |
| Riesgos no contemplados | `matriz_de_riesgo` | `f2_document_activities` + `f2_document_dangers` | `risk_matrix` | no | igual que el AST |
| Riesgos no contemplados | `observaciones` | — | `textarea` | no | — |

Que la matriz **no** sea obligatoria aquí no es un descuido:
`F2Document#validate_activities_presence` está comentada en la v1, así que allí
se podía guardar un PTF sin una sola fila de riesgos.

### 2.3 EPP (F3)

| Sección | Campo | v1 | Tipo del motor | Oblig. | Config |
| --- | --- | --- | --- | --- | --- |
| Inspección | `epp_por_trabajador` | `f3_document_workers` + `f3_document_answers` → `epp_items` | `person_checklist` | **sí** | `items` (25), `answers` (3), `extra` (3) |
| Inspección | `observaciones` | — | `textarea` | no | — |

Las filas no se declaran: el campo `person_checklist` genera una por trabajador
del plan, que es lo que hacía el controlador de la v1 al abrir el formulario
(`plan_workers.each { … build }`).

Las tres columnas del final del papel —`correction_measure`, `deadline_date`,
`correction_verification`— viajan en `config.extra` y la pantalla sólo las
enseña cuando algo salió no conforme, que es cuando se llenan de verdad.

### 2.4 IHM (F4)

| Sección | Campo | v1 | Tipo del motor | Oblig. | Config |
| --- | --- | --- | --- | --- | --- |
| Inspección | `inspeccion_de_herramientas` | `f4_document_tools` + `f4_document_items` → `ihm_items` | `tool_checklist` | **sí** | `tools` (4), `items` (10), `answers` (3), `extra` (2) |
| Inspección | `observaciones` | — | `textarea` | no | — |

`config.tools` es un catálogo de sugerencias, no un desplegable cerrado: en la
v1 el nombre de la herramienta es un `text_area` con autocompletado contra
`ihm_tools`.

### 2.5 Lo que se elige de catálogo y lo que se escribe a mano

| Se elige de catálogo | Se escribe a mano |
| --- | --- |
| Objetivos y equipos del AST (`ast_objetives`, `ast_equipments`) | Permisos y herramientas adicionales del AST |
| Severidad y probabilidad de cada fila de riesgo | Nombre de la herramienta del IHM (con autocompletado) |
| Items de EPP y puntos de inspección del IHM | Medidas de corrección, responsable, verificación |
| Preguntas del PTF | Observaciones |
| Actividad, peligro, riesgo y control — **con matiz**: en la v1 son `text_area` con autocompletado contra `ast_activities` / `ast_dangers` / `ast_risks` / `ast_controls`. Se sugiere, no se obliga. | |

---

## 3. Qué formatos exige cada tipo de trabajo

`work_type_documents` de la v1 (`db/seeds.rb:325`), para Perú:

| Tipo de trabajo | Formatos exigidos |
| --- | --- |
| Estándar | AST, PTF |
| Eléctricos | AST, PTF, EPP |
| Altura | AST, PTF, IHM |
| Vacío | AST, PTF, **y un `document_id: 5` que no existe** |

Es decir: **el AST y el PTF van siempre**, y el tercero depende del riesgo
dominante del trabajo. Brasil repite el mismo patrón con otros ids.

Lo del `document_id: 5` no es una lectura mía: la v1 sólo siembra cuatro
documentos (ids 1 a 4) y luego pide el 5 para el tipo «Vacío». Es una fila
huérfana en los seeds del sistema anterior.

**Esto no se sembró**, y es una decisión: en una instalación limpia de DOCUFIZ
no hay ningún tipo de trabajo todavía —no existe un `WorkTypesSeeder`—, así que
no hay a qué enganchar las plantillas. La tabla pivote `work_type_form_templates`
está creada y vacía. Cuando se siembren los tipos de trabajo, la tabla de arriba
es el mapeo que hay que aplicar.

---

## 4. El banco de preguntas del PTF

`ptf_question_titles` (5 filas) agrupa `ptf_questions` (17 filas). Cada título
tiene además una imagen (`1.png`…`5.png`) que la v1 pinta en una celda con
`rowspan` a la izquierda del bloque.

| # | Título | Preguntas |
| --- | --- | --- |
| 1 | ¡DETÉNTE y piensa antes de actuar! | 4 |
| 2 | ¿Somos COMPETENTES y HABILITADOS para realizar el trabajo? | 3 |
| 3 | ¿Estás debidamente EQUIPADO? | 3 |
| 4 | Mira y observa los RIESGOS a tu alrededor | 6 |
| 5 | ¿Está todo correcto para comenzar la actividad? | 1 |

**El agrupamiento se pierde.** Ver §5.2.

---

## 5. Qué no encajó

Esta es la sección que importa. Nada de aquí está «resuelto porque sí».

### 5.1 El motor no tenía dónde poner los nombres — **ampliado**

`form_templates` tenía `code` y `name` (una sola cadena). `form_sections` tenía
`position` **y nada más**. `form_fields` tenía `code` y nada más.

O sea: la cabecera del formato enseñaba «AST», las secciones salían como
tarjetas sin título, y la etiqueta de cada campo se sacaba de humanizar el
código (`FormFill.vue`: `campo.config?.label ?? humanizar(campo.code)`). Sale
legible de chiripa porque los códigos se escribieron en castellano; el día que
un campo se llame `epp_por_trabajador`, la etiqueta es «Epp por trabajador». Y
en inglés no sale nada.

La v1 **sí** tenía los nombres, y en tres idiomas: no en el código, sino en la
tabla `translations`, que es de donde `Document#translated_name` sacaba «AST
(Análisis de Seguridad en el Trabajo)».

Se amplió el motor
(`2026_08_10_120000_add_bilingual_names_to_form_engine.php`), siguiendo el
criterio que ya usan `approver_roles` y `positions` — el texto es un dato, no
una cadena de `resources/lang`, porque un formato lo crea el cliente desde la
pantalla y su nombre no puede vivir en el repositorio:

| Tabla | Columnas nuevas | Accesor |
| --- | --- | --- |
| `form_templates` | `name_es`, `name_en` | `FormTemplate::getLabelAttribute()` |
| `form_sections` | `name_es`, `name_en` | `FormSection::getLabelAttribute()` |
| `form_fields` | `label_es`, `label_en` | `FormField::getLabelAttribute()` |

`form_templates.name` **se queda**: lo consultan `scopeFilter()` y las pantallas
ya escritas. Pasa a ser el nombre por defecto y el seeder lo deja igual a
`name_es`. Todas las columnas son nullable y ninguna lleva clave ajena, así que
SQLite no reconstruye ninguna tabla.

### 5.2 Los catálogos de `config` son de un solo idioma — **no encaja, no se forzó**

`config.options`, `config.items`, `config.questions`… son listas planas de
cadenas, y los componentes de campo las leen así (`catalogo()` en
`respuestas.js` hace `.map(String)`). En la v1 **todos** los catálogos son
trilingües: `ast_activities.name_es / name_pt / name_en`, `epp_items`,
`ptf_questions`, todos.

Se sembró **castellano**, por dos razones: es lo que ve la instalación de Perú,
y es lo que escribe `LegacyFormMapper` en las respuestas migradas (guarda la
etiqueta, no el número). Si el catálogo no contuviera exactamente esas cadenas,
las entregas migradas se abrirían sin nada marcado.

**No se inventó una clave paralela** (`options_en`, etc.) porque los componentes
de campo no la leerían y sería configuración muerta. Esto es una carencia real
del motor, y está pedida en §7.

Por lo mismo se pierden dos agrupaciones que la v1 sí pinta:

- **El PTF pierde sus 5 títulos y sus imágenes.** `question_bank` sólo admite
  `questions` como lista plana. En la v1 las preguntas salen en cinco bloques
  con un icono a la izquierda, que es lo que hace que el formato de papel se
  reconozca de un vistazo.
- **El EPP pierde sus 8 categorías.** `epp_items` cuelga de `epp_categories`
  (Cabeza, Cara, Cuerpo, Ojos, Manos, Oídos, Sist. Respiratorio, Pies) y la
  tabla de la v1 lleva una fila de cabecera con `colspan` por categoría.
  `person_checklist` sólo admite `items` como lista plana, así que los 25 items
  salen seguidos.

Ninguna de las dos pérdidas afecta a lo que se guarda ni a lo que se firma: es
presentación. Pero es presentación que la gente de obra reconoce.

*(De paso: la asignación de categorías de la v1 tiene lo suyo — «Mandil de
cuero», «Escarpines de cuero» y «Traje anti arco eléctrico» están bajo la
categoría «Ojos». Es un error de datos del sistema anterior, no una lectura mía.)*

### 5.3 `observations` de la v1 es un entero, no un texto

En los cuatro formatos, `observations` es la **cuenta** de hallazgos que
recalculaba `set_completed` después de cada guardado, y era lo que el supervisor
leía en la ficha del plan.

Eso aquí ya existe y se llama `form_submissions.nonconformities`, y lo lleva
`FormFindingsService`. El campo `observaciones` que se siembra en los cuatro
formatos es **otra cosa**: texto libre. No estaba en la v1; se conserva porque es
donde `MigrateLegacyDataCommand` deja los permisos y las herramientas del AST
(ver §5.4) y porque un cajón de texto al final de un formato de seguridad se usa.

### 5.4 Los dos campos de texto del AST no se estaban trayendo — **arreglado**

`f1_documents.adrights` (permisos) y `f1_documents.eqtools` (herramientas
adicionales) son los **dos únicos campos libres del AST**, y se pintan en la
primera tarjeta del formulario. `docufiz:migrate-formats` no creaba ningún campo
para ellos, y `LegacyFormMapper::observacionesAst()` los mete a empujones dentro
de las observaciones, con un «Permisos adicionales: » delante.

Ahora existen como `permisos` y `herramientas_adicionales`. **Pendiente para
quien lleve la migración**: `MigrateLegacyDataCommand::migrarAst()` sigue
volcándolos en `observaciones`; debería escribirlos en su campo. Los AST nuevos
ya usan los campos buenos.

### 5.5 El IHM perdía la medida de correción y el responsable — **arreglado**

`f4_document_tools` tiene `correction_measure` y `responsible`, y las plantillas
que creaba `docufiz:migrate-formats` no declaraban ningún `extra` para el
`tool_checklist`: las dos columnas no tenían dónde escribirse. Ahora van en
`config.extra`, igual que las tres del EPP. Los dos nombres ya tienen traducción
en `resources/lang/{es,en}/field_work.php` (`field_work.extra.*`).

La tercera columna del papel, `f4_document_tools.is_enabled`, **no se porta a
propósito**: en la v1 no la marcaba nadie, la ponía sola un JavaScript según las
respuestas de la fila. Aquí es el `conforme` que ya calcula el propio campo
(`filaConforme()` en `respuestas.js`). Portarla sería duplicar un dato derivado.

### 5.6 El PTF tenía dos respuestas, no tres — **corregido**

`docufiz:migrate-formats` sembraba `['Si', 'No', 'No aplica']`. El formulario de
la v1 pinta **exactamente dos radios** por pregunta (`_form_questions.html.erb`)
y el PDF sólo mira `answer == 1` y `answer == 0`. Se siembran dos: `['Si', 'No']`,
que además son las etiquetas que escribe `LegacyFormMapper::RESPUESTAS_PTF`.

Detalle que queda sin resolver, y conviene saberlo: la cabecera de la segunda
columna en la v1 es la clave `f2_documents.question_na` («no aplica») mientras
que el `id` del radio dice `_no`. El texto de verdad estaba en la tabla
`translations`, que no se pudo leer. Se sigue al mapper —«No»— porque es lo que
tienen las entregas ya migradas.

### 5.7 El IHM listaba las respuestas al revés — **corregido**

EPP e IHM usan los mismos códigos numéricos (1 conforme/cumple, 2 no conforme/no
cumple, 0 no aplica), pero `docufiz:migrate-formats` los declaraba en orden
distinto en cada uno: `['Conforme', 'No conforme', 'No aplica']` en el EPP y
`['No cumple', 'Cumple', 'No aplica']` en el IHM. Ahora los dos ponen el positivo
primero. **No rompe nada migrado**: se guarda la etiqueta y nunca la posición.

### 5.8 El nivel de riesgo no es severidad × probabilidad

Merece repetirse aunque ya estuviera resuelto en el comando. La tabla `risks` de
la v1 es un **ranking del 1 al 25** donde el 1 es lo peor (`c1` más grave, `p1`
más probable):

```
        p1   p2   p3   p4   p5
  c1     1    2    4    7   11
  c2     3    5    8   12   16
  c3     6    9   13   17   20
  c4    10   14   18   21   23
  c5    15   19   22   24   25
```

Bandas de `Risk#level_name`: 1-8 alto, 9-15 medio, 16-25 bajo.

Doce de las veinticinco celdas caen en otra banda si se multiplica. `c2 × p4`
vale **12** en la tabla —«medio»— y **8** multiplicando, que es «alto». La
exportación de la base viva
(`legacy-docufiz.json`) trae `config.formula: "severidad * probabilidad"`, o sea
que en algún momento se hizo así. La tabla entera viaja en `config.matrix` y las
bandas en `config.levels`; hay una prueba que lo fija.

### 5.9 La lista de equipos del AST está muerta en el formulario de la v1 — **se conserva igual**

En `_form.html.erb` del F1, la tarjeta de los equipos lleva
`style="display:none;"` **y** tiene la llamada al partial comentada
(`<%#= render … form_tools %>`). Por el formulario no entra un equipo desde hace
tiempo.

Aun así el campo `equipos` se siembra, porque `f1_document_equipments` sí tiene
filas de cuando se enseñaba y `MigrateLegacyDataCommand` las escribe en ese
campo. Sin él se perderían en silencio. Queda anotado por si se decide quitarlo.

### 5.10 Cosas del origen que se copian tal cual

- `probabilities` de la v1 son `p1, p2, **P3**, p4, p5` — con la pe mayúscula en
  la tercera. Se copia el error: es el valor que tienen las respuestas migradas.
- Lo que se enseñaba en pantalla no era `c1`/`p1` sino un texto de la tabla
  `translations` (`I18n.t("probabilities.#{id}")`), que no se pudo leer. Se
  siembran los códigos, que es lo que guarda `LegacyFormMapper`.

---

## 6. De dónde salió cada catálogo

Los catálogos viajan en `database/seeders/data/formatos-v1.json` y salen de
`db/seeds.rb` de la v1, que es **la línea base del producto**: lo que traía una
instalación nueva del sistema anterior, en tres idiomas y sin datos de ningún
cliente.

| Catálogo | Cuántos | En `config` como |
| --- | --- | --- |
| `ast_activities` | 126 | `activities` |
| `ast_dangers` | 83 | `dangers` |
| `ast_risks` | 84 | `risks` |
| `ast_controls` | 40 | `controls` |
| `ast_equipments` | 10 | `options` de `equipos` |
| `ast_objetives` | 10 | `options` de `objetivos` |
| `epp_items` | 25 | `items` |
| `ihm_tools` | 4 | `tools` |
| `ihm_items` | 10 | `items` |
| `ptf_questions` | 17 | `questions` |

**Por qué la línea base y no la exportación de la base viva**, que es más grande
(127 actividades, 51 controles, 16 herramientas): porque las entradas de más son
datos de un cliente, no del producto. Ocho de los once controles extra son
procedimientos escritos por un cliente **con su nombre dentro** («Política de
HITACHI ENERGY sobre Negativa al Trabajo Inseguro»). Eso saldría en el
desplegable de cualquier otro cliente y acabaría impreso en su PDF; es
exactamente lo que prohíbe `docs/UI.md §2-bis`. Hay una prueba que barre los
catálogos sembrados buscando nombres de clientes reales.

Lo que cada cliente añadió a sus catálogos **no se pierde**: vuelve con
`php artisan docufiz:migrate-formats --fresh` cuando la base vieja está
disponible, que es el camino que ya existía y sigue funcionando.

`ihm_tools` con 4 entradas se ve pobre al lado de las 16 de la base viva, pero es
lo que el producto sembraba, y es un catálogo de sugerencias para un campo de
texto libre: no limita nada.

---

## 7. Lo que le falta al motor

Nada de esto se implementó aquí. Es para quien lleve el editor de campos
(`FormTemplateController` y `resources/js/Pages/FormTemplates/*`) y la pantalla
de llenado.

1. **Catálogos bilingües en `config`.** Hoy `options` / `items` / `questions` son
   listas de cadenas en un solo idioma (§5.2). Haría falta admitir también
   `[{ "es": "Casco", "en": "Helmet" }, …]` y que `catalogo()` en
   `resources/js/Components/FormFields/respuestas.js` elija por el idioma en
   curso, cayendo a la cadena plana para lo que ya está guardado. Es la carencia
   más grande: sin esto, media aplicación está en inglés y los formatos en
   castellano.

2. **Agrupar los items de un campo compuesto.** Una clave `groups` en `config`
   —`[{ "title": …, "image": …, "items": [...] }]`— cubriría los 5 bloques del
   PTF y las 8 categorías del EPP de una vez (§5.2).

3. **Que la pantalla lea las columnas de nombre nuevas — hecho.**
   `FormSubmissionController::open()` arma el payload a mano y mete el accesor
   `label` del formato, de cada sección y de cada campo; un accesor no viaja
   solo en el JSON de Inertia, que era por lo que la pantalla se quedaba en la
   cadena de respaldo aunque los nombres estuvieran guardados. `FormFill.vue`
   lee `template.label`, pinta el título de la tarjeta con `s.label` y la
   etiqueta con `campo.label`, cayendo al código humanizado si no hay ninguna.
   El duplicado `config.label` del seeder **se quitó**. Lo que sí se conserva es
   el respaldo del accesor: hay campos guardados con la etiqueta dentro de
   `config` y se siguen leyendo (`FormFillNombresTest`).

4. **Que el editor sepa rellenar los nombres — hecho para secciones y campos.**
   `FormTemplates/Structure.vue` pide **un** título por sección y **una**
   etiqueta por campo, y `FormTemplateStructureService` la escribe en la columna
   del idioma en curso, sin tocar la del otro. Un campo que traía la etiqueta en
   `config.label` la muda a su columna la primera vez que se guarda desde la
   pantalla, y el JSON se limpia: dos copias del mismo texto acaban divergiendo.

   **Un cuadro y no dos, a propósito.** Las dos columnas existen para que los
   cuatro formatos que trae el producto vengan en los dos idiomas — y eso lo
   escribimos nosotros en el seeder, una vez. Lo que el cliente escribe se
   guarda tal cual, en su idioma, igual que el nombre de una empresa, de un
   cargo o de una sede: pedirle cada título dos veces es pedirle que traduzca su
   propio trabajo, y en un formato de treinta campos eso son sesenta cuadros que
   nadie va a rellenar. Quien de verdad quiera el suyo en los dos idiomas cambia
   el idioma de la interfaz y lo escribe allí.

   Por eso el servicio escribe **solo** la columna del idioma en curso: mandando
   las dos desde una pantalla que enseña una, abrir el AST en castellano y darle
   a guardar le borraba los nombres en inglés que trae sembrados
   (`test_editar_en_un_idioma_no_borra_el_del_otro`).

   **Queda la cabecera del propio formato**: `FormTemplates/Form.vue` pide un
   solo `name` y no escribe `name_es` / `name_en`, que sólo se rellenan al
   sembrar. `FormTemplate::getLabelAttribute()` cae a `name`, así que nada se ve
   mal; lo coherente sería que ese campo escribiera también la columna del
   idioma en curso, como hacen las secciones y los campos.

5. **`work_type_form_templates` sin sembrar** (§3), a la espera de que existan
   tipos de trabajo.

---

## 8. Cómo entran

Todo por el comando de siempre:

```
php artisan setup:project --datos
```

Por dentro: `migrate:fresh --seed` → `DatabaseSeeder` → `FormTemplatesSeeder`.

Las piezas:

| Fichero | Qué es |
| --- | --- |
| `database/seeders/FormTemplatesSeeder.php` | Construye los cuatro |
| `database/seeders/data/formatos-v1.json` | Los catálogos de la v1 |
| `database/migrations/2026_08_10_120000_add_bilingual_names_to_form_engine.php` | Las columnas de nombre |
| `tests/Feature/FieldWork/FormatosSembradosTest.php` | 12 pruebas |
| `tests/Feature/FieldWork/FormFillNombresTest.php` | Que la pantalla de llenado lea esos nombres |

**Es idempotente, y con matiz.** Si una plantilla ya existe **con campos**, no se
toca la estructura: puede tener entregas firmadas colgando y el cliente ha podido
editarla desde la pantalla. Sólo se rellenan los nombres que falten. Si existe
pero vacía —el caso de las que dejó a medias `docufiz:migrate-formats`— se
termina de construir.

El seeder **no pasa por `FormTemplateBuilder`** a propósito: ese servicio es la
puerta de la pantalla (exige borrador, resuelve `created_by` con `auth()->id()`,
no conoce las columnas de nombre). Lo que sí se conserva es su contrato: una
prueba comprueba que cada campo sembrado trae la configuración que
`FormTemplateBuilder::TIPOS` exige para su tipo, de modo que saltarse el
constructor no signifique saltarse sus reglas.

Nacen **publicados**: son los formatos que la obra usa desde el primer día, no un
borrador que alguien tenga que revisar.

---

## Las dos formas de todo lo que configura el cliente

Cualquier lista de `config` —respuestas, equipos, herramientas, preguntas,
columnas, actividades— admite dos formas, y **las dos son válidas para
siempre**:

```json
"answers": [
  "Conforme",                                          // forma corta
  { "value": "No conforme", "tone": "bad",             // forma larga
    "label": { "en": "Non-compliant" } }
]
```

La corta es lo que hay en los cuatro formatos migrados y en las 14 000 entregas:
una cadena es su propio valor y su propio rótulo. La larga añade las dos cosas
que una cadena no puede llevar dentro.

**`value` es lo que se guarda y no se traduce jamás.** Es la clave de la
respuesta en `form_answers`, la que casa el PDF con su columna y la que
escribieron las entregas migradas. Traducirla convertiría el mismo documento en
dos documentos distintos según el idioma de la tablet. Lo que cambia con el
idioma es el **rótulo**, que es lo único que se lee.

Un texto suelto del cliente —el nombre de un grupo del EPP, el rótulo de una
banda— admite lo mismo: `"Cabeza"` o `{"es": "Cabeza", "en": "Head"}`. Un idioma
nuevo es una clave más: ni columna, ni migración.

| Pieza | Qué resuelve |
| --- | --- |
| `App\Support\TextoTraducible` | Un texto del cliente en el idioma de quien mira |
| `App\Support\Catalogo` | Una lista: valores, rótulos y tonos |
| `App\Support\BandasDeRiesgo` | Las bandas de la matriz |
| `resources/js/Support/catalogo.js` | **El gemelo exacto de los tres**, para la pantalla |
| `tests/Feature/FieldWork/EscalabilidadDelMotorTest.php` | Compara las dos mitades |

Hay dos implementaciones a propósito: la pantalla tiene que pintar la casilla en
el momento en que se toca, sin ir al servidor, y el servidor tiene que contar las
no conformidades y dibujar el PDF sin preguntarle a la pantalla. Lo que **no**
puede pasar es que digan cosas distintas —la casilla en rojo y el contador en
cero—, y de eso se ocupa la prueba.

### El tono de una respuesta se declara

`tone` es `ok`, `bad` o `na`, y decide **todo** lo que cuelga de una respuesta:
si cuenta como observación en la ficha del plan, qué símbolo lleva en el PDF, de
qué color sale la pastilla, si dispara los campos de medida de corrección y si el
servidor la escribe al cerrar los huecos.

Cuando no se declara, se deduce del texto («empieza por no» → no conformidad).
**Esa deducción es compatibilidad con lo que ya estaba escrito, no un mecanismo
para formatos nuevos.** Se equivoca, y hacia el lado peligroso:

| Catálogo | Qué deducía | Qué pasaba |
| --- | --- | --- |
| `Rechazado`, `Malo`, `Deficiente`, `Fail` | conforme | El fallo salía en verde, y sin ninguna respuesta negativa la pastilla **no tenía a dónde ir** para registrarlo |
| `Normal` | no conformidad | Un equipo en buen estado contaba como observación |

No se arregla alargando la lista de palabras en castellano: la siguiente empresa
traerá otras. Se arregla declarándolo, y el editor lo pide.

### Las bandas de la matriz de riesgo

```json
"levels": [
  { "clave": "critico", "min": 20, "max": 25,
    "label": { "es": "Riesgo crítico", "en": "Critical risk" },
    "tone": "bad" },
  { "clave": "aceptable", "min": 1, "max": 9, "tolerable": true }
]
```

- **`min`/`max` son un rango**, no un límite acumulado. Con eso da igual que lo
  peor de la escala sea el 1 (la matriz de la v1) o el 25 (la clásica de
  severidad × probabilidad). La forma vieja —sólo `hasta`— se sigue leyendo como
  el tramo que empieza donde acabó la anterior.
- **`clave` es lo que se guarda** en cada peligro evaluado. No se traduce.
- **`label` es lo que se lee.** Sin él, las bandas que se llamen como las de la
  v1 caen a la traducción del producto y el resto se leen por su clave.
- **`tone`** es uno de `bad | warn | ok | info | off`: los `--state-*` del
  sistema, no un color tecleado. Sin declararlo se reparte por posición —la
  primera roja, la última verde, las de en medio ámbar—, que es exactamente
  alto/medio/bajo. **Nunca sale del nombre de la banda**: `.ff-risk.is-alto` era
  el motivo de que una empresa con `crítico/moderado/aceptable` viera sus AST en
  gris.
- **`tolerable`** marca la banda que no cuenta como observación. Sin marcar
  ninguna se toma la última. Sin bandas declaradas no se inventa ninguna y ningún
  peligro cuenta: antes se caía a la palabra `bajo` escrita en el código, y en un
  formato con otros nombres eso convertía en observación cada peligro evaluado.

### El puntaje sale de la tabla, no de una multiplicación

`config.matrix` es la cuadrícula real: una fila por severidad, una columna por
probabilidad. **No es severidad × probabilidad** — doce de las veinticinco celdas
de la matriz de la v1 caen en otra banda si se multiplica (c2×p4 vale 12 en la
tabla y 8 en el producto, que es la diferencia entre «medio» y «alto»). El
producto queda sólo de red para un formato al que todavía no le hayan cargado su
tabla, y por eso el editor la pide.

Todo esto se configura en la pantalla de estructura del formato. Nada de ello
necesita entrar a la base.

### Los nombres, en cualquier idioma

El nombre de un campo, el título de una sección, el nombre del formato y el del
rol aprobador viven así: **es/en en sus columnas de siempre** (`label_es`,
`name_en`…) y **cualquier otro idioma en la columna JSON `*_i18n`**. El accessor
`label` funde las dos fuentes (`TextoTraducible::fundir`) con las columnas
mandando en su idioma — cada idioma tiene exactamente un sitio donde vivir, así
que no puede existir una copia rancia que gane a la editada.

El editor de estructura escribe en el idioma en que se navega, vaya donde vaya
ese idioma (`FormTemplateStructureService::escribirTexto`). Activar un idioma
nuevo no toca el esquema: `lang/{idioma}` para el producto, y los nombres del
cliente entran solos por `*_i18n`. Pruebas: `NombresEnCualquierIdiomaTest`.
