# Crear un módulo nuevo con `make:module`

Guía completa del scaffold del proyecto. Cómo generar un módulo nuevo a partir del master template **Customer**, qué hace el comando automático y qué tienes que hacer manualmente después.

> **Cuándo usar esto**: cuando quieras crear un módulo de negocio nuevo (Products, Sales, Categories, Brands, Inventory, etc.).
>
> **Cuándo NO usar esto**: si solo quieres agregar una columna nueva a un módulo existente. Para eso, crea una migración con `php artisan make:migration add_X_to_Y_table` y edita el `Form.vue` + `Show.vue` + `StoreRequest` correspondientes.

---

## 1. ¿Por qué Customer como master template?

Customer es el patrón de referencia porque tiene todo lo que un módulo de negocio multi-tenant necesita:

| Feature | Implementación |
|---|---|
| `BelongsToTenant` trait | Scope automático: cada workspace ve solo sus datos |
| Rutas con `permission:X.action` | Granularidad fina: el admin del workspace decide quién hace qué |
| `tenant_id` nullable + scope | Super bypassea el scope; admin/user están filtrados |
| Audit log polimórfico | Toda creación/edición/borrado queda registrada |
| Soft-delete + Trash + Restore + Force-delete | Triple guard contra pérdida de datos |
| Bulk ops (auto-async > 200) | Operaciones masivas en queue |
| Exports CSV/Excel/PDF/Word + Imports | Patrón completo con plan gating |
| Favoritos polimórficos + Recent items | UX consistente con el resto del sistema |

Clonar Customer garantiza que el módulo nuevo herede todas estas capacidades sin tener que reimplementarlas.

---

## 2. Uso básico

```bash
php artisan make:module Product --group=BusinessManagement
```

### Argumentos

| Argumento | Obligatorio | Descripción |
|---|---|---|
| `{Name}` | Sí | PascalCase singular: `Product`, `Sale`, `Category`, `Brand`, `Inventory`. Soporta nombres de 2+ palabras (`OilType`, `PowerPlant`) → genera `oil_types`, `power_plants`. |
| `--group=` | No (default `BusinessManagement`) | Namespace donde vive el módulo. Otros válidos: `HealthManagement`, `LegalManagement`, etc. **No usar `SystemManagement`** — está reservado para módulos core del super |
| `--fields=` | No | Campos del dominio: `"col:tipo"` separados por coma, `?` = nullable. Inyecta en migración, modelo, FormRequests, factory, lang, Form.vue, Show.vue y columns.js. Tipos: `string, text, integer, decimal, boolean, date, datetime, year`. Ej: `--fields="price:decimal, stock:integer, sku:string?, notes:text?"` |
| `--no-tenant` | No | Genera un **catálogo global** sin `tenant_id` ni `BelongsToTenant` (todos los workspaces comparten los datos). Para catálogos como `oil_types`, tipos, unidades, etc. |

### Ejemplo completo

```bash
# Módulo per-tenant con campos custom
php artisan make:module Product --group=BusinessManagement \
    --fields="price:decimal, stock:integer, sku:string?, description:text?"

# Catálogo global (sin tenant) con un campo código
php artisan make:module OilType --group=BusinessManagement \
    --fields="code:string?, sort_order:integer" --no-tenant
```

> El frontend genera cada campo con el control adecuado según su tipo
> (`Input`, `InputNumber`, `Switch`, `Textarea`, `DatePicker`). Ajustá el
> control o el layout a mano si necesitás algo más específico (ej. un
> `Select` para una FK).

### Reglas del nombre

- PascalCase **singular** (no plural)
- Solo letras
- No usar `Customer` (es el master, no se puede sobrescribir)
- **No usar nombres reservados.** El comando rechaza nombres que chocan con
  clases del framework que los archivos generados ya importan (el caso clásico
  es `Model`: el modelo generado hace `class Model extends Model`, que es un
  fatal contra `Illuminate\Database\Eloquent\Model`) o con palabras/tipos
  reservados de PHP (`String`, `Array`, `Match`, `Enum`…). Lista completa en
  `MakeModuleCommand::handle()` (`$reservedNames`): incluye `Model`, `Builder`,
  `Collection`, `Request`, `Controller`, `Resource`, `Validator`, `Rule`,
  `Auth`, `Str`, `DB`, `Schema`, etc. Si tu dominio es "modelo" a secas, usá un
  nombre compuesto: `EquipmentModel`, `DeviceModel`, `TapChangerModel`.

