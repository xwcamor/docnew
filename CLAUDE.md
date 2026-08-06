# Convenciones de DOC APP 2

Este archivo manda sobre cualquier costumbre heredada de la v1. Si algo aquí contradice al código
viejo, gana este archivo.

## Stack

- Ruby 3.3 · Rails 7.2 · MySQL 8
- Vistas ERB + Hotwire (Turbo + Stimulus). **No** se usa jQuery ni vistas `.js.erb`.
- CSS propio sobre tokens Fiori (`app/assets/stylesheets/tokens.css`). Bootstrap 5 solo como base
  de grid y utilidades.
- Un único motor de PDF: `wicked_pdf`.
- Almacenamiento de archivos: Active Storage (no CarrierWave).

## Reglas que no se negocian

1. **Todo cambio de esquema es una migración.** Nada de `ALTER TABLE` a mano en producción: así se
   desalineó la v1. `bin/schema_check` corre antes de cada commit que toque `db/migrate/`.
2. **La base de datos defiende sus invariantes.** Si una regla es "no puede haber dos personas con
   el mismo documento", hay un índice único, no solo una validación de Rails.
3. **Cero valores mágicos en columnas de datos.** Nunca un `photo = "detected_by_IA"`. Si no hay
   archivo, la columna es `NULL` y el motivo vive en su propia columna.
4. **Lo que decide permisos o aprobaciones lo decide el servidor.** Ningún `is_approved` viaja en un
   formulario.
5. **Nada de texto de interfaz en el código.** Todo pasa por `t()`. `i18n-tasks` corre en CI.
6. **Sin lógica de negocio en controladores.** Va en `app/services/{grupo}/`. Un controlador que
   pasa de 80 líneas es una señal de que falta un servicio.
7. **Un módulo nuevo se genera, no se copia.** `bin/rails g docapp:module`.
8. **Todo módulo llega con tests.** Un módulo sin test de sistema no se mergea.

## Nombres

- Controladores: `{Grupo}Management::{Plural}Controller` (`CompanyManagement::CompaniesController`).
- Servicios: `{Grupo}Management::{Accion}{Singular}` (`PlanManagement::ClosePlan`).
- Rutas: `{grupo}_management_{plural}_{accion}`.
- Traducciones: un archivo por módulo, `config/locales/{modulo}.{locale}.yml`.
- Tablas: plural en inglés. Columnas booleanas en positivo (`active`, no `is_not_inactive`).
- Slug público de 22 caracteres (`SecureRandom.alphanumeric(22)`); los `id` no se exponen en URLs.

## Soft delete

Una sola forma: `discarded_at` + `discarded_by_id` + `discard_reason` vía el concern
`SoftDeletable`. Nada de `is_deleted` / `is_active` / `deleted_description` sueltos por tabla como
en la v1.

## Multi-idioma

- Interfaz: archivos YAML por módulo. La tabla de traducciones editable llega después, y como
  respaldo, nunca como fuente única.
- Datos de catálogo: tabla `translated_texts` (registro traducible, campo, locale, valor). **No** se
  añaden columnas `name_es/name_pt/name_en`.
- `default_locale` es `:es`, con fallback `[:es, :en]`.

## Evidencias y firmas

- Toda firma genera un `signature_event` con su `evidence_file`. Sin excepción.
- Si el reconocimiento facial no encuentra coincidencia en 30 segundos, **se toma la foto igual**,
  se permite firmar y el evento queda `method: :timeout_capture`, `pending_review: true`. Nunca se
  bloquea el trabajo en campo.
- Un atajo manual siempre lleva motivo y usuario que lo autorizó.

## Qué NO copiar de la v1

`.js.erb` para todo · `permit!` en los controladores · `constantize` sobre datos de la base ·
`role_id` mágico 1/2/3 · `human_attribute_name` devolviendo cadena vacía · dos motores de PDF ·
tablas de personas duplicadas por tipo · `"F#{document_id}Document".constantize`.

## Qué NO copiar de TRAFODEX

Su organización por grupos de negocio y su sistema de diseño sí se replican. Su stack no:
aquí no hay Inertia, Vue, Ant Design ni PHP. Cuando `docs/UI.md` habla de "Fiori" se refiere a la
paleta y a las convenciones visuales, no a SAPUI5, que TRAFODEX tampoco usa.
