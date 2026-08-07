# Pendientes y decisiones abiertas

Todo lo que quedó sin confirmar, sin terminar o marcado para mejorar. Nada se da por cerrado en
silencio.

## Necesito que lo confirmes

| # | Asunto | Estado |
| --- | --- | --- |
| 1 | **Jerarquía duplicada.** TRAFODEX trae `Customer → ubicación → área → subestación`. El dominio nuevo trae `companies` + `work_locations` + `work_areas` + `workstations`. Se solapan | conservé las dos: la de TRAFODEX está entretejida con usuarios, roles, buscador y bloqueo de registros, y borrarla ahora rompía el núcleo. Hay que unificarlas |
| 2 | **`Brand`** (marca de transformador) sigue en el proyecto porque es la plantilla que clona `make:module`. Borrarla deja el generador inservible | ¿lo renombramos a algo neutro tipo `Sample`/`Plantilla` o lo dejamos oculto? |
| 3 | **`Customer`**: ¿es la empresa contratista de DOCUFIZ, o el cliente del SaaS y las contratistas van en `companies`? | hoy conviven las dos cosas |
| 4 | **Usuarios falsos**: creé **26**, uno por cada `user_detail` del volcado. Mencionaste 29 | confirmar el número correcto |
| 5 | **Contraseñas**: pensaba no migrar los hashes, pero Rails y Laravel usan el mismo bcrypt, así que **sí se pueden migrar tal cual** | decidir: migrarlas o forzar cambio en el primer ingreso |
| 6 | **Suscripciones y planes de facturación** de TRAFODEX se conservaron | ¿DOCUFIZ se vende como SaaS a varias empresas o es instalación única? |
| 7 | **Formatos AST y PTF**: quedaron fuera del motor de formatos por volumen (226 875 y 62 254 filas) | revisar al final, con datos de rendimiento |
| 8 | **Multi-país**: el 100 % de los planes del sistema viejo son de Perú | se mantiene la estructura, sin invertir más |

## Decisiones tomadas que conviene recordar

| Asunto | Decisión |
| --- | --- |
| Umbral facial | arranca en 0,50 (el de tenkofiz) y se ajusta con las distancias reales que registre el sistema, no por corazonada |
| Firma por tiempo de espera | no bloquea el trabajo: firma, guarda la foto y queda pendiente de revisión |
| Evidencias de firma | no se purgan a los 90 días como en tenkofiz: son documentación legal |
| Motor de formatos | se usa para los formatos nuevos; los históricos se evalúan después |
| Trabajo en campo | sigue en navegador (tablets Android); sin app nativa ni API por ahora |

## Trabajo técnico pendiente

1. ~~Adaptar `CLAUDE.md`~~ — hecho.
2. Revisar los documentos heredados en `docs/` que aún hablan de transformadores
   (`ARCHITECTURE.md`, `MANUAL-CLIENTE.md`, `PERMISSIONS.md`, `FRONTEND.md`…).
3. ~~`npm install` y compilar el front~~ — hecho, compila sin errores.
4. ~~Menú lateral y traducciones~~ — limpiados; faltan las entradas de los módulos nuevos,
   que se añaden cuando cada módulo exista (una entrada a una ruta inexistente rompe la página).
5. ~~Seeder de roles y permisos~~ — reescrito con los módulos y perfiles de DOCUFIZ.
6. Tests: los del dominio viejo se borraron; faltan los del dominio nuevo.
7. Flujo de aprobación de documentos: el de TRAFODEX se borró con los informes de diagnóstico.
   La estructura ya está (`approval_rules` + `work_plan_approvals`), falta la pantalla.
10. Los módulos `Companies`, `People` y `WorkPlans` se generaron clonando `Brand`, así que trae `code`/`sort_order` renombrados
    a las columnas reales. Falta revisar sus vistas una por una: el formulario y el listado
    todavía tratan la segunda columna como si fuera un número de orden, y les faltan los campos
    propios (país, nacionalidad, empresa, tipo de trabajo, fechas).
11. `DocufizDemoSeeder` no está en `DatabaseSeeder`: se ejecuta a mano con
    `php artisan db:seed --class=DocufizDemoSeeder`. Decidir si entra en el sembrado por defecto.
8. Índices únicos que faltan en el sistema viejo: hay que resolver antes el duplicado `47019239`.
9. Auditar `public/images_uploads` del sistema viejo contra las 4 189 referencias de la base.

## Hallazgos de esta tanda

- El `.git/config` del repositorio se corrompió durante `composer install` (dejó `origin` apuntando
  a `symfony/routing`). Se reparó, pero conviene revisarlo si vuelve a fallar un `push`.
- `resources/js/Utils` se importaba como `@/utils` en dos archivos: funcionaba en macOS y falla en
  Linux por mayúsculas. Corregido.
- El seeder de roles usaba `updateOrCreate` con `tenant_id => null`, que nunca casa en SQL y
  duplicaba filas. Es un defecto heredado de TRAFODEX: sigue ahí para los roles del núcleo.

## Corregido sobre la marcha

- **La purga se llevó por delante dos archivos de rutas enteros.** `routes/system_management.php`
  pasó de 289 líneas a 14 y `routes/api.php` de 92 a 34, porque el grupo exterior mencionaba un
  controlador borrado y mi script eliminaba la sentencia completa. Resultado: 167 rutas dejaban de
  existir, y `route('report.verify')` en la pantalla de login lanzaba un error de Ziggy que dejaba
  **toda la aplicación en blanco**. Restaurados desde git y recortados solo en las rutas muertas.
- El panel de flota del dashboard y el payload de aprobaciones seguían consultando modelos borrados.
  El primero se reemplazó por el panel del día de DOCUFIZ; el segundo devuelve vacío.
- Se retiraron los últimos enlaces al dominio viejo en el buscador global, la ficha de cliente y los
  atajos de teclado.

**Lección para la siguiente purga**: borrar por marcador de sección es seguro; borrar sentencias
`Route::` que *contengan* un texto no lo es, porque un grupo entero es una sola sentencia.


- `evidence_files.sha256` estaba declarado único, lo cual contradecía la propia deduplicación: si un
  archivo se guarda una vez y lo referencian varios eventos, el hash **se repite**. Ahora el hash va
  indexado y lo único es el par (evento, tipo).

## Nota operativa

`make:module` consulta la base al registrar el módulo en `system_modules`. Si PostgreSQL no está
levantado, el comando **revierte todo lo generado** (lo hace bien: deja el proyecto limpio), pero hay
que volver a ejecutarlo con la base arriba.

- El nombre del producto vive en la tabla `settings` (`app.name`). El seeder ya dice DOCUFIZ, pero
  en una base ya sembrada la fila conserva el valor anterior: se cambia desde Configuración o
  volviendo a sembrar desde cero.
