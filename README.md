# DOC APP 2

Sistema de registro de documentos de seguridad en obra. Por cada tarea del día se guardan los
formatos (AST, PTF, EPP, IHM y los que el cliente defina), los trabajadores que la ejecutan, el
supervisor que la aprueba, la empresa contratista y la evidencia firmada de todo ello.

**Base**: este repositorio arranca desde el código de
[TRAFODEX](https://github.com/xwcamor/trafodex) como plataforma SaaS. Se hereda el núcleo
—multi-tenant, permisos, `make:module`, componentes de UI, i18n, auditoría, exportaciones— y se
sustituye el dominio de diagnóstico de transformadores por el de seguridad en obra.

**Sistema anterior**: [`app_documentation`](https://github.com/xwcamor/app_documentation)
(Rails 7.2 + MySQL), que sigue en producción hasta el corte. De él se migran los datos, no el código.

## Stack

PHP 8.4 · Laravel 13 · PostgreSQL 16 · Inertia 3 + Vue 3.5 · Ant Design Vue 4 · Tailwind 4 ·
Spatie Permission · Sanctum · Vite

## Documentación

| Archivo | Contenido |
| --- | --- |
| `docs/PLAN.md` | Plan del sistema nuevo, por fases |
| `docs/MIGRACION.md` | Cómo se migran y se controlan los datos de la v1 |
| `docs/CHECKLIST.md` | Estado real de cada cosa, para ir confirmando |
| `docs/DOMINIO.md` | Qué es un plan, un formato, una firma |
| `CLAUDE.md` | Convenciones (heredadas de TRAFODEX + las propias) |
| `docs/ARCHITECTURE.md` y demás | Documentación heredada de TRAFODEX |

## Puesta en marcha

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm run dev
```
