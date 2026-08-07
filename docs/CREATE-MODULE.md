# Crear un módulo nuevo con `make:module`

Guía completa del scaffold del proyecto. Cómo generar un módulo nuevo clonando el master template **`Brand`**, qué hace el comando solo y qué queda para después.

> **Cuándo usar esto**: cuando quieras un módulo de negocio nuevo. En DOCUFIZ los que faltan son catálogos de obra: sedes, puestos, áreas, cargos, nacionalidades, tipos de trabajo.
>
> **Cuándo NO usar esto**: si solo quieres agregar una columna a un módulo existente. Para eso, `php artisan make:migration add_X_to_Y_table` y editar `Form.vue` + `Show.vue` + el `StoreRequest`.

---

## 1. Por qué `Brand` es el master template

El scaffold clona `Brand`, no `Customer`.

`Customer` fue el primer patrón, y acumuló dominio propio: código de cliente, jerarquía de ubicaciones, restricción por cartera asignada (`RestrictedToAssignedCustomers`) y capa de API REST. Clonarlo obligaba a **quitar** cosas en cada módulo nuevo, y el post-procesado del comando se iba llenando de parches para borrar campos que el módulo nuevo no quería.

`Brand` es un master **limpio**: `name` + `code` + `is_active` + `sort_order`, con `BelongsToTenantOrGlobal` y `Lockable`, y nada más.

Eso explica por qué `Brand` sigue en el repositorio aunque no sea un módulo del dominio de DOCUFIZ: **borrarlo deja el generador inservible** (ver `docs/PURGA.md`). El comando rechaza generar un módulo llamado `Brand` o `Customer`.

Lo que se hereda al clonar:

| Capacidad | Cómo llega |
|---|---|
| Aislamiento por workspace | trait `BelongsToTenantOrGlobal` (`tenant_id` nullable, con bypass del super) |
| Rutas con `permission:{modulo}.{accion}` | el bloque de rutas se copia con sus middleware |
| Auditoría de cambios | trait `Auditable` + `audit_logs` polimórfico |
| Soft-delete, papelera, restaurar, borrado definitivo | rutas `role:super` + `Trash.vue` |
| Masivas que pasan a cola por encima del umbral | `Bulk{Plural}ActionJob` |
| Exportar CSV/Excel/PDF/Word e importar con vista previa | jobs + exports + import |
| Favoritos, vistas recientes, vistas guardadas, selector de columnas | `config/polymorphic.php` + componentes comunes |
| Bloquear/desbloquear un registro | trait `Lockable` — pero ver el aviso del paso 5.1 |

---

## 2. Uso básico

```bash
php artisan make:module WorkType --group=BusinessManagement
```

### Argumentos

| Argumento | Obligatorio | Descripción |
|---|---|---|
| `{Name}` | Sí | PascalCase singular: `WorkType`, `Position`, `Nationality`. Soporta nombres de 2+ palabras (`WorkLocation`, `WorkArea`) → genera `work_locations`, `work_areas`. |
| `--group=` | No (default `BusinessManagement`) | Namespace donde vive el módulo. Cualquier PascalCase vale. **No usar `SystemManagement`** — está reservado para el core del super |
| `--fields=` | No | Campos del dominio: `"col:tipo"` separados por coma, `?` = nullable. Se inyectan en migración, modelo, FormRequests, factory, traducciones, `Form.vue`, `Show.vue` y `columns.js`. Tipos: `string, text, integer, decimal, boolean, date, datetime, year`. |
| `--no-tenant` | No | Genera un **catálogo global** sin `tenant_id` (todos los workspaces comparten los datos). |

### Ejemplo completo

```bash
# Módulo por workspace con campos propios
php artisan make:module Workstation --group=BusinessManagement \
    --fields="code:string?, description:text?, is_confined:boolean"

# Catálogo global (sin tenant) con código y orden
php artisan make:module Nationality --group=BusinessManagement \
    --fields="code:string?, sort_order:integer" --no-tenant
```

> El frontend genera cada campo con el control adecuado según su tipo
> (`Input`, `InputNumber`, `Switch`, `Textarea`, `DatePicker`). Ajustá el
> control o el layout a mano si necesitás algo más específico (ej. un
> `Select` para una FK).

### Reglas del nombre

