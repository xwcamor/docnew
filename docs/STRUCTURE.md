# Estructura del proyecto

**Qué es esto**: mapa de carpetas y archivos relevantes para entender dónde vive cada cosa del sistema.

**Para qué sirve**: orientarse rápido cuando hay que encontrar un controller, un componente Vue, una migración, una traducción o un job. También sirve como referencia al crear módulos nuevos para saber dónde van los archivos generados.

**Cuándo leerlo**: la primera vez que abres el proyecto y cada vez que dudes "¿dónde pongo esto?".

```
docufiz/
├── app/
│   ├── Console/Commands/           # Comandos Artisan propios
│   │   ├── SetupProjectCommand.php           # Recrea la BD desde cero (se niega en producción)
│   │   ├── MakeModuleCommand.php             # Scaffold de módulos nuevos (clona Brand)
│   │   ├── MakeViewCommand.php               # Genera una vista suelta
│   │   ├── MigrateLegacyDataCommand.php      # docufiz:migrate-data — empresas y personas del sistema v1
│   │   ├── MigrateLegacyFormatsCommand.php   # docufiz:migrate-formats — AST, PTF, EPP, IHM
│   │   ├── CleanupExpiredDownloads.php       # Borra exportaciones vencidas (cron horario)
│   │   ├── PurgeSoftDeleted.php              # Purga registros borrados hace tiempo
│   │   ├── PurgeIdempotencyKeys.php          # Purga claves de idempotencia de la API
│   │   ├── PurgeAutomationNotifications.php  # Vacía la campana de notificaciones de automatización
│   │   ├── CheckSubscriptionExpirations.php  # Expira suscripciones y avisa
│   │   ├── AutomationsTick.php               # Tick por minuto de Automatizaciones
│   │   ├── ExportCustomPermissions.php       # Vuelca a JSON los permisos de los perfiles
│   │   ├── FixDatabaseSequencesCommand.php   # Recoloca las secuencias de PostgreSQL tras una carga masiva
│   │   ├── SeedFakeRegions.php               # Dev: genera N regiones para medir
│   │   └── BenchmarkRegions.php              # Dev: mide el coste de las consultas
│   ├── Exports/                    # Clases de exportación (Excel, Word, plantilla de importación)
│   ├── Imports/                    # Clases de importación con validación y deduplicación
│   ├── Http/
│   │   ├── Controllers/            # Por grupo: SystemManagement, BusinessManagement, FieldWork, AuthManagement…
│   │   ├── Middleware/
│   │   │   ├── HandleInertiaRequests.php  # Props globales que ve Vue
│   │   │   ├── EnforcePlanFeature.php     # Gateo por plan, ruta a ruta
│   │   │   ├── EnforceSubscription.php    # Bloquea workspaces suspendidos
│   │   │   ├── EnforceIdempotency.php     # Clave de idempotencia en la API
│   │   │   ├── EnsureUserActive.php       # Corta la sesión de un usuario desactivado
│   │   │   ├── TenantResolver.php         # Resuelve el workspace de la petición
│   │   │   └── MaintenanceMode.php        # Página 503 conmutable desde Ajustes
│   │   ├── Requests/               # FormRequests por módulo
│   │   └── Resources/              # Recursos de API (Eloquent → JSON)
│   ├── Jobs/                       # Trabajos en cola (exportaciones, masivas, automatizaciones)
│   ├── Mail/                       # Mailables
│   ├── Models/                     # Modelos Eloquent
│   ├── Notifications/              # Notificaciones (DownloadReady, PlanChanged…)
│   ├── Observers/
│   │   └── SystemModuleObserver.php  # Crea los 7 permisos canónicos al registrar un módulo
│   ├── Providers/
│   │   ├── AppServiceProvider.php         # Gate::before del super, limitadores de tasa
│   │   ├── AutomationServiceProvider.php  # Registra data sources y actions de automatización
│   │   └── SettingsServiceProvider.php    # Vuelca ajustes de la BD dentro de config()
│   ├── Rules/                      # Reglas de validación (UniqueNormalizedName)
│   ├── Scopes/                     # Scopes de Eloquent (HideSuperScope)
│   ├── Services/                   # Lógica de negocio, un servicio por módulo
│   │   ├── BusinessManagement/     # CompanyService, PersonService, WorkPlanService, FormTemplateService…
│   │   ├── FieldWork/              # FormSubmissionService, SignatureService, FormTemplateBuilder
│   │   ├── Automations/            # Runner, registries, data sources y actions
│   │   └── …
│   ├── Support/                    # Tz, FeatureGate, LikeQuery, HtmlSanitizer
│   └── Traits/                     # Auditable, BelongsToTenant(OrGlobal), Lockable, HasFavorites, HasDependents…
│
├── bootstrap/
│   └── app.php                     # Middleware, calendario de tareas, providers, manejo de excepciones
│
├── database/
│   ├── migrations/                 # Las cinco del dominio son 2026_08_07_1003xx: organización, personas, planes, motor de formatos y evidencias
│   ├── seeders/                    # DatabaseSeeder marca el orden. SystemModulesSeeder y RolesAndPermissionsSeeder son los que gobiernan el acceso
│   │   └── data/                   # Datos de apoyo (CSV/JSON). Ojo: aún quedan datasets de TRAFODEX sin usar
│   └── factories/                  # Factories por modelo, para pruebas
│
├── public/
│   ├── build/                      # Assets compilados por Vite
│   ├── models/                     # Pesos de face-api.js (~7 MB). Versionados a propósito: no se descargan en ejecución
│   └── storage/                    # Enlace a storage/app/public (php artisan storage:link)
│
├── resources/
│   ├── css/app.css                 # Tailwind 4 + Ant Design Vue
│   ├── js/
│   │   ├── app.js                  # Arranque de Inertia + Vue 3 + Ant Design Vue
│   │   ├── Pages/                  # Una página Inertia por pantalla
│   │   │   ├── Companies|People|WorkPlans|FormTemplates/  # Maestros, patrón Index/Show/Form/Delete/Trash/EditAll
│   │   │   └── FieldWork/          # Forms, FormFill, Sign, SignaturePhotos — las pantallas de obra
│   │   ├── Components/
│   │   │   ├── Common/             # ResponsiveTable, FilterBar, ExportDialog, SavedViews, RecordHistory…
│   │   │   └── FormFields/         # Los tipos compuestos: RiskMatrixField, PersonChecklistField, CatalogSelect…
│   │   ├── Composables/            # useAuth, useFaceVerify, useModuleFilters, usePlanFeatures…
│   │   ├── Layouts/                # AppLayout (dentro), AuthLayout (login)
│   │   └── Plugins/i18n.js         # Plugin de traducción para $t()
│   ├── lang/{es,en}/               # Un archivo por módulo, unos 40 en cada idioma
│   └── views/
│       ├── app.blade.php           # Cascarón de Inertia
│       ├── exports/                # Plantillas PDF (dompdf)
│       ├── emails/                 # Plantillas de correo
│       └── maintenance.blade.php   # Página de mantenimiento (503)
│
├── routes/
│   ├── web.php                     # Punto de entrada; incluye el resto dentro del grupo de idioma + auth
│   ├── api.php                     # API con Sanctum (solo customers hoy)
│   ├── console.php                 # Parte del calendario de tareas
│   ├── auth_management.php         # Login, recuperación de contraseña, perfil
│   ├── user_management.php         # Usuarios + Perfiles
│   ├── system_management.php       # Core del super: Regiones, Idiomas, Países, Locales, Workspaces, Planes, Ajustes, Auditoría
│   ├── business_management.php     # Los maestros: Clientes, Marcas, Empresas, Personas, Planes de trabajo, Plantillas de formato
│   ├── field_work.php              # Trabajo en obra: llenar formatos, firmar, álbum de fotos de firmas
│   ├── communication.php           # Inbox + Mensajes
│   ├── automation_management.php   # Automatizaciones
│   ├── dashboard_management.php    # Dashboards
│   ├── notifications.php           # Campana
│   ├── saved_views.php             # Vistas guardadas
│   ├── user_preferences.php        # Preferencias de interfaz
│   ├── localized.php               # Rutas con prefijo de idioma
│   ├── tools.php                   # Utilidades sueltas
│   └── legal_management.php        # Términos y privacidad
│
├── storage/
│   └── app/                        # Archivos subidos. Las evidencias de firma van en disco PRIVADO, nunca bajo public
│
├── docs/                           # Esta documentación
├── .env                            # Variables locales (NO se commitea)
├── .env.example                    # Plantilla sin secretos (sí se commitea)
├── package.json                    # Dependencias Node
├── composer.json                   # Dependencias PHP
├── vite.config.js                  # Config de Vite
└── README.md                       # Portada
```