---

## 3. Qué genera el scaffold (~51 archivos)

### Backend (21 archivos)

| Archivo | Para qué |
|---|---|
| `app/Http/Controllers/{Group}/{Name}Controller.php` | Controller con CRUD + bulk + exports + imports + edit-all |
| `app/Services/{Group}/{Name}Service.php` | Lógica de negocio |
| `app/Models/{Name}.php` | Eloquent model con BelongsToTenant + Auditable + HasFavorites |
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
| `database/migrations/{timestamp}_create_{plural}_table.php` | Migración con `name`, `description`, `tenant_id`, soft-deletes, audit columns, performance indexes |
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
| Tabla `system_modules` (BD) | Inserta una fila con `permission_key='{plural}.view'` |

### Lo que el scaffold NO toca (intencionalmente)

| Item | Por qué NO lo toca | Paso de referencia |
|---|---|---|
| Capa API (Resource + ApiController + routes/api.php) | Es opcional y específica del módulo | Paso 5.10 |
| Sidebar (AppLayout.vue + lang/sidebar.php) | El icono y la traducción son decisiones de UX por módulo | Paso 5.3 |
| Permisos en `RolesAndPermissionsSeeder` | El módulo nuevo necesita permisos custom asignados a roles | Paso 5.2 |
| `config/features.php` (plan gating) | No todos los módulos requieren plan gating | Paso 5.8 |
| Tests del módulo nuevo | El scaffold clona los tests de Customer si existen | Paso 5.12 |

---

## 4. Campos base del módulo generado

El scaffold genera el módulo con **1 solo campo** de dominio:

| Campo | Tipo | Required | Para qué |
|---|---|---|---|
| `name` | string | sí | Identificador visible del registro |

Los campos específicos del dominio (description, price, stock, sku, FKs, etc.) se agregan **a mano** después del scaffold editando la migración + model + FormRequests + Form.vue + Show.vue + columns.js + lang files + exports/imports. Ver paso 5.5.

### Columnas del sistema (también generadas, automático)

| Columna | Para qué |
|---|---|
| `id` | Primary key |
| `slug` | Identificador opaco de 22 chars para URLs (ej. `/products/aBcD12...`) |
| `tenant_id` | Multi-tenant scoping. FK `ON DELETE SET NULL` a `tenants` desde 2026-05 (heredado del template master). |
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

#### 5.2. Sembrar los permisos Spatie

Editar `database/seeders/RolesAndPermissionsSeeder.php` y agregar los 4 permisos básicos al array de permisos del seeder:

```php
'{plural}.view',
'{plural}.create',
'{plural}.edit',
'{plural}.delete',
```

Asignar al rol `super` (y a `admin`/`user` si corresponde según el modelo de acceso del proyecto).

Luego correr el seeder:

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
```

> Sin este paso, ningún rol tiene permiso para ver/crear/editar el módulo, por lo tanto el sidebar no muestra la entrada y las rutas rechazan los requests con 403.

#### 5.3. Agregar la entrada al sidebar

El scaffold **no toca el sidebar** porque el icono y la traducción son decisiones específicas de cada módulo.

**a)** Editar `resources/js/Layouts/AppLayout.vue`:

```js
import { ShoppingOutlined } from '@ant-design/icons-vue';

// Dentro del array correspondiente al grupo del módulo:
{
    key: 'products',
    label: t('sidebar.products'),
    icon: ShoppingOutlined,
    href: route('business_management.products.index'),
    inertia: true,
    visible: () => can('products.view'),
    // Si el módulo requiere plan_feature:
    // visible: () => can('products.view') && canUsePlanFeature('products'),
},
```

**b)** Agregar la traducción en los 3 archivos de lang:

```php
// resources/lang/es/sidebar.php
'products' => 'Productos',