- PascalCase **singular** (no plural)
- Solo letras y dígitos
- No usar `Brand` (es el master que clona el comando) ni `Customer` (el patrón de referencia). El comando rechaza ambos.
- **No usar nombres reservados.** El comando rechaza nombres que chocan con
  clases del framework que los archivos generados ya importan (el caso clásico
  es `Model`: el modelo generado hace `class Model extends Model`, que es un
  fatal contra `Illuminate\Database\Eloquent\Model`) o con palabras/tipos
  reservados de PHP (`String`, `Array`, `Match`, `Enum`…). Lista completa en
  `MakeModuleCommand::handle()` (`$reservedNames`): incluye `Model`, `Builder`,
  `Collection`, `Request`, `Controller`, `Resource`, `Validator`, `Rule`,
  `Auth`, `Str`, `DB`, `Schema`, etc. Si tu dominio es "modelo" a secas, usa un
  nombre compuesto: `EquipmentModel`, `ToolModel`.

---

## 3. Qué genera el scaffold (~51 archivos)

### Backend (21 archivos)

| Archivo | Para qué |
|---|---|
| `app/Http/Controllers/{Group}/{Name}Controller.php` | Controller con CRUD + bulk + exports + imports + edit-all |
| `app/Services/{Group}/{Name}Service.php` | Lógica de negocio |
| `app/Models/{Name}.php` | Modelo Eloquent con `BelongsToTenantOrGlobal` + `Auditable` + `HasFavorites` + `Lockable` |
| `app/Http/Requests/{Group}/{Name}/*.php` | 9 FormRequests (Store, Update, Delete, ForceDelete, BulkDelete, BulkSetActive, BulkRestore, EditAllUpdate, Import) |
| `app/Imports/{Group}/{Plural}/{Plural}Import.php` | Import Excel/CSV con dedup y validación |
| `app/Exports/{Group}/{Plural}/*.php` | 3 Exports (Excel, Word, ImportTemplate) |
| `app/Jobs/{Group}/{Plural}/*.php` | 6 Jobs: base export + bulk action + 4 generators (CSV/Excel/PDF/Word) |

> **El scaffold NO genera la capa API.** Por defecto los módulos generados son web-only (Inertia). Si necesitas exponer el módulo via API REST, ver paso 5.10.

### Frontend (24 archivos)

| Archivo | Para qué |
|---|---|
| `resources/js/Pages/{Plural}/Index.vue` | Listado con filtros, search, paginación, bulk actions, mobile cards |
| `resources/js/Pages/{Plural}/Show.vue` | Vista de detalle con tabs (información + actividad) |
| `resources/js/Pages/{Plural}/Form.vue` | Form de crear / editar |
| `resources/js/Pages/{Plural}/Delete.vue` | Vista de confirmación de delete con motivo obligatorio |
| `resources/js/Pages/{Plural}/Trash.vue` | Papelera (super only) |
| `resources/js/Pages/{Plural}/EditAll.vue` | Edición masiva inline (plan pro+) |
| `resources/js/Pages/{Plural}/config/*.js` | 5 configs: columns, filters, exports, tour, trashColumns |
| `resources/js/Components/{Plural}/*.vue` | 13 componentes: bulk bar, action cells, drawers, modals, etc. |

### Database (2 archivos)

| Archivo | Para qué |
|---|---|
| `database/migrations/{timestamp}_create_{plural}_table.php` | Migración con `name`, `slug`, `is_active`, `tenant_id`, columnas del lock, soft-delete, columnas de auditoría e índices |
| `database/factories/{Name}Factory.php` | Factory para tests con fake data |

### Config + i18n (4 archivos)

| Archivo | Para qué |
|---|---|
| `config/{plural}.php` | Config del módulo (límites, defaults) |
| `resources/lang/es/{plural}.php` | Traducciones español |
| `resources/lang/en/{plural}.php` | Traducciones inglés |

### Lo que el scaffold modifica (NO crea desde cero)

| Archivo | Cómo lo modifica |
|---|---|
| `routes/{group}.php` | Appendea el bloque de rutas del módulo nuevo (con `permission:X.action` middleware) |
| `config/polymorphic.php` | Agrega entrada para favoritos + recent items |
| `config/purge.php` | Agrega entrada para purge automático (default 90 días) |
| Tabla `system_modules` (BD) | Inserta una fila con `permission_key='{plural}'` — sin sufijo de acción. De esa fila salen los **7 permisos**, que los crea `SystemModuleObserver` al insertarla |

### Lo que el scaffold NO toca (intencionalmente)