> **`routes/field_work.php` es el archivo que separa DOCUFIZ de la base SaaS.**
> Todo lo demás sigue el patrón CRUD heredado. Ahí viven las tres pantallas que
> solo tienen sentido en obra: llenar el formato, firmar con la cámara y resolver
> las firmas que quedaron dudosas.

## Convenciones por carpeta

### `app/Http/Controllers/`
Organizados por grupo, no por capa:
```
Controllers/
├── AuthManagement/
│   ├── Auth/LoginController.php
│   └── UserController.php
├── SystemManagement/
│   ├── LanguageController.php
│   └── TenantController.php
├── BusinessManagement/
│   ├── CompanyController.php
│   ├── PersonController.php
│   ├── WorkPlanController.php
│   └── FormTemplateController.php
└── FieldWork/
    ├── FormSubmissionController.php   # llenar y confirmar un formato
    └── SignatureController.php        # firmar, enrolar, álbum de fotos, anular, servir evidencias
```

### `resources/js/Pages/`
Cada `.vue` aquí es una **página completa** que sirve Inertia:
```
Pages/
├── Dashboard/Index.vue
├── People/{Index,Show,Form,Delete,Trash,EditAll}.vue     # el patrón de los maestros
├── WorkPlans/{Index,Show,Form,…}.vue
└── FieldWork/
    ├── Forms.vue             # la lista de formatos del plan
    ├── FormFill.vue          # llenar uno
    ├── Sign.vue              # la cámara
    └── SignaturePhotos.vue   # el álbum de fotos de firmas (solo super)
```

Convención: `People/Index.vue` se invoca desde el controller con `inertia('People/Index', [...])`.

### `routes/`
Cada archivo agrupa las rutas de su área. Todos se incluyen desde `routes/web.php` dentro del grupo de idioma + auth.

### Dónde van los archivos subidos

Hay dos destinos, y la diferencia importa:

| Qué | Dónde | Por qué |
|---|---|---|
| Logos de workspace, fotos de perfil, exportaciones | `storage/app/public/`, accesible por `/storage/...` gracias al enlace | Son públicos y se piden mucho |
| **Fotos de firma y adjuntos de formato** | Disco **privado**, servidos por una ruta autenticada | Son la prueba de quién estuvo en obra. Un enlace público se reenvía; una ruta con `signature_events.review` no |

En el PDF generado las fotos se incrustan como data-uri leyéndolas del disco: no
se crea ninguna URL de una cara, ni siquiera autenticada, dentro de un documento
que después puede acabar en un correo.

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
- [`DOMINIO.md`](DOMINIO.md) — qué tabla es cada cosa y cómo se relacionan
