# Purga del dominio heredado

TRAFODEX es un SaaS de diagnóstico de transformadores. DOCUFIZ conserva su plataforma y elimina
su dominio. Esto documenta qué se fue, qué se quedó y por qué.

## Eliminado (331 archivos y carpetas)

Transformadores y su flota · muestras y análisis · cromatografía y gases disueltos (DGA) ·
calculadora Duval · fisicoquímicos (fiqui) · furanos · FPOT · tipos de aceite · laboratorios ·
conmutadores (tap changers: marcas, modelos, tecnologías, tipos) · motor de reglas de diagnóstico ·
escalas de resultado · índice de salud · informes de diagnóstico con su flujo de aprobación,
firmantes y compartición pública · datasets de diagnóstico · marcas de transformador ·
comparador de muestras · seeders de datos legados · sus tests, traducciones, páginas Vue,
componentes, configuraciones, rutas y documentación.

## Conservado del núcleo

Tenants y aislamiento multi-empresa · usuarios, roles y permisos (Spatie + `system_modules`) ·
`make:module` · auditoría (`audit_logs` + `Auditable`) · ajustes · países, idiomas, locales y
regiones · suscripciones y planes de facturación · notificaciones y mensajería · descargas ·
favoritos, vistas recientes y vistas guardadas · automatizaciones · comentarios polimórficos ·
componentes Vue comunes · layouts y sistema de diseño · exportadores e importadores genéricos.

## Conservado con reparo (ver `docs/PENDIENTES.md`)

| Qué | Por qué se quedó | Qué hay que decidir |
| --- | --- | --- |
| `Brand` | es la plantilla que clona `make:module`; borrarlo deja el generador inservible | renombrarlo a algo neutro o dejarlo como plantilla oculta |
| `Customer` y su jerarquía (ubicación → área → subestación) | está entretejido con `User`, `Role`, automatizaciones, buscador y bloqueo de registros | se solapa con `companies` / `work_locations` / `work_areas` / `workstations` del dominio nuevo |

## Restos que quedaron en pie, y lo que costaban

`php -l` no detecta una clase que falta: un archivo que dice `hasMany(Transformer::class)` es
sintácticamente perfecto y revienta en tiempo de ejecución. Por eso lo siguiente sobrevivió a la
primera pasada, y salió a la luz al poner el conjunto de pruebas en verde.

| Resto | Qué provocaba |
| --- | --- |
| Siete modelos con `hasMany(Transformer::class)` — incluidos `Company`, `Person`, `WorkPlan` y `FormTemplate`, que lo heredaron al clonarse de `Brand` | error al usar la relación |
| `SearchController` consultaba `Transformer` en cada pulsación del buscador global | **500 en el buscador**, para cualquier usuario con permiso |
| El módulo de clientes contaba transformadores en el listado, en la jerarquía y en las cuatro exportaciones | **500 al abrir el listado de clientes** |
| `ReportSigner` borrado, pero su pantalla y su controlador seguían llamándolo | error al abrir «Mi workspace» |
| Migración que añadía `notify_approval_by_email` junto a una columna que ya no existía | migración huérfana |
| Siete archivos de pruebas del dominio de transformadores y diagnóstico | 59 pruebas rojas, que tapaban las regresiones de verdad |

**`ReportSigner` volvió.** No era dominio de transformadores: es *quién firma el documento
generado*, y eso DOCUFIZ lo necesita. Se fue por arrastre, porque estaba enredado con el flujo de
aprobación de informes de diagnóstico. Ese flujo sí se queda fuera: la aprobación en DOCUFIZ vive en
`work_plan_approvals` y se firma con la cara, no por correo.

**El buscador global se rehízo** para lo que de verdad se busca en obra: un plan por su código u
orden de servicio, una empresa por nombre o RUC, una persona por nombre o documento.

## Cómo se verificó

- `php -l` sobre los 1 156 archivos PHP: sin errores de sintaxis.
- Búsqueda de referencias a clases borradas en `app/`, `routes/`, `config/`, `database/`.
- Las secciones de rutas se cortaron por sus marcadores de módulo, no por línea suelta.
- **El conjunto de pruebas completo en verde**: 557 pasan, 0 fallan. Esa es la comprobación que
  encontró todo lo de la tabla de arriba, y la razón por la que no se deja ninguna prueba roja
  «heredada»: una suite roja no avisa de nada.

**Lección**: una purga no termina cuando el código compila. Termina cuando las pruebas pasan y las
pantallas cargan. Lo que `php -l` aprueba puede seguir siendo un 500.
