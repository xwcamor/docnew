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

## Cómo se verificó

- `php -l` sobre los 1 156 archivos PHP: sin errores de sintaxis.
- Búsqueda de referencias a clases borradas en `app/`, `routes/`, `config/`, `database/`.
- Las secciones de rutas se cortaron por sus marcadores de módulo, no por línea suelta.
