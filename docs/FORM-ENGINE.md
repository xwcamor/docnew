# Motor de formatos

## El problema que resuelve

En la v1, cada formato (AST, PTF, EPP, IHM) era una tabla, un modelo, un controlador de 150-250
lineas, entre 8 y 12 vistas, dos plantillas PDF y su entrada en rutas. Anadir uno costaba entre 3 y
5 dias. La prueba esta en el propio repositorio: existe un `F5DocumentsController` con 18 vistas
apuntando a un modelo y una tabla que nunca se crearon.

Ademas la resolucion de clase era `"F#{document_id}Document".constantize`: el nombre de la clase
dependia del id de una fila.

## Modelo

**Definicion** (lo configura el administrador):

- `form_templates` — `code`, `kind`, `status`, `version`, `requires_signature`
- `form_sections` — agrupacion visual
- `form_fields` — `code`, `field_type`, `required`, `config`, `visibility_rule`
- `work_type_form_templates` — que formatos exige cada tipo de trabajo

**Captura** (lo llena la cuadrilla):

- `form_submissions` — por plan y plantilla, con `template_version`
- `form_answers` — un valor por campo y fila
- `form_attachments` — archivos, con `sha256`

## Los tres tipos de formato

| `kind` | Para que | Que ve el usuario |
| --- | --- | --- |
| `structured` | formatos con campos | el formulario generado |
| `upload_only` | la "HOJA X": un papel que se fotografia | camara o selector de archivo |
| `hybrid` | unos campos mas el papel adjunto | ambos |

## Tipos de campo

Basicos: `text`, `textarea`, `number`, `date`, `time`, `select`, `multiselect`, `checkbox`,
`radio`, `table`, `photo`, `file`, `signature`.

Compuestos, que reproducen lo que en la v1 eran formatos enteros:

| Tipo | Equivale a |
| --- | --- |
| `person_checklist` | EPP: una fila por trabajador del plan |
| `tool_checklist` | IHM: una fila por herramienta |
| `risk_matrix` | AST/PTF: actividad → peligro → severidad × probabilidad |
| `question_bank` | PTF: banco de preguntas por pais |

## Versionado

Un `form_submission` guarda la version de la plantilla con la que se lleno. Publicar una version
nueva **no** altera lo ya firmado. En documentos de seguridad esto no es opcional.

## Rendimiento

El almacenamiento por respuesta es el punto delicado: en la v1 `f3_document_answers` tiene 226 875
filas y `f4_document_items` 76 750. Antes de migrar ningun formato de alto volumen hay que medir con
esos datos reales; el indice `(form_submission_id, form_field_id, row_index)` existe justamente para
eso. Si no rinde, un formato puede quedarse con su tabla nativa: el motor no obliga a migrar.
