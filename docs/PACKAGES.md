# Paquetes y librerías

**Qué es esto**: inventario real de los paquetes que usa el proyecto, su rol y por qué se eligieron.

**Para qué sirve**: tener una vista rápida del stack completo. Si una librería queda obsoleta o quieres reemplazarla, este es el primer doc que tienes que revisar.

**Cuándo leerlo**: antes de sumar un paquete nuevo (a ver si ya hay uno que cubre lo mismo) o cuando hagas la auditoría de seguridad periódica.

---

## Backend (PHP / Composer)

### Producción (`require`)

| Paquete | Versión | Para qué |
|---|---|---|
| `laravel/framework` | ^13.0 | Framework core. |
| `laravel/sanctum` | ^4.2 | Bearer tokens con abilities para la capa API REST. |
| `laravel/socialite` | ^5.23 | Login con Google OAuth (toggleable desde Settings). |
| `laravel/reverb` | ^1.5 | WebSockets para notificaciones realtime (skeleton — no usado por defecto). |
| `laravel/tinker` | ^3.0 | REPL para depuración rápida. |
| `inertiajs/inertia-laravel` | ^3.0 | Glue entre Laravel y Vue 3 (sin API REST intermedia). |
| `tightenco/ziggy` | ^2.0 | Expone rutas nombradas de Laravel al frontend como `route('x.y')`. |
| `spatie/laravel-permission` | ^6.21 | Roles + permisos + super bypass vía `Gate::before`. |
| `mcamara/laravel-localization` | ^2.3 | Detección de locale del browser + prefijo `/{locale}/` en URLs. |
| `maatwebsite/excel` | ^3.1 | Exports a Excel + Imports CSV/XLSX. |
| `phpoffice/phpword` | ^1.4 | Exports a Word (DOCX). |
| `barryvdh/laravel-dompdf` | ^3.1 | Exports a PDF. |
| `knuckleswtf/scribe` | ^5.9 | Genera la documentación interactiva de la API REST (`/docs`). |

### Desarrollo (`require-dev`)

| Paquete | Versión | Para qué |
|---|---|---|
| `phpunit/phpunit` | ^11.5 | Suite de tests (453 passing, 19 skipped justificados). |
| `mockery/mockery` | ^1.6 | Mocking en tests. |
| `fakerphp/faker` | ^1.23 | Fake data para factories + tests. |
| `nunomaduro/collision` | ^8.6 | Mejor formato de errores en CLI. |
| `laravel/pint` | ^1.25 | Formatter PHP (PSR-12). |
| `laravel/pail` | ^1.2 | Tail de logs en tiempo real (`php artisan pail`). |
| `laravel/sail` | ^1.41 | Docker dev (no se usa en este proyecto — Laragon en Windows). |
| `barryvdh/laravel-debugbar` | ^4.2 | Toolbar de debug en dev (controlado por `APP_DEBUG`). |

---

## Frontend (Node / npm)

### Producción (`dependencies`)

| Paquete | Versión | Para qué |
|---|---|---|
| `vue` | ^3.5 | Framework UI principal. |
| `@inertiajs/vue3` | ^3.0 | Cliente Inertia para Vue 3 (page transitions sin SPA). |
| `ant-design-vue` | ^4.2 | Component library principal (Form, Table, Drawer, Modal, etc.). |
| `@ant-design/icons-vue` | ^7.0 | Icon library de Antd. |
| `ag-grid-community` + `ag-grid-vue3` | ^35.2 | Grid avanzado para edición masiva (EditAll). |
| `@tiptap/vue-3` + extensiones | ^3.23 | Editor rich-text para mensajes del Inbox. |
| `@formkit/auto-animate` | ^0.9 | Animaciones sutiles en list reorder. |

### Desarrollo (`devDependencies`)

| Paquete | Versión | Para qué |
|---|---|---|
| `vite` | ^7.0 | Bundler + dev server. |
| `@vitejs/plugin-vue` | ^6.0 | Soporte Vue en Vite. |
| `laravel-vite-plugin` | ^2.0 | Integración Vite + Laravel (HMR, manifest). |
| `tailwindcss` + `@tailwindcss/vite` | ^4.0 | Utility CSS. |
| `axios` | ^1.11 | Cliente HTTP (XHR + CSRF). |
| `concurrently` | ^9.0 | Correr varios procesos juntos (no usado por defecto). |

---

## Por qué estos paquetes y no otros

| Decisión | Alternativa descartada | Por qué |
|---|---|---|
| Inertia.js | SPA con API REST + JWT | 1 deploy, sin CORS, sin tokens en frontend. Detalle en [`ARCHITECTURE.md`](ARCHITECTURE.md). |
| Ant Design Vue | Vuetify, PrimeVue | Tablas + Form mucho más maduros para apps de gestión. |
| Spatie Permission | Custom ACL desde cero | Es el estándar de facto. Roles + permisos + super bypass funciona out-of-the-box. |
| PostgreSQL | MySQL | `unaccent` + JSONB + window functions. Ver [`ARCHITECTURE.md`](ARCHITECTURE.md). |
| Sin Redis | Redis 7 | Cache de queries es premature optimization para el volumen esperado. Decisión consciente. |

---

## Documentación relacionada

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — por qué se eligió cada tecnología
- [`STRUCTURE.md`](STRUCTURE.md) — dónde vive cada cosa en el repo
- [`../README.md`](../README.md) — portada general del sistema
- [`INSTALL-TOOLS.md`](INSTALL-TOOLS.md) — instalación de dependencias del SO antes de `composer install` y `npm install`
