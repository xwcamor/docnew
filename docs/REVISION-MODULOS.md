# Encargo de revisión de un módulo

Cada agente revisa **un módulo** y sólo ese. Este documento es el criterio común
para que quince revisiones no salgan quince estilos distintos.

## Lo que hay que dejar bien

1. **Los nombres.** Como se dicen en obra, no como se llama la columna. Ver
   `docs/UI.md §2`. Una etiqueta en mayúsculas con un punto (`PEOPLE.NUM_DOC`)
   es una clave sin traducir y es un fallo, no un descuido.
2. **La apariencia.** Coherente con los módulos hermanos: misma anatomía de
   índice, ficha y formulario. Si dos listas hermanas se ven distintas, una de
   las dos está mal.
3. **Los formularios.** Que se puedan enviar y que guarden. Que no falte ningún
   campo que la tabla exige.
4. **Iconos y títulos.** Un icono por concepto, el mismo en todas partes.
5. **La posición de los datos.** Lo que identifica arriba, lo que matiza debajo.
6. **Los errores al crear y editar.** Que no reviente y que, si algo falta, lo
   diga en su campo.
7. **Los datos que faltan en los formularios.** Columnas que existen en la tabla
   y que la pantalla nunca deja rellenar.

## Fallos conocidos: búscalos, están repetidos

Casi todos los módulos se generaron clonando `Brand`. Eso dejó una familia de
defectos que ya han aparecido varias veces:

- **Una columna NOT NULL que el formulario no pide.** Crear reventaba con un
  `23502` de Postgres en la cara del usuario. Pasó en `form_templates` con
  `country_id`. **Comprueba la tabla contra el formulario, columna a columna.**
- **Leer una columna que no existe.** La ficha de formatos enseñaba
  `$m->sort_order` para la versión: esa columna no existe en esa tabla, así que
  salía siempre vacía. Silencioso.
- **Listas escritas a mano que deberían ser catálogo.**
  `Rule::in(['DNI', 'CE', 'PASAPORTE'])` impedía dar de alta a nadie con PTP sin
  tocar PHP.
- **Claves ajenas inventadas** en el servicio (`documentType_id` sobre una tabla
  que guarda el texto).
- **Un estado inalcanzable.** Un formato nacía en `draft` y no había ninguna
  acción para publicarlo: era inservible y nadie lo había notado.
- **Rejillas aplanadas.** `app.css` forzaba toda columna a ancho completo. Ya
  está arreglado, **pero una fila `<Row>` sólo se pinta en dos columnas si lleva
  `class="form-grid"`**. Revisa si el formulario tiene `Row`/`Col` escritos que
  se están pintando apilados, y si conviene agrupar campos cortos por pares. La
  rejilla parte en `lg` (≥992), no en `md`: en tablet vertical se apila a
  propósito.
- **Permisos mal puestos.** Exportar pedía `.view` en vez de `.export`, así que
  cualquiera que abriera el listado se bajaba el fichero entero.
- **Datos privados sin tapar.** El documento de una persona va enmascarado salvo
  con `people.view_private_info` (ver `App\Support\PrivateInfo`). Eso vale en
  pantalla **y en exportaciones**.

## Cómo comprobar que está bien

**Escribe una prueba de que las pantallas abren** si el módulo no la tiene. Es
la que encontró el fallo de formatos:

```php
foreach (['index', 'create', 'trash'] as $p) { $this->get(route("...{$p}"))->assertOk(); }
foreach (['show', 'edit', 'delete'] as $p) { $this->get(route("...{$p}", $fila->slug))->assertOk(); }
```

Y una de alta y otra de edición que **comprueben la fila en la base**, no sólo
que la respuesta sea 302.

Los catálogos de obra tienen base común en
`tests/Feature/BusinessManagement/CatalogTestCase.php`: si el módulo encaja,
extiéndela en vez de repetir el escenario.

## Reglas de convivencia

Quince agentes a la vez sobre el mismo repositorio. Para no pisarse:

**Puedes editar** sólo lo tuyo:
- `app/Http/Controllers/**/<Modulo>Controller.php`
- `app/Http/Requests/**/<Modulo>/**`
- `app/Services/**/<Modulo>Service.php`
- `app/Models/<Modelo>.php`
- `resources/js/Pages/<Modulo>/**`
- `resources/js/Components/<Modulo>/**`
- `resources/lang/es/<modulo>.php` y `resources/lang/en/<modulo>.php`
- `tests/Feature/**/<Modulo>*Test.php`

**NO edites** —lo tocan todos y se perdería trabajo—. Si hace falta un cambio
ahí, **anótalo en el informe** y lo aplico yo:
- `routes/*.php`
- `resources/js/Layouts/AppLayout.vue`
- `resources/css/app.css`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `database/seeders/**`, `database/migrations/**`
- `resources/js/Components/Common/**` y `Components/Catalog/**` (compartidos)
- `docs/**`

**No ejecutes `npm run build`** (se pisan entre sí; lo corro yo al final) y **no
hagas commit ni push**.

Sí ejecuta las pruebas de tu módulo:
`php artisan test --filter '<Modulo>'`

## El informe

Al terminar, responde con:

1. **Qué estaba roto de verdad** — fallos que impedían usar el módulo, con el
   síntoma que vería el usuario.
2. **Qué arreglaste**, agrupado por los siete puntos de arriba.
3. **Qué necesita un archivo compartido** — el cambio exacto, listo para pegar.
4. **Qué dejaste sin tocar y por qué** — si algo es una decisión del dueño, no la
   tomes tú: dilo.

Sé honesto en el punto 4. Un módulo que no tenía nada roto es una respuesta
perfectamente buena.

## El criterio, por si dudas

Un supervisor de obra, con casco y guantes, en una tablet, a pleno sol y con
prisa. Si una pantalla necesita que alguien la explique, está mal hecha aunque
funcione.
