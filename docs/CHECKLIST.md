# Checklist del proyecto

Se actualiza en cada entrega. `[x]` es algo hecho **y verificado**; si algo está hecho pero sin
verificar, se dice explícitamente.

## Sistema viejo (`app_documentation`) — saneamiento

| | Tarea | Verificación |
| --- | --- | --- |
| [x] | Migración `create_settings` arreglada: declaraba `sidebar_color_text` dos veces y nunca creaba `header_color_text`, así que `db:migrate` fallaba desde cero | validador propio + revisión de las 22 migraciones |
| [x] | `db/schema.rb` alineado con las migraciones y con producción (`is_hidden`, columnas de `settings`, fuera `photo_evidences` y `signature_events`) | comparación tabla a tabla contra el volcado: 64 = 64 |
| [x] | `db/seeds.rb` usaba `is_visible`, columna inexistente (7 usos) | `ruby -c` + revisión |
| [x] | Cache de i18n: la clave ignoraba las opciones y servía traducciones interpoladas de otra llamada durante 12 h | revisión del initializer |
| [x] | `default_locale = :es` + fallbacks (estaba comentado; caía a inglés) | — |
| [x] | `resource.language&.locale` en 3 controladores (tumbaba el login) | — |
| [x] | `ability.rb`: fuera el `can :read, :all` por defecto | — |
| [x] | Borrado el código muerto: formato F5 completo (controlador + 18 vistas + rutas), migración huérfana, `PhotoEvidence`, generador de SQL sin sanitizar, rake rota | `grep` de referencias antes de borrar |
| [x] | Los 26 usuarios que faltaban en el volcado, reconstruidos | **carga real en MySQL: 26 usuarios, 3 722 planes, 0 huérfanos** |
| [ ] | Índices únicos que faltan (`workers`, 5 tablas puente) | pendiente: hay que resolver antes el duplicado `47019239` |
| [ ] | Suite de tests real (la actual prueba modelos de otro proyecto) | pendiente |
| [ ] | Auditoría de `public/images_uploads` contra las 4 189 referencias | pendiente |

## Sistema nuevo (`docnew`)

### Base

| | Tarea | Verificación |
| --- | --- | --- |
| [x] | TRAFODEX copiado como base SaaS (1 826 archivos) | — |
| [x] | Identidad cambiada a DOC APP (`.env.example`, `composer.json`, README) | — |
| [ ] | Quitar el dominio de transformadores (modelos, migraciones, páginas, rutas, seeders) | pendiente, módulo por módulo |
| [ ] | `composer install` + `npm install` + `php artisan migrate` en limpio | **pendiente: no ejecutado todavía** |
| [ ] | Seeders propios (país, idiomas, ajustes, roles, permisos) | pendiente |

### Dominio

| | Tarea | Verificación |
| --- | --- | --- |
| [x] | 5 migraciones del dominio: organización, personas, planes, motor de formatos, evidencias | `php -l` sin errores |
| [ ] | Modelos Eloquent con sus traits | pendiente |
| [ ] | Módulos de catálogo con `make:module` | pendiente |
| [ ] | Módulo de personas | pendiente |
| [ ] | Módulo de planes de trabajo | pendiente |
| [ ] | Motor de formatos | pendiente |
| [ ] | Firma con verificación en servidor y captura por tiempo de espera | pendiente |

### Documentación

| | Tarea |
| --- | --- |
| [x] | `docs/PLAN.md` — plan por fases |
| [x] | `docs/MIGRACION.md` — pasos, control y checklist de corte |
| [x] | `docs/DOMINIO.md` — qué es cada cosa |
| [x] | `docs/CHECKLIST.md` — este archivo |
| [ ] | `CLAUDE.md` adaptado (hoy es todavía el de TRAFODEX) |

## Decisiones tomadas

| Fecha | Decisión |
| --- | --- |
| 06-08 | Sistema nuevo en PHP/Laravel sobre TRAFODEX, no refactor del Rails |
| 06-08 | El motor de formatos se usa para los formatos nuevos; AST y PTF se evalúan al final |
| 06-08 | Biometría con captura por tiempo de espera (30 s), umbral 0.5 hasta tener datos reales |
| 06-08 | Trabajo en campo sigue en navegador (tablets Android); sin app nativa ni API por ahora |
| 06-08 | Multi-país se mantiene, sin invertir más en él |

## Sin resolver

- Qué se hace con `Brand` y `Customer`, las plantillas de `make:module`: se quedan como referencia
  o se convierten en módulos propios.
- Nombre definitivo del producto y del tenant inicial.
