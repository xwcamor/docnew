# Estructura del proyecto

**Qué es esto**: mapa de carpetas y archivos relevantes para entender dónde vive cada cosa del sistema.

**Para qué sirve**: orientarse rápido cuando hay que encontrar un controller, un componente Vue, una migración, una traducción o un job. También sirve como referencia al crear módulos nuevos para saber dónde van los archivos generados.

**Cuándo leerlo**: la primera vez que abres el proyecto y cada vez que dudes "¿dónde pongo esto?".

```
trafodex-main/
├── app/
│   ├── Console/Commands/           # Comandos Artisan custom
│   │   ├── SetupProjectCommand.php       # Recrea la BD desde cero (dev only)
│   │   ├── CleanupExpiredDownloads.php   # Borra exports vencidos (cron horario)
│   │   ├── PurgeSoftDeleted.php          # Purga registros soft-deleted antiguos
│   │   ├── CheckSubscriptionExpirations.php  # Expira subs y manda warnings
│   │   ├── AutomationsTick.php           # Tick por minuto de Automatizaciones
│   │   ├── MakeModuleCommand.php         # Scaffold de módulos nuevos (clona Customers)
│   │   ├── SeedFakeRegions.php           # Dev: genera N regiones para benchmarking
│   │   └── BenchmarkRegions.php          # Dev: mide performance de queries
│   ├── Http/
│   │   ├── Controllers/            # Controllers (organizados por grupo: SystemManagement, BusinessManagement, AuthManagement, etc.)
│   │   ├── Middleware/
│   │   │   ├── HandleInertiaRequests.php  # Comparte props globales con Vue
│   │   │   ├── EnforcePlanFeature.php     # Plan gating por ruta
│   │   │   ├── EnforceSubscription.php    # Bloqueo de tenants suspendidos
│   │   │   └── MaintenanceMode.php        # Página 503 toggleable desde Settings
│   │   ├── Requests/                # FormRequests por módulo
│   │   └── Resources/               # API Resources (Eloquent → JSON)
│   ├── Jobs/                       # Queue jobs (exports, bulk ops, automations)
│   ├── Mail/                       # Mailables (subscription expiring, automation digest)
│   ├── Models/                     # Eloquent models (User, Tenant, Customer, etc.)
│   ├── Notifications/              # Notifications (DownloadReady, PlanChanged, etc.)
│   ├── Providers/
│   │   ├── AppServiceProvider.php          # Gate::before super bypass, rate limiters
│   │   └── SettingsServiceProvider.php     # Lee settings de BD y override config (app.name, session.lifetime)
│   ├── Rules/                      # Validation rules (UniqueNormalizedName)
│   ├── Scopes/                     # Eloquent scopes (HideSuperScope)
│   ├── Services/                   # Lógica de negocio (1 service por módulo)
│   ├── Support/                    # Helpers (Tz, AppSettings, FeatureGate)
│   └── Traits/                     # Auditable, BelongsToTenant, HasFavorites, HasDependents
│
├── bootstrap/
│   └── app.php                     # Configuración global: middleware aliases, schedule, providers, exceptions
│
├── database/
│   ├── migrations/                 # 27 migraciones consolidadas (sin add_/rename_/drop_)
│   ├── seeders/                    # Datos iniciales (DatabaseSeeder llama a 12 seeders en orden)
│   └── factories/                  # Factories por modelo (para tests)
│
├── public/
│   ├── build/                      # Assets compilados por Vite (regenera con npm run build)
│   └── storage/                    # Symlink a storage/app/public (crear con php artisan storage:link)
│
├── resources/
│   ├── css/
│   │   └── app.css                 # Tailwind 4 + Ant Design Vue + reset global de Antd
│   ├── js/
│   │   ├── app.js                  # Bootstrap Inertia + Vue 3 + Ant Design Vue
│   │   ├── bootstrap.js            # Axios global config
│   │   ├── Pages/                  # Componentes Inertia por módulo (Index/Show/Form/Delete/Trash/EditAll)
│   │   ├── Components/             # Componentes Vue por módulo + Common/
│   │   ├── Composables/            # useAuth, useViewport, useDateFormat, useModuleFilters, etc.
│   │   ├── Layouts/                # AppLayout (logged-in), AuthLayout (login)
│   │   └── Plugins/i18n.js         # Plugin Vue para $t()
│   ├── lang/
│   │   ├── es/                     # 27 archivos de traducción español
│   │   └── en/                     # 27 archivos inglés
│   └── views/
│       ├── app.blade.php           # Root shell de Inertia
│       ├── exports/                # Templates PDF (Dompdf) por módulo
│       ├── emails/                 # Templates de email (Blade)
│       ├── maintenance.blade.php   # Página de mantenimiento (503)
│       └── subscription-expired.blade.php  # Página de plan vencido
│
├── routes/
│   ├── web.php                     # Rutas web (Inertia + Blade)
│   ├── api.php                     # Rutas API (Sanctum)
│   ├── console.php                 # Schedules de Laravel (cleanup-expired-downloads cada hora, etc.)
│   ├── auth_management.php         # Login, password reset, profile
│   ├── user_management.php         # Users + Roles
│   ├── system_management.php       # Módulos super (Regions, Languages, Countries, Locales, Tenants, Plans, etc.)
│   ├── business_management.php     # Customers + módulos de negocio futuros (creados por make:module)
│   ├── communication.php           # Inbox + Messages
│   └── automation_management.php   # Automatizaciones
│   ├── dashboard_management.php    # Dashboards
│   ├── download_management.php     # Descargas
│   └── legal_management.php        # Términos, privacidad
│
├── storage/
│   └── app/public/                 # Archivos subidos (logos, fotos, exports, PDFs)
│
├── docs/                           # Documentación detallada
│   ├── INSTALL-TOOLS.md
│   ├── STRUCTURE.md                ← este archivo
│   ├── ENV.md
│   ├── PERMISSIONS.md
│   ├── FRONTEND.md
│   ├── TROUBLESHOOTING.md
│   └── DEPLOY.md
│
├── .env                            # Variables locales (NO commitear)
├── .env.example                    # Template de variables (sí commitear)
├── .gitignore
├── package.json                    # Deps Node
├── composer.json                   # Deps PHP
├── vite.config.js                  # Config Vite (Vue + Tailwind + Laravel)
└── README.md                       # Portada
```

