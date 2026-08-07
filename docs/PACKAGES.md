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
| `barryvdh/laravel-dompdf` | ^3.1 | Exports a PDF, incluido el **PDF firmado de una entrega**, que es el documento que la empresa conserva. |
| `rap2hpoutre/fast-excel` | ^5.6 | Lectura y escritura de hojas en streaming, para volúmenes que no caben en RAM. |
| `endroid/qr-code` | ^5.1 | Códigos QR. |
| `knuckleswtf/scribe` | ^5.9 | Genera la documentación interactiva de la API REST (`/docs`). |

### Desarrollo (`require-dev`)

| Paquete | Versión | Para qué |
|---|---|---|
| `phpunit/phpunit` | ^11.5 | La suite de pruebas. Es la comprobación que encontró todo lo que `php -l` no vio durante la purga (ver `PURGA.md`). |
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
| `vue` | ^3.5 | Framework de UI. |
| `@inertiajs/vue3` | ^3.0 | Cliente Inertia para Vue 3. |
| `ant-design-vue` | ^4.2 | Biblioteca de componentes principal (Form, Table, Drawer, Modal…). |
| `@ant-design/icons-vue` | ^7.0 | Sus iconos. |
| `@vladmandic/face-api` | ^1.7 | **Reconocimiento facial en el navegador**: detección, 68 puntos faciales (que alimentan el reto de vida) y el descriptor de 128 números. Los pesos viven en `public/models/`, no se descargan en ejecución. Ver [`BIOMETRIA.md`](BIOMETRIA.md). |
| `ag-grid-community` + `ag-grid-vue3` | ^35.2 | Grid para la edición masiva (EditAll). |
| `@amcharts/amcharts5` | ^5.18 | Los medidores del dashboard (`AmGauge.vue`). Licencia por `VITE_AMCHARTS_LICENSE`. |
| `echarts` + `vue-echarts` | ^6.1 / ^8.0 | El resto de gráficos y el mapa. |
| `@tiptap/vue-3` + extensiones | ^3.23 | Editor de texto enriquecido para los mensajes del Inbox. |
| `write-excel-file` | ^4.0 | Genera XLSX del lado del cliente, sin pasar por el servidor. |
| `driver.js` | ^1.4 | Los recorridos guiados de cada módulo (`useModuleTour`). |
| `@formkit/auto-animate` | ^0.9 | Animaciones sutiles al reordenar listas. |

### Desarrollo (`devDependencies`)

| Paquete | Versión | Para qué |
|---|---|---|
| `vite` | ^7.0 | Empaquetador y servidor de desarrollo. |
| `@vitejs/plugin-vue` | ^6.0 | Soporte de Vue en Vite. |
| `laravel-vite-plugin` | ^2.0 | Integración Vite + Laravel (HMR, manifest). |
| `tailwindcss` + `@tailwindcss/vite` | ^4.0 | CSS de utilidades. |
| `axios` | ^1.11 | Cliente HTTP (XHR + CSRF). |
| `playwright` | ^1.62 | Navegador sin interfaz, para comprobar que una pantalla carga de verdad. |
| `concurrently` | ^9.0 | Levanta servidor, cola, logs y Vite a la vez (`composer dev`). |

---

## Por qué estos paquetes y no otros

| Decisión | Alternativa descartada | Por qué |
|---|---|---|
| Inertia.js | SPA con API REST + JWT | 1 deploy, sin CORS, sin tokens en frontend. Detalle en [`ARCHITECTURE.md`](ARCHITECTURE.md). |
| Ant Design Vue | Vuetify, PrimeVue | Tablas + Form mucho más maduros para apps de gestión. |
| Spatie Permission | Custom ACL desde cero | Es el estándar de facto. Roles + permisos + super bypass funciona out-of-the-box. |
| PostgreSQL | MySQL | `unaccent` + JSONB + window functions. Ver [`ARCHITECTURE.md`](ARCHITECTURE.md). |
| Sin Redis | Redis 7 | Cachear consultas es optimización prematura para el volumen esperado. Decisión consciente. |
| `@vladmandic/face-api` en el navegador | Un servicio de reconocimiento en la nube | El vídeo no sale del dispositivo, no hay coste por llamada, y no se le entrega la cara de un trabajador a un tercero. La comparación que decide se rehace igualmente en el servidor. |
| Sin `sentry/sentry-laravel` | Sentry | **No está instalado.** `.env.example` tiene las claves preparadas, pero el paquete no está en `composer.json` y no hay `config/sentry.php`. Ver [`SENTRY.md`](SENTRY.md). |

---

## Documentación relacionada

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — por qué se eligió cada tecnología
- [`STRUCTURE.md`](STRUCTURE.md) — dónde vive cada cosa en el repo
- [`../README.md`](../README.md) — portada general del sistema
- [`INSTALL-TOOLS.md`](INSTALL-TOOLS.md) — instalación de dependencias del SO antes de `composer install` y `npm install`