| Item | Por qué NO lo toca | Paso de referencia |
|---|---|---|
| Capa API (Resource + ApiController + routes/api.php) | Es opcional y específica del módulo | Paso 5.10 |
| Sidebar (`AppLayout.vue` + `lang/{es,en}/sidebar.php`) | El icono y la traducción son decisiones de cada módulo | Paso 5.3 |
| Asignar los permisos a los perfiles en `RolesAndPermissionsSeeder` | Los permisos ya existen (los crea el observer); lo que falta es decidir **qué perfil los lleva** | Paso 5.2 |
| `config/features.php` (gateo por plan) | No todos los módulos son de pago | Paso 5.8 |
| Tests del módulo nuevo | El scaffold clona los de `Brand` si existen | Paso 5.12 |

---

## 4. Campos base del módulo generado

Sin `--fields`, el scaffold genera el módulo con **un solo campo** de dominio:

| Campo | Tipo | Obligatorio | Para qué |
|---|---|---|---|
| `name` | string | sí | Identificador visible del registro |

Con `--fields` los campos van inyectados en los doce archivos que hacen falta. Sin
él, se añaden a mano después (paso 5.5). Usar `--fields` desde el principio ahorra
la mayor parte de ese trabajo.

### Columnas del sistema (también generadas, automático)

| Columna | Para qué |
|---|---|
| `id` | Primary key |
| `slug` | Identificador opaco de 22 chars para URLs (ej. `/workstations/aBcD12...`) |
| `tenant_id` | Aislamiento por workspace. FK `ON DELETE SET NULL` a `tenants`, heredada del master. `NULL` = registro global (lo ven todos, solo el super lo edita). |
| `locked_at`, `locked_by`, `lock_scope` | Bloqueo de registro (trait `Lockable`). Las inyecta el post-procesado del comando. |
| `is_active` | Toggle activo/inactivo |
| `created_by`, `deleted_by` | Audit trail |
| `deleted_description` | Motivo obligatorio del soft-delete |
| `created_at`, `updated_at`, `deleted_at` | Timestamps + soft delete |

---

## 5. Pasos manuales post-scaffold

El comando imprime un checklist completo al final con todos los pasos. Esta sección los explica en detalle.

### Pasos OBLIGATORIOS (sin esto el módulo no funciona)

#### 5.1. Migrar la base de datos

```bash
php artisan migrate
```

Si la migración falla, revisa el archivo generado en `database/migrations/*_create_{plural}_table.php` y reintenta.