## Convenciones por carpeta

### `app/Http/Controllers/`
Organizados por módulo:
```
Controllers/
├── AuthManagement/
│   ├── Auth/LoginController.php
│   └── UserController.php
├── SystemManagement/
│   ├── LanguageController.php
│   ├── RegionController.php
│   └── TenantController.php
└── DashboardManagement/
    └── DashboardController.php
```

### `resources/js/Pages/`
Cada `.vue` aquí es una **página completa** que Inertia sirve. Estructura por módulo:
```
Pages/
├── Dashboard/Index.vue
├── Users/Index.vue
├── Users/Create.vue
├── Users/Edit.vue
├── Customers/Index.vue
└── ...
```

Convención: la ruta `Users/Index.vue` se llama desde el controller con `inertia('Users/Index', [...])`.

### `routes/`
Cada archivo `*_management.php` agrupa rutas de su módulo. Se incluyen desde `routes/web.php` dentro del grupo de localización + auth.

### `storage/app/public/`
```
storage/app/public/
├── tenants/{id}/logos/
├── users/{id}/avatars/
├── exports/{user_id}/{filename}.xlsx
└── documents/{tenant_id}/{filename}.pdf
```

Accesible vía URL `/storage/...` gracias al symlink. Cuando migres a Spaces/S3, solo cambias el filesystem en `.env`.

## Archivos que NO se commitean (en `.gitignore`)

| Archivo / carpeta | Razón |
|---|---|
| `.env` | Tiene secrets locales |
| `/node_modules` | Dependencias Node — se reinstalan con `npm install` |
| `/vendor` | Dependencias PHP — se reinstalan con `composer install` |
| `/public/build` | Output de Vite — se regenera con `npm run build` |
| `/public/storage` | Es un symlink — se regenera con `php artisan storage:link` |
| `/storage/*.key` | Claves generadas |
| `*.log` | Logs locales |

---

## Documentación relacionada

- [`../README.md`](../README.md) — portada y descripción general del sistema
- [`../README-DEV.md`](../README-DEV.md) — setup y comandos del día a día
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — por qué se eligió cada tecnología y decisiones de diseño
- [`CREATE-MODULE.md`](CREATE-MODULE.md) — qué archivos genera el scaffold y dónde los coloca
- [`PERMISSIONS.md`](PERMISSIONS.md) — cómo se organizan controllers/rutas por rol y permiso
- [`FRONTEND.md`](FRONTEND.md) — convenciones de los archivos en `resources/js/`
