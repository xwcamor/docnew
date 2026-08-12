# DOCUFIZ

Documentación diaria de seguridad en obra. Por cada tarea del día se registran los formatos
(AST, PTF, EPP, IHM y los que la empresa defina), los trabajadores que la ejecutan, el supervisor
que la aprueba, la empresa contratista y la evidencia firmada de todo ello.

## De dónde viene cada pieza

| Origen | Qué aporta |
| --- | --- |
| [TRAFODEX](https://github.com/xwcamor/trafodex) | la base SaaS: multi-tenant, permisos, `make:module`, componentes de UI, i18n, auditoría, exportaciones, apariencia Fiori |
| [tenkofiz](https://github.com/xwcamor/tenkofiz) | el mecanismo de marcación facial: descriptor de 128 puntos, verificación 1:1, reto de vida y captura por tiempo de espera |
| [app_documentation](https://github.com/xwcamor/app_documentation) | el dominio y los datos: 3 722 planes, 231 personas, 22 empresas. Se migran los datos, no el código |

## Stack

PHP 8.4 · Laravel 13 · PostgreSQL 16 · Inertia + Vue 3.5 · Ant Design Vue 4 · Tailwind 4 ·
Spatie Permission · Sanctum · Vite

## Puesta en marcha

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan setup:project
npm run dev
```

`setup:project` rehace la base, migra y siembra. Con `--datos` trae además todo el sistema anterior
—plantillas, empresas, personas, planes, formatos llenados y firmas— en la misma orden; necesita
`LEGACY_DB_*` en el `.env` y está explicado en `docs/BASE-DE-DATOS-LOCAL.md`.

## Documentación propia

| Archivo | Contenido |
| --- | --- |
| `docs/SISTEMA.md` | **El sistema entero, de punta a punta: qué hace cada módulo y cómo encajan** |
| `docs/PLAN.md` | Plan por fases |
| `docs/UI.md` | **Estándar de interfaz — de obligado cumplimiento en módulos nuevos** |
| `docs/DOMINIO.md` | Qué es un plan, un formato, una firma |
| `docs/BIOMETRIA.md` | Cómo se firma con reconocimiento facial |
| `docs/MIGRACION.md` | Cómo se traen y se controlan los datos del sistema viejo |
| `docs/BASE-DE-DATOS-LOCAL.md` | **Migrar en tu máquina y subir la base ya terminada** |
| `docs/DESPLIEGUE-POSTGRES.md` | Varias bases en un servidor, aisladas entre sí |
| `docs/PURGA.md` | Qué se quitó de TRAFODEX y qué se conservó |
| `docs/CHECKLIST.md` | Estado real, verificado, de cada cosa |
| `docs/PENDIENTES.md` | **Todo lo que quedó abierto o sin confirmar** |

El resto de `docs/` viene de TRAFODEX y describe la base heredada.
