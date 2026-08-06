# DOC APP 2 (`docnew`)

Registro de documentos de seguridad en obra: por cada tarea del día se guardan los formatos
(AST, PTF, EPP, IHM y los que el propio cliente defina), los trabajadores que la ejecutan, el
supervisor que la aprueba, la empresa contratista y la evidencia firmada de todo ello.

Es la segunda generación de [`app_documentation`](https://github.com/xwcamor/app_documentation),
que sigue en producción. Este repositorio arranca con la arquitectura objetivo definida en
`docs/PLAN_MIGRACION_DOCAPP.md` de aquel repo, y se puebla con los datos reales mediante el
proceso de `docs/DATA-MIGRATION.md`.

## Qué cambia respecto a la versión 1

| Problema en v1 | Solución en v2 |
| --- | --- |
| Cada formato nuevo cuesta 3–5 días (modelo + controlador + vistas + PDF + rutas) | Motor de formatos: se definen desde la UI, sin escribir código |
| No se podían subir formatos "en papel" | Tipo de formato `upload_only`: se fotografía y se adjunta |
| Una persona duplicada por cada empresa (391 filas para 231 personas) | Una identidad `people` + vínculos `person_company_links` |
| Foto y firma en tres sitios a la vez, y el 83 % eran el texto `detected_by_IA` | `signature_events` + `evidence_files`: una sola fuente de verdad, la foto siempre se guarda |
| Verificación facial en el navegador, manipulable | Verificación en servidor, con `match_distance` y umbral auditado |
| `is_approved` venía en el HTML del formulario | Lo calcula el servidor |
| `name_es/name_pt/name_en` en 16 tablas | Traducción de datos en una sola tabla |
| 57 controladores copiados a mano | `ResourceController` + generador de módulos |
| Tests de otro proyecto, 0 % de cobertura real | Suite propia desde el primer módulo |

## Estado

Fase inicial: fundaciones. Ver `docs/ROADMAP.md` para el detalle de lo hecho y lo pendiente.

## Puesta en marcha

```bash
bundle install
cp config/database.yml.example config/database.yml   # ajustar credenciales
bin/rails db:create db:migrate db:seed
bin/rails s
```

Antes de cada commit que toque `db/migrate/`:

```bash
bin/schema_check          # valida las migraciones y genera el DDL de previsualización
```

## Documentación

| Archivo | Contenido |
| --- | --- |
| `CLAUDE.md` | Convenciones del proyecto (léelo antes de escribir código) |
| `docs/ARCHITECTURE.md` | Decisiones técnicas y por qué |
| `docs/STRUCTURE.md` | Mapa de carpetas |
| `docs/CREATE-MODULE.md` | Cómo se añade un módulo de negocio |
| `docs/UI.md` | Sistema de diseño (tokens Fiori) |
| `docs/FORM-ENGINE.md` | Motor de formatos |
| `docs/DATA-MIGRATION.md` | Traer los datos de la v1 |
| `docs/ROADMAP.md` | Estado por fases |