> **Comprueba que la migración trae las columnas del lock.** El comando las
> inyecta (`locked_at`, `locked_by`, `lock_scope`), pero el modelo clonado usa el
> trait `Lockable` y las rutas `lock`/`unlock` se generan igual, así que si el
> parche no se aplicó tienes un botón que apunta a columnas inexistentes. Es
> exactamente lo que pasó con `companies`, `people`, `work_plans` y
> `form_templates`, cuyas migraciones se escribieron a mano y no las tienen.
> Detalle en [`ACCESS-MODEL.md` §6.5](ACCESS-MODEL.md#65-lock-por-registro-congelar--trait-lockable).

#### 5.2. Asignar los permisos a los perfiles

Los **7 permisos ya existen**: `SystemModuleObserver` los creó cuando el scaffold
insertó la fila en `system_modules`. No hay que declararlos en ningún sitio.

Lo que sí hace falta es decidir **quién los tiene**. En
`database/seeders/RolesAndPermissionsSeeder.php`, sumar el `permission_key` nuevo
al conjunto del perfil que corresponda. Por ejemplo, para que el supervisor de
obra pueda gestionar un catálogo nuevo:

```php
$supervisorPerms = array_merge(
    // …
    $pick('workstations', ['view', 'show', 'create', 'edit']),
);
```

Luego:

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
```

> `super` y `admin` reciben **todos** los permisos con un `syncPermissions(Permission::all())`, así que el módulo nuevo les funciona sin tocar nada. Este paso es solo para los perfiles.

Si además quieres que exportar exija su propio permiso (y no baste con `view`),
tienes que añadir `permission:{plural}.export` a las rutas de exportación: el
bloque generado **no lo trae** en todos los casos. Ver el aviso de
[`ACCESS-MODEL.md` §2](ACCESS-MODEL.md#aviso-export--import-no-gatean-en-los-módulos-de-docufiz).

#### 5.3. Agregar la entrada al sidebar

El scaffold **no toca el sidebar** porque el icono y la traducción son decisiones específicas de cada módulo.

**a)** Editar `resources/js/Layouts/AppLayout.vue`:

```js
import { ApartmentOutlined } from '@ant-design/icons-vue';

// Dentro del array correspondiente al grupo del módulo:
{
    key: 'workstations',
    label: t('sidebar.workstations'),
    icon: ApartmentOutlined,
    href: route('business_management.workstations.index'),
    inertia: true,
    visible: () => can('workstations.view'),
    // Si el módulo se gatea por plan:
    // visible: () => can('workstations.view') && canUsePlanFeature('workstations'),
},
```

**b)** Agregar la traducción en los dos archivos de idioma:

```php
// resources/lang/es/sidebar.php
'workstations' => 'Puestos de trabajo',

// resources/lang/en/sidebar.php
'workstations' => 'Workstations',
```

#### 5.4. Verificar build y limpiar caches

```powershell
npm run build
php artisan config:clear
php artisan route:clear
```

### Pasos RECOMENDADOS (según el dominio del módulo)

#### 5.5. Sumar columnas del dominio en la migración

`database/migrations/{timestamp}_create_workstations_table.php`:

```php
// Después de $table->string('name')->index();
$table->string('code', 50)->nullable()->index();
$table->boolean('is_confined')->default(false);
$table->foreignId('work_location_id')->nullable()->constrained('work_locations')->nullOnDelete();
```

Luego también:

- Sumarlas al `$fillable` del modelo.
- Sumarlas al `$casts` si aplica (decimales, booleanos, fechas).
- Sumarlas a la `definition()` del factory para tests.
- Sumarlas al `Form.vue` con los `<FormItem>` correspondientes.
- Sumarlas al `Show.vue` con los `<DescriptionsItem>` correspondientes.
- Sumarlas al `config/columns.js` para que aparezcan en el listado.
- Sumar las reglas de validación en `StoreXRequest.php` y `UpdateXRequest.php`:

```php
'code'             => 'nullable|string|max:50',
'is_confined'      => 'boolean',
'work_location_id' => 'nullable|integer|exists:work_locations,id',
```

#### 5.6. Relaciones del modelo (FKs salientes)

Si el módulo tiene FKs hacia otras tablas, agregar el método `belongsTo()` correspondiente en `app/Models/{Name}.php`:

```php
public function workLocation(): BelongsTo
{
    return $this->belongsTo(WorkLocation::class);
}
```

Y cargar la relación con `with()` en el Service para evitar N+1.

#### 5.7. Dependientes (FKs entrantes a este módulo)

Si OTROS modelos apuntan a este con una FK (por ejemplo `WorkPlan` tiene `workstation_id`), declarar el método `dependents()` en el modelo para que el sistema avise antes de borrar:

```php
public function dependents(): array
{
    return [
        ['model' => WorkPlan::class, 'foreign_key' => 'workstation_id', 'label' => 'planes de trabajo'],
    ];
}
```

Sin esto, borrar un puesto de trabajo con planes colgando no avisa a nadie.

#### 5.8. Plan gating (si el módulo es premium)

Si el módulo debe estar disponible solo en planes `pro` o `enterprise`:

**a)** Declarar la feature. La **fuente de verdad es la tabla `plans`**, así que hay
que tocar dos sitios: `PlansSeeder` (el valor por cada uno de los cuatro planes) y
`config/features.php`, que es solo el respaldo para el caso en que la tabla aún no
exista:

```php
// config/features.php — dentro de 'features'
'workstations' => ['pro', 'enterprise'],   // null = todos los planes
```

Y añadir la clave a `PlanController::featureKeys()` para que aparezca en el
formulario de planes.

**b)** Aplicar el middleware en las rutas correspondientes en `routes/business_management.php`:

```php
Route::middleware(['permission:workstations.view', 'plan_feature:workstations'])->group(function () {
    Route::get('workstations', [WorkstationController::class, 'index'])->name('workstations.index');
    // ...
});
```

**c)** Sumar `canUsePlanFeature()` al `visible` del item del sidebar (ver paso 5.3).

#### 5.9. Filtros avanzados (opcional)

Para usar el query builder de filtros avanzados (el drawer con condiciones tipo `where`), declarar el método estático `filterSchema()` en el modelo:

```php
public static function filterSchema(): array
{
    return [
        'name'             => ['type' => 'string', 'label' => 'Nombre'],
        'code'             => ['type' => 'string', 'label' => 'Código'],
        'is_confined'      => ['type' => 'bool',   'label' => 'Espacio confinado'],
        'work_location_id' => ['type' => 'enum',   'label' => 'Sede', 'options' => 'work_locations'],
        'created_at'       => ['type' => 'date',   'label' => 'Fecha de creación'],
    ];
}
```

Mirar `app/Models/WorkPlan.php` o `app/Models/Customer.php` como referencia.

#### 5.10. Capa API REST (opcional — el scaffold NO la genera)

Por defecto los módulos generados son **web-only** (Inertia). Si el módulo necesita exponerse via API REST:

**a)** Crear `app/Http/Resources/{Name}Resource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WorkstationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'slug'        => $this->slug,
            'name'        => $this->name,
            'description' => $this->description,
            'is_active'   => $this->is_active,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
```

(Mirar `app/Http/Resources/CustomerResource.php` como referencia.)

**b)** Crear `app/Http/Controllers/Api/V1/{Name}ApiController.php` (mirar `CustomerApiController.php`).

**c)** Agregar las rutas en `routes/api.php` con abilities Sanctum:

Dentro del grupo `v1` que ya trae `auth:sanctum`, `throttle:api` y
`plan_feature:api_access` — no repitas esos tres:

```php
Route::middleware('ability:workstations:read')->group(function () {
    Route::get('workstations',        [WorkstationApiController::class, 'index']);
    Route::get('workstations/{slug}', [WorkstationApiController::class, 'show']);
});

Route::middleware('ability:workstations:write')->group(function () {
    Route::post('workstations',        [WorkstationApiController::class, 'store']);
    Route::put('workstations/{slug}',  [WorkstationApiController::class, 'update']);
});

Route::middleware('ability:workstations:delete')->group(function () {
    Route::delete('workstations/{slug}', [WorkstationApiController::class, 'destroy']);
});
```

**d)** Sumar las abilities al crear tokens (ver lógica de creación de tokens del proyecto).

**e)** Documentar con anotaciones Scribe (`@group`, `@queryParam`, `@bodyParam`, `@response`) y regenerar:

```bash
php artisan scribe:generate
```

> Hoy el **único** módulo con API es `customers`, y está ahí como patrón de referencia. Ningún módulo del dominio de obra se expone. El core (Regions, Languages, Countries, Locales, Tenants, SystemModules, Settings) no se expone por decisión de diseño: es super-only desde la interfaz web.

#### 5.11. Data source para Automatizaciones (opcional)

Si quieres que las **automatizaciones** puedan consultar este módulo:

**a)** Crear `app/Services/Automations/DataSources/{Plural}DataSource.php` implementando `DataSourceContract`.

**b)** Registrarlo en `app/Services/Automations/DataSourceRegistry::register()`.

Detalles y ejemplo completo en [`AUTOMATIONS.md`](AUTOMATIONS.md) (sección 8).

#### 5.12. Tests

El scaffold clona los tests de `Brand` si los encuentra. Verificar:

```bash
php artisan test --filter=Workstation
```

La referencia mínima es [`BrandCrudTest`](../tests/Feature/BusinessManagement/BrandCrudTest.php). Para lo que de verdad importa —que un workspace no vea los datos de otro y que un perfil sin permiso reciba 403— los modelos a copiar son `CustomerTenantIsolationTest` e `ImportExportPermissionTest`.

---

## 6. Idempotencia y rollback automático

### Idempotencia

Si el módulo ya existe (alguien intentó crearlo antes y quedó en estado raro), el comando **aborta sin tocar nada**. Para regenerarlo, primero borra manualmente los archivos del intento anterior.

### Rollback automático

Si algo falla a mitad de la generación (ej. un patch específico no encuentra el patrón esperado en el archivo de origen, o falla la inserción en `system_modules` por una collision), el comando:

1. Imprime el error con detalle
2. Inicia rollback automático:
   - Borra todos los archivos creados durante este run
   - Restaura los archivos modificados a su contenido original (routes, polymorphic, purge)
3. Sale con código de error 1

El proyecto queda exactamente como estaba antes de correr el comando.

---

## 7. Post-procesamiento que hace el scaffold

Clonar con find-replace no basta: hay que **quitar** lo que era propio de `Brand` y **añadir** lo que pidió el `--fields`. El comando lo hace en un post-procesado sobre unos doce archivos (migración, modelo, FormRequests, `Form.vue`, `Show.vue`, `columns.js`, factory, traducciones, exports e imports):

- **Inyecta las columnas del lock** (`locked_at`, `locked_by`, `lock_scope`) en la migración generada. La migración base de `Brand` no las trae, y sin ellas el botón de bloquear revienta. Es idempotente.
- **Inyecta los campos de `--fields`** en los doce archivos, cada uno con su control de formulario según el tipo.
- Si no se pasó `--fields`, el módulo arranca solo con `name` y los campos del dominio se añaden a mano (paso 5.5).

Si un parche no encuentra el patrón que espera, el scaffold avisa con un `warn` pero **no aborta**: el archivo queda con el contenido base de `Brand` y se ajusta a mano.

---

## 8. Dónde vive el comando

El scaffold es código real del proyecto, no algo "mágico":

| Pieza | Path |
|---|---|
| El comando Artisan | [`app/Console/Commands/MakeModuleCommand.php`](../app/Console/Commands/MakeModuleCommand.php) |
| Auto-cargado | Sí — Laravel escanea `app/Console/Commands/*.php` por convención |
| Stubs externos | No tiene — toda la lógica vive dentro del comando |
| Cómo invocarlo | `php artisan make:module {Name} --group={Group}` |

---

## 9. Tabla de verificación post-scaffold

Antes de declarar "módulo listo", verifica:

| Item | Cómo |
|---|---|
| Tabla creada en BD | `\d workstations` en psql (debe listar todas las columnas) |
| Permisos creados | `php artisan tinker` → `Spatie\Permission\Models\Permission::where('name', 'like', 'workstations.%')->count()` debe dar exactamente **7** |
| Columnas del lock | `\d workstations` debe listar `locked_at`, `locked_by` y `lock_scope`. Si no están, el botón de bloquear falla |
| Rol super tiene los permisos | `Role::where('name', 'super')->first()->hasPermissionTo('workstations.view')` debe dar true |
| Rutas funcionan | `php artisan route:list --name=business_management.workstations` debe listar 20+ rutas |
| Sidebar muestra el módulo | Login como super → verificar que aparece el item con su icono |
| El listado vacío carga sin error | Visitar `/es/business_management/workstations` (login como super) |
| Crear un registro funciona | Form → llenar `name` (required) → guardar → debe aparecer en el listado |
| Build pasa | `npm run build` sin errores |

---

## 10. Errores comunes durante el scaffold

| Error | Causa | Solución |
|---|---|---|
| "El módulo X ya existe" | Hay archivos del módulo en el filesystem | Borrar manualmente los archivos del intento anterior |
| "Patron no encontrado en archivo X" (warn) | El scaffold espera un patrón que cambió en los archivos de `Brand` | Ajustar el archivo a mano después del scaffold (no es fatal) |
| "Permission denied" al escribir archivos | El usuario no tiene permiso de escritura en `app/`, `resources/`, etc. | `chmod -R u+w app resources database routes config` |
| "system_modules: permission_key ya existe" | Hay una fila en BD con el mismo `permission_key` (intento anterior) | `DB::table('system_modules')->where('permission_key', 'workstations')->delete()` antes de re-correr |
| Sidebar no muestra el módulo nuevo | Falta el paso 5.3 | Editar `AppLayout.vue` + los dos `lang/{es,en}/sidebar.php` |
| Listado da 403 | Los permisos existen pero ningún perfil los tiene (paso 5.2) | Sumarlos al perfil en `RolesAndPermissionsSeeder` y re-sembrar |
| Listado da 404 | Falta el paso 5.4 (clear de caches) | `php artisan route:clear` + `npm run build` |

---

## 11. Documentación relacionada

- [`README-DEV.md`](../README-DEV.md) — workflow general de desarrollo
- [`PERMISSIONS.md`](PERMISSIONS.md) — sistema de permisos Spatie + super bypass
- [`plan-features.md`](plan-features.md) — cómo gatear un módulo nuevo por plan
- [`ACCESS-MODEL.md`](ACCESS-MODEL.md) — qué habilita cada uno de los 7 permisos, y dónde el gateo tiene huecos
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — sección 13, por qué `Brand` y no `Customer`
- [`STRUCTURE.md`](STRUCTURE.md) — estructura general del repo
- [`AUTOMATIONS.md`](AUTOMATIONS.md) — cómo registrar un data source nuevo (paso 5.11)
