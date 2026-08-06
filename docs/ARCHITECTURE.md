# Decisiones de arquitectura

Cada decision dice tambien que problema concreto de la v1 evita.

## 1. Rails 7.2 + MySQL 8, no Laravel/Inertia/Vue

TRAFODEX aporta su organizacion y su sistema de diseno, no su stack. Reescribir el front en Vue
habria significado tirar 814 vistas que hoy funcionan en obra. Se mantiene ERB, y el
comportamiento dinamico va con Hotwire.

## 2. Hotwire en vez de jQuery + `.js.erb`

La v1 tiene 271 vistas `.js.erb`: cada accion devuelve JavaScript que reemplaza HTML a mano. Turbo
Frames y Turbo Streams hacen lo mismo sin escribir JavaScript por vista.

## 3. Una identidad de persona, con vinculos por empresa

`people` + `person_company_links`. En la v1 la unicidad de `num_doc` estaba scoped a `company_id`,
asi que la misma persona se creaba de nuevo en cada contratista: 391 filas para 231 personas, cada
una con su propia foto y firma. Ahora la identidad, la biometria y la firma son de la persona; lo
que cambia de empresa es el vinculo.

## 4. Las invariantes viven en la base

Indices unicos reales sobre `(country_id, doc_type, num_doc)`, `(plan_id, form_template_id)`,
`(form_submission_id, form_field_id, row_index)` y las cinco tablas puente. En la v1 esas reglas
solo existian como validaciones de Rails, que no protegen de una condicion de carrera.

## 5. `signature_events` como unica fuente de verdad

Nada de columnas `photo`/`signature` repartidas por las tablas de operacion. Cada firma es un
evento con su archivo, su distancia de coincidencia, su umbral y su origen. La foto **siempre** se
guarda: en la v1 el 83 % de las fotos eran la cadena `detected_by_IA` y no habia archivo.

## 6. Captura por tiempo de espera en vez de umbral estricto

Si el reconocimiento no encuentra coincidencia en `settings.face_timeout_seconds` (30 por defecto),
se captura igual, se deja firmar y el evento queda `pending_review`. Endurecer la verificacion sin
esta valvula es lo que empuja a inventar atajos.

## 7. Verificacion facial en el servidor

El navegador captura y envia; el servidor compara contra `person_biometrics` y decide. En la v1 el
matching corria entero en el navegador con `face-api.js` y el umbral era un campo de texto editable.

## 8. Motor de formatos en vez de una tabla por formato

Ver `docs/FORM-ENGINE.md`. Un formato nuevo pasa de 3-5 dias de trabajo a una configuracion.

## 9. Traduccion de datos en una tabla

`translated_texts` polimorfica en vez de `name_es/name_pt/name_en` repetidas en 16 tablas.

## 10. Un solo motor de PDF

`wicked_pdf`. La v1 usaba `wicked_pdf` en unos modulos y `pdfkit` en otros para lo mismo.

## 11. Soft delete con `discard`

`discarded_at` + `discarded_by_id` + `discard_reason`, en un concern. La v1 replicaba
`is_active`/`is_deleted`/`deleted_description` en 25 tablas con reglas distintas de nulabilidad.

## 12. Permisos declarados en codigo

`config/permissions.yml` define los pares recurso/accion; la base solo guarda cuales tiene cada
perfil. La v1 hacia `constantize` sobre un texto editable desde la interfaz.
