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
| [x] | **La aplicación responde y se navega** | probado en un Chromium real: login, dashboard, planes, personas, empresas, formatos y bandeja de firmas cargan **sin un solo error de JavaScript** |
| [x] | **La base rechaza dos personas con el mismo documento** | probado: lanza la violación de índice único |
| [x] | Flujo completo en base: empresa → cuadrilla → plan → formatos → firmas con evidencia | `DocufizDemoSeeder`: 1 plan, 2 formatos, 2 firmas, 1 pendiente de revisión |

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
| [x] | 23 modelos Eloquent del dominio, con relaciones, casts y constantes | **probados contra la base con un seeder de demostración** |
| [x] | Módulos generados con `make:module`: **Companies**, **People**, **WorkPlans**, **FormTemplates** | 108 rutas registradas, front compila |
| [x] | Menú lateral con los módulos de DOCUFIZ (Trabajo en obra · Maestros) | compila y las rutas resuelven |
| [x] | Rutas protegidas: sin sesión redirigen al login | `work_plans` → 302 |
| [x] | **Alta de persona que reutiliza la identidad** (`PersonService::vincularOCrear`) | probado: la misma persona en dos empresas, **sin identidad nueva**, conservando biometría e historial de firmas |
| [x] | **Servicio de firma con verificación en el servidor** | probado: descriptor idéntico → `face_recognition`; distinto → `timeout_capture` con foto y pendiente de revisión; sin foto y sin coincidencia → rechazado; manual sin motivo → rechazado |
| [x] | Deduplicación de evidencias por hash | probado: 4 referencias, 3 archivos en disco |
| [x] | Revisión de firmas pendientes | probado: rechazar revierte la aprobación |
| [x] | **Servicio de llenado de formatos** | probado: HOJA X no cierra sin la foto del papel; AST no cierra sin sus campos obligatorios; la matriz de riesgo se guarda como JSON |
| [x] | Controladores y rutas de trabajo en obra | **11 rutas registradas**, cada una tras su permiso |
| [x] | Pantallas Vue: lista de formatos, llenado, firma con cámara y bandeja de revisión | el front compila con face-api incluido |
| [x] | **Editor de formatos** (`FormTemplateBuilder`) | probado: crear un formato con campos, publicar, versionar |
| [x] | Tipos de campo compuestos declarados con su configuración obligatoria | probado: un `select` sin opciones no se acepta |
| [x] | Validación del valor según el tipo | probado: una matriz de riesgo mal formada se rechaza |
| [x] | Un formato publicado no se edita: se saca versión nueva | probado: la entrega firmada conserva su versión |
| [x] | **Los cuatro formatos históricos, con los catálogos reales** (`docufiz:migrate-formats`) | leídos de la base anterior: AST con **127 actividades, 83 peligros, 51 controles** y matriz 5×5; PTF con 17 preguntas; EPP con 25 ítems; IHM con 16 herramientas y 10 puntos |
| [x] | Conexión de solo lectura a la base del sistema anterior | `config/database.php` → `legacy`, alimentada por `LEGACY_DB_*` |
| [x] | **Empresas migradas** (`docufiz:migrate-data empresas`) | 22 de 22; una existente se adoptó por RUC en vez de duplicarse |
| [x] | **Personas migradas** (`docufiz:migrate-data personas`) | **402 filas de tres tablas → 228 identidades**, 370 vínculos y 240 firmas. **87 personas trabajan en 2 o más empresas** con una sola identidad |
| [x] | Informe de conflictos de nombre | 13 documentos con nombres distintos entre tablas, listados para revisión manual |
| [x] | **Enrolamiento facial** (`field_work.signatures.enroll`) | 3 muestras guiadas; se guardan los descriptores, **nunca la foto**; exige consentimiento |
| [x] | **Migrar planes, documentos y evidencias** (`docufiz:migrate-data planes\|documentos\|evidencias`) | ejecutado dos veces contra la base real: **3 722 planes, 9 186 asignaciones, 11 166 aprobaciones, 14 435 entregas, 48 522 respuestas, 17 276 firmas**. La segunda pasada informa 0 nuevos y conteos idénticos: es idempotente |
| [x] | Los 26 usuarios de la aplicación (`docufiz:migrate-data usuarios`) | reconstruidos desde `user_details`, porque `users` vino vacía. Correos provisionales, contraseñas aleatorias sin anotar |
| [ ] | Copiar los archivos físicos de `public/images_uploads` | el comando `docufiz:migrate-data archivos --desde=…` está probado; **faltan los 4 027 ficheros del servidor viejo** |
| [x] | Modelos de face-api.js en el repositorio (6,8 MB) | no se descargan en tiempo de ejecución |
| [x] | Verificación facial en el navegador con los dos relojes | portada a `useFaceVerify` |
| [x] | **Reto de vida** (gesto de cabeza al azar, con vuelta al centro) | implementado en `useFaceVerify`; no bloquea: si falla, firma y queda pendiente de revisión |
| [x] | Enrolamiento desde la app | 3 muestras guiadas al ir a firmar sin cara registrada |
| [ ] | Probar la cámara en una tablet real | **no verificable desde aquí** |
| [x] | **Tipos de campo compuestos en pantalla** (matriz de riesgo, EPP por trabajador, IHM por herramienta, banco de preguntas) | probado: la pantalla emite exactamente lo que exige `validarValor()`, con una prueba por tipo |
| [ ] | Editor de formatos desde la interfaz | el servicio existe (`FormTemplateBuilder`); falta la pantalla |
| [x] | **PDF firmado de la entrega** | probado: membrete, versión congelada, adjuntos, bloque de firmas con foto de evidencia incrustada del disco privado, y las pendientes marcadas |
| [x] | Módulos, roles y permisos de DOCUFIZ | **16 módulos, 117 permisos, 4 perfiles creados en base** |
| [x] | `npm install` y `npm run build` | **compila sin errores** |
| [x] | **Conjunto de pruebas completo en verde** | **603 pasan, 0 fallan**, 19 omitidas |
| [x] | **Alertas de seguridad de dependencias** | de 44 avisos en 14 paquetes (1 crítico) a **0**, sin cambiar ninguna versión mayor |

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