// resources/lang/en/sidebar.php
'products' => 'Products',
```

#### 5.4. Verificar build y limpiar caches

```powershell
npm run build
php artisan config:clear
php artisan route:clear
```

### Pasos RECOMENDADOS (según el dominio del módulo)

#### 5.5. Sumar columnas del dominio en la migración

`database/migrations/{timestamp}_create_products_table.php`:

```php
// Después de $table->string('name')->index();
$table->decimal('price', 10, 2)->default(0);
$table->integer('stock')->default(0);
$table->string('sku', 50)->nullable()->index();
$table->unsignedBigInteger('category_id')->nullable();
$table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
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
'price'       => 'required|numeric|min:0',
'stock'       => 'required|integer|min:0',
'sku'         => 'nullable|string|max:50',
'category_id' => 'nullable|integer|exists:categories,id',
```

#### 5.6. Relaciones del modelo (FKs salientes)

Si el módulo tiene FKs hacia otras tablas, agregar el método `belongsTo()` correspondiente en `app/Models/{Name}.php`:

```php
public function category(): BelongsTo
{
    return $this->belongsTo(Category::class);
}
```

Y cargar la relación con `with()` en el Service para evitar N+1.

#### 5.7. Dependientes (FKs entrantes a este módulo)

Si OTROS modelos referencian a este modelo con FK (ej. `Order::class` tiene `customer_id`), declarar el método `dependents()` en el modelo para que el sistema avise antes de borrar:

```php
public function dependents(): array
{
    return [
        ['model' => Order::class, 'foreign_key' => 'customer_id', 'label' => 'pedidos'],
    ];
}
```

#### 5.8. Plan gating (si el módulo es premium)

Si el módulo debe estar disponible solo en planes `pro` o `enterprise`:

**a)** Agregar la feature en `config/features.php`:

```php
'plans' => [
    'free'       => [..., 'products' => false],
    'basic'      => [..., 'products' => false],
    'pro'        => [..., 'products' => true],
    'enterprise' => [..., 'products' => true],
],
```

**b)** Aplicar el middleware en las rutas correspondientes en `routes/business_management.php`:

```php
Route::middleware(['permission:products.view', 'plan_feature:products'])->group(function () {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
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
        'name'        => ['type' => 'string', 'label' => 'Nombre'],
        'price'       => ['type' => 'number', 'label' => 'Precio'],
        'stock'       => ['type' => 'number', 'label' => 'Stock'],
        'is_active'   => ['type' => 'bool',   'label' => 'Activo'],
        'category_id' => ['type' => 'enum',   'label' => 'Categoría', 'options' => 'categories'],
        'created_at'  => ['type' => 'date',   'label' => 'Fecha de creación'],
    ];
}
```

Mirar `app/Models/Customer.php` como referencia.

#### 5.10. Capa API REST (opcional — el scaffold NO la genera)

Por defecto los módulos generados son **web-only** (Inertia). Si el módulo necesita exponerse via API REST:

**a)** Crear `app/Http/Resources/{Name}Resource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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

```php
Route::middleware(['auth:sanctum', 'abilities:products:read'])->group(function () {
    Route::get('products', [ProductApiController::class, 'index']);
    Route::get('products/{product:slug}', [ProductApiController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'abilities:products:write'])->group(function () {
    Route::post('products', [ProductApiController::class, 'store']);
    Route::put('products/{product:slug}', [ProductApiController::class, 'update']);
});

Route::middleware(['auth:sanctum', 'abilities:products:delete'])->group(function () {
    Route::delete('products/{product:slug}', [ProductApiController::class, 'destroy']);
});
```

**d)** Sumar las abilities al crear tokens (ver lógica de creación de tokens del proyecto).

**e)** Documentar con anotaciones Scribe (`@group`, `@queryParam`, `@bodyParam`, `@response`) y regenerar:

```bash
php artisan scribe:generate
```

> Solo módulos críticos exponen API. Los módulos `core` (Regions, Languages, Countries, Locales, Tenants, SystemModules, Settings) **no exponen API** por decisión de diseño.

#### 5.11. Data source para Automatizaciones (opcional)

Si quieres que las **automatizaciones** puedan consultar este módulo:

**a)** Crear `app/Services/Automations/DataSources/{Plural}DataSource.php` implementando `DataSourceContract`.

**b)** Registrarlo en `app/Services/Automations/DataSourceRegistry::register()`.

Detalles y ejemplo completo en [`AUTOMATIONS.md`](AUTOMATIONS.md) (sección 8).

#### 5.12. Tests

El scaffold clona los tests de Customer si existen en `tests/Feature/BusinessManagement/Customers/`. Verificar:

```bash
php artisan test --filter=Product
```

Si Customer no tiene tests propios, los suyos se escriben a mano usando el patrón de Region como referencia.

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

Después de clonar los archivos de Customer con find-replace, el scaffold corre **transformaciones específicas** para adaptar el módulo nuevo:

- **Quita** referencias a `cod` (era una columna específica de Customer): migration, model, FormRequests, Form.vue, Show.vue, columns.js, factory, lang, exports, import.
- **Quita** referencias a `country_id` y la relación `country()`: migration, model, scope filter, sort whitelist, filterSchema, Form.vue, Show.vue, columns.js, lang, exports, import.
- **NO agrega ningún campo nuevo de dominio** — el módulo arranca con solo `name` (que viene del Customer master). El dev agrega los campos del dominio post-scaffold.

Estas transformaciones se aplican a ~12 archivos (migration, model, FormRequests, Form.vue, Show.vue, columns.js, factory, lang/{es,en}, exports, imports).

Si el patch específico no encuentra el patrón esperado en algún archivo, el scaffold emite un `warn` pero **no aborta** — el archivo queda con el contenido base de Customer y se ajusta a mano después.

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
| Tabla creada en BD | `\d products` en psql (debe listar todas las columnas) |
| Permisos sembrados | `php artisan tinker` → `Spatie\Permission\Models\Permission::where('name', 'like', 'products.%')->count()` debe dar 4+ |
| Rol super tiene los permisos | `Role::where('name', 'super')->first()->hasPermissionTo('products.view')` debe dar true |
| Rutas funcionan | `php artisan route:list --name=business_management.products` debe listar 20+ rutas |
| Sidebar muestra el módulo | Login como super → verificar que aparece el item con su icono |
| El listado vacío carga sin error | Visitar `/es/business_management/products` (login como super) |
| Crear un registro funciona | Form → llenar `name` (required) → guardar → debe aparecer en el listado |
| Build pasa | `npm run build` sin errores |

---

## 10. Errores comunes durante el scaffold

| Error | Causa | Solución |
|---|---|---|
| "El módulo X ya existe" | Hay archivos del módulo en el filesystem | Borrar manualmente los archivos del intento anterior |
| "Patron no encontrado en archivo X" (warn) | El scaffold espera un patrón regex que cambió en Customer base | Ajustar el archivo a mano después del scaffold (no es fatal) |
| "Permission denied" al escribir archivos | El usuario no tiene permiso de escritura en `app/`, `resources/`, etc. | `chmod -R u+w app resources database routes config` |
| "system_modules: permission_key ya existe" | Hay un row en BD con el mismo permission_key (intento anterior) | `DB::table('system_modules')->where('permission_key', 'products.view')->delete()` antes de re-correr |
| Sidebar no muestra el módulo nuevo | Falta el paso 5.3 (agregar item al sidebar) | Editar AppLayout.vue + 3 archivos lang/sidebar.php |
| Listado da 403 | Falta el paso 5.2 (sembrar permisos) | Correr el seeder de Roles |
| Listado da 404 | Falta el paso 5.4 (clear de caches) | `php artisan route:clear` + `npm run build` |

---

## 11. Documentación relacionada

- [`README-DEV.md`](../README-DEV.md) — workflow general de desarrollo
- [`PERMISSIONS.md`](PERMISSIONS.md) — sistema de permisos Spatie + super bypass
- [`plan-features.md`](plan-features.md) — cómo gatear un módulo nuevo por plan
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — sección 13 sobre la decisión Customer master
- [`STRUCTURE.md`](STRUCTURE.md) — estructura general del repo
- [`AUTOMATIONS.md`](AUTOMATIONS.md) — cómo registrar un data source nuevo (paso 5.11)
