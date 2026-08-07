# Checklist

`[x]` = hecho **y verificado**, con la prueba anotada. Si algo está hecho pero sin verificar, se dice.

## Base heredada de TRAFODEX

| | Tarea | Verificación |
| --- | --- | --- |
| [x] | Código de TRAFODEX copiado como base SaaS | 1 826 archivos |
| [x] | Dominio de transformadores purgado | 331 archivos y carpetas eliminados |
| [x] | Referencias cruzadas reparadas (rutas, seeders, config, Inertia, comentarios, automatizaciones) | `php -l` sobre 1 156 archivos PHP: **0 errores** |
| [x] | Migraciones huérfanas que apuntaban a tablas borradas, eliminadas | 3 encontradas al ejecutar |
| [x] | Renombrado a DOCUFIZ (`.env`, `composer.json`, `package.json`, README) | — |
| [x] | `composer install` | Laravel 13.9.0 arranca |
| [x] | **`php artisan migrate:fresh` contra PostgreSQL 16** | **69 tablas creadas, 0 errores** |
| [x] | **`php artisan db:seed`** | tenants, suscripciones y 175 clientes sembrados |
| [x] | Menú lateral y traducciones sin módulos borrados | 0 rutas muertas en `AppLayout.vue` |
| [x] | **La aplicación responde**: `/` redirige a login y `/es/login` devuelve 200 | probado con el servidor levantado |

## Dominio DOCUFIZ

| | Tarea | Verificación |
| --- | --- | --- |
| [x] | 5 migraciones: organización, personas, planes, motor de formatos, evidencias | ejecutadas en PostgreSQL |
| [x] | `companies`, `work_locations`, `workstations`, `work_areas`, `work_types`, `positions`, `nationalities` | en base |
| [x] | `people` + `person_company_links` + `person_roles` + `person_biometrics` + `person_signatures` | en base |
| [x] | `work_plans`, `work_plan_people`, `approval_rules`, `work_plan_approvals` | en base |
| [x] | `form_templates` … `form_attachments` (motor de formatos) | en base |
| [x] | `signature_events` + `evidence_files` | en base |
| [x] | Índice único real de identidad `(tenant, país, tipo doc, documento)` | índice parcial de PostgreSQL creado |
| [ ] | Modelos Eloquent con sus traits | pendiente |
| [ ] | Módulos con `make:module` (catálogos → personas → planes → formatos) | pendiente |
| [ ] | Motor de formatos: editor y tipos de campo | pendiente |
| [ ] | Firma facial portada de tenkofiz | diseñada en `docs/BIOMETRIA.md`, sin implementar |
| [x] | Módulos, roles y permisos de DOCUFIZ | **16 módulos, 117 permisos, 4 perfiles creados en base** |
| [x] | `npm install` y `npm run build` | **compila sin errores** |

## Documentación

| | Archivo |
| --- | --- |
| [x] | `docs/PLAN.md` · `docs/DOMINIO.md` · `docs/MIGRACION.md` · `docs/BIOMETRIA.md` · `docs/PURGA.md` |
| [x] | `docs/CHECKLIST.md` (este) · `docs/PENDIENTES.md` |
| [x] | `CLAUDE.md` adaptado a DOCUFIZ (conserva las convenciones heredadas) |

## Sistema viejo (`app_documentation`)

| | Tarea | Verificación |
| --- | --- | --- |
| [x] | Migración rota arreglada (columna declarada dos veces: `db:migrate` fallaba desde cero) | validador propio |
| [x] | `schema.rb` y `seeds.rb` alineados con producción | comparación tabla a tabla: 64 = 64 |
| [x] | Cache de i18n, locale por defecto, login nil-safe, permisos por defecto cerrados | — |
| [x] | Código muerto eliminado (formato F5, migración huérfana, SQL sin sanitizar) | `grep` previo de referencias |
| [x] | Los 26 usuarios que faltaban en el volcado | **carga real en MySQL: 26 usuarios, 3 722 planes, 0 huérfanos** |
