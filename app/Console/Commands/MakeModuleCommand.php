<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * php artisan make:module {Name} [--group=BusinessManagement]
 *
 * Clona el módulo Brand (master template per-tenant, BusinessManagement)
 * hacia un módulo nuevo usando find-replace de identificadores. NO usa stubs
 * reducidos — copia los archivos reales de Brand 1:1 para garantizar paridad
 * de features.
 *
 * Brand es un master LIMPIO: trae name + code + is_active + sort_order +
 * tenant_id (sin cod/country/address/logo/jerarquía). El módulo nuevo arranca
 * con name + code + is_active + sort_order como campos de dominio. El usuario
 * después agrega columnas custom (price, stock, FKs, etc.) editando la
 * migration, model, FormRequests, Form.vue, Show.vue, columns.js, lang files y
 * exports/imports.
 *
 * Brand usa los traits BelongsToTenantOrGlobal + Lockable, y su controller usa
 * HandlesRecordLocking (métodos lock()/unlock()).
 *
 * Operaciones:
 *   1. Validar nombre (PascalCase singular) y que NO sea Brand (master).
 *   2. Calcular reemplazos (singular/plural, PascalCase/snake_case).
 *   3. Verificar que el módulo no exista (idempotencia).
 *   4. Clonar archivos backend (controller, service, model, requests,
 *      jobs, imports, exports, migration, factory).
 *   5. Clonar archivos frontend (pages, components, config).
 *   6. Clonar tests (si existen en Brand).
 *   7. Clonar config + lang (es/en).
 *   8. Append routes al archivo del grupo (crear si no existe).
 *   9. Insertar entradas en config/polymorphic.php y config/purge.php.
 *  10. Auto-registrar fila en system_modules (si la tabla existe).
 *  11. Aplicar post-procesado: inyectar columnas de lock en la migración,
 *      generar el blade del PDF, opcionalmente --no-tenant / --fields.
 *  12. Imprimir checklist de pasos manuales.
 *
 * En caso de error a mitad, rollback automático (borrar archivos creados).
 *
 * Ejemplos:
 *   php artisan make:module Patient  --group=HealthManagement
 *   php artisan make:module Doctor   --group=HealthManagement
 *   php artisan make:module Provider --group=BusinessManagement
 */
class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
        {name : Nombre del módulo en singular PascalCase (ej. Patient, Doctor)}
        {--group=BusinessManagement : Grupo de namespace/routing (ej. HealthManagement)}
        {--fields= : Campos del dominio separados por coma. Formato "col:tipo" con ? para nullable. Ej: "price:decimal, stock:integer, sku:string?, notes:text?"}
        {--no-tenant : Genera un catalogo GLOBAL sin tenant_id ni BelongsToTenant (ej. oil_types, catalogos compartidos por todos los workspaces)}';

    protected $description = 'Clona el módulo Brand hacia un módulo nuevo, con campos custom y opcion sin-tenant';

    /**
     * Campos del dominio parseados desde --fields.
     * @var array<int, array{name:string, type:string, nullable:bool}>
     */
    protected array $fields = [];

    /** true si el modulo es catalogo global (--no-tenant). */
    protected bool $noTenant = false;

    /** Archivos creados durante este run — usado para rollback si algo falla. */
    protected array $createdFiles = [];

    /** Backup de archivos modificados (path => contenido original) para rollback. */
    protected array $modifiedFiles = [];

    /** Tabla de reemplazos calculada. */
    protected array $replacements = [];

    /** Nombre original (PascalCase singular). */
    protected string $module;

    /** Grupo de routing/namespace (PascalCase). */
    protected string $group;

    public function handle(): int
    {
        $this->module = $this->argument('name');
        $this->group  = $this->option('group');

        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $this->module)) {
            $this->error("El nombre debe estar en PascalCase singular (ej. Patient, Doctor). Recibido: {$this->module}");
            return self::FAILURE;
        }

        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $this->group)) {
            $this->error("El grupo debe estar en PascalCase (ej. HealthManagement). Recibido: {$this->group}");
            return self::FAILURE;
        }

        // No permitir override del master template. Brand es el nuevo master
        // (la fuente que clona el scaffold); Customer sigue protegido por ser
        // el patrón de referencia con API/dominio comercial.
        if (in_array($this->module, ['Brand', 'Customer'], true)) {
            $this->error("No se puede generar un módulo llamado {$this->module} — Brand es el master template que clona este comando y Customer es el patrón de referencia. Usa otro nombre.");
            return self::FAILURE;
        }

        // Nombres reservados: chocan con clases del framework que los archivos
        // generados YA importan (p.ej. `Model` colisiona con
        // Illuminate\Database\Eloquent\Model → `class Model extends Model` es
        // un fatal), o son palabras/tipos reservados de PHP que no pueden ser
        // nombre de clase (`class String {}` no compila). Se compara en
        // minúsculas porque el nombre ya viene forzado a PascalCase.
        $reservedNames = [
            // Clases de Laravel/Illuminate importadas en los archivos generados.
            'model', 'builder', 'collection', 'request', 'controller', 'response',
            'resource', 'validator', 'rule', 'auth', 'gate', 'str', 'arr', 'db',
            'schema', 'blueprint', 'carbon', 'eloquent', 'pivot', 'relation',
            // Palabras reservadas / tipos de PHP (no pueden ser nombre de clase).
            'class', 'interface', 'trait', 'enum', 'function', 'fn', 'list',
            'array', 'bool', 'boolean', 'int', 'integer', 'float', 'double',
            'string', 'object', 'mixed', 'void', 'null', 'true', 'false',
            'iterable', 'callable', 'static', 'self', 'parent', 'match',
            'readonly', 'never', 'numeric', 'namespace', 'use', 'new', 'return',
            'echo', 'print', 'default', 'switch', 'case',
        ];
        if (in_array(strtolower($this->module), $reservedNames, true)) {
            $this->error("El nombre '{$this->module}' es reservado (choca con una clase del framework o una palabra reservada de PHP). Usa un nombre compuesto, ej. {$this->module}Type o EquipmentModel.");
            return self::FAILURE;
        }

        // Flags nuevos: campos custom + catalogo sin tenant.
        $this->noTenant = (bool) $this->option('no-tenant');
        $parsed = $this->parseFields((string) $this->option('fields'));
        if ($parsed === null) {
            return self::FAILURE; // parseFields ya imprimio el error
        }
        $this->fields = $parsed;

        $this->replacements = $this->buildReplacements();

        // Idempotencia: si el módulo ya existe, abort sin tocar nada.
        if ($this->moduleAlreadyExists()) {
            $this->error("El módulo {$this->module} ya existe. Para regenerarlo, borra los archivos manualmente primero.");
            return self::FAILURE;
        }

        $this->info("Generando módulo {$this->module} (grupo: {$this->group})");
        $this->newLine();

        try {
            $this->cloneBackend();
            $this->cloneFrontend();
            $this->cloneTests();
            $this->cloneConfigAndLang();
            $this->cloneMigrationAndFactory();
            $this->appendRoutes();
            $this->registerInPolymorphicConfig();
            $this->registerInPurgeConfig();
            $this->registerInSystemModulesTable();
            $this->applyFieldTransformations();
        } catch (\Throwable $e) {
            $this->error("ERROR durante la generación: {$e->getMessage()}");
            $this->warn("Iniciando rollback automático...");
            $this->rollback();
            return self::FAILURE;
        }

        $this->printChecklist();
        return self::SUCCESS;
    }

    /**
     * Tipos soportados en --fields y como se materializa cada uno.
     * Cada tipo define:
     *   migration : metodo de Blueprint (sin el ->nullable, se agrega aparte)
     *   cast      : valor para $casts (null = no castear)
     *   rule      : regla base de validacion Laravel (sin nullable/required)
     *   faker     : expresion faker para la factory
     *   input     : control Vue para Form.vue ('text'|'number'|'switch'|'textarea'|'date')
     */
    protected function fieldTypeMap(): array
    {
        return [
            'string'   => ['migration' => "string('%s')",          'cast' => null,        'rule' => 'string|max:255',        'faker' => '$this->faker->word()',                 'input' => 'text'],
            'text'     => ['migration' => "text('%s')",            'cast' => null,        'rule' => 'string',                'faker' => '$this->faker->sentence()',             'input' => 'textarea'],
            'integer'  => ['migration' => "integer('%s')",         'cast' => 'integer',   'rule' => 'integer',               'faker' => '$this->faker->numberBetween(0, 1000)', 'input' => 'number'],
            'decimal'  => ['migration' => "decimal('%s', 10, 2)",  'cast' => 'decimal:2', 'rule' => 'numeric',               'faker' => '$this->faker->randomFloat(2, 0, 9999)','input' => 'number'],
            'boolean'  => ['migration' => "boolean('%s')",         'cast' => 'boolean',   'rule' => 'boolean',               'faker' => '$this->faker->boolean()',              'input' => 'switch'],
            'date'     => ['migration' => "date('%s')",            'cast' => 'date',      'rule' => 'date',                  'faker' => '$this->faker->date()',                 'input' => 'date'],
            'datetime' => ['migration' => "dateTime('%s')",        'cast' => 'datetime',  'rule' => 'date',                  'faker' => '$this->faker->dateTime()',             'input' => 'date'],
            'year'     => ['migration' => "integer('%s')",         'cast' => 'integer',   'rule' => 'integer|min:1900|max:2100', 'faker' => '$this->faker->year()',             'input' => 'number'],
        ];
    }

    /**
     * Parsea --fields "col:tipo, col2:tipo?" → array de specs.
     * Devuelve [] si no hay campos, o null si hubo error de formato (ya
     * impreso). El `?` al final del tipo marca nullable.
     */
    protected function parseFields(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return [];

        $map = $this->fieldTypeMap();
        $reserved = ['id', 'slug', 'name', 'is_active', 'tenant_id', 'created_by',
                     'deleted_by', 'deleted_description', 'created_at', 'updated_at', 'deleted_at'];
        $out = [];

        foreach (explode(',', $raw) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;

            if (!str_contains($chunk, ':')) {
                $this->error("Campo invalido '{$chunk}'. Formato esperado: col:tipo (ej. price:decimal).");
                return null;
            }
            [$col, $type] = array_map('trim', explode(':', $chunk, 2));

            $nullable = str_ends_with($type, '?');
            $type = rtrim($type, '?');

            if (!preg_match('/^[a-z][a-z0-9_]*$/', $col)) {
                $this->error("Nombre de columna invalido '{$col}'. Usa snake_case (ej. unit_price).");
                return null;
            }
            if (in_array($col, $reserved, true)) {
                $this->error("La columna '{$col}' es reservada por el scaffold. Elige otro nombre.");
                return null;
            }
            if (!isset($map[$type])) {
                $this->error("Tipo '{$type}' no soportado para '{$col}'. Tipos: " . implode(', ', array_keys($map)) . '.');
                return null;
            }

            $out[] = ['name' => $col, 'type' => $type, 'nullable' => $nullable];
        }

        return $out;
    }

    /** Lineas Blueprint para la migracion (una por campo del dominio). */
    protected function fieldsMigrationLines(): string
    {
        if (empty($this->fields)) return '';
        $map = $this->fieldTypeMap();
        $lines = [];
        foreach ($this->fields as $f) {
            $method = sprintf($map[$f['type']]['migration'], $f['name']);
            $line = "            \$table->{$method}";
            if ($f['nullable']) $line .= "->nullable()";
            $line .= ";";
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /** Nombres de campo para el $fillable (entre comillas, separados por coma). */
    protected function fieldsFillable(): string
    {
        if (empty($this->fields)) return '';
        return implode(', ', array_map(fn ($f) => "'{$f['name']}'", $this->fields));
    }

    /** Lineas de $casts (solo campos que castean). */
    protected function fieldsCastLines(): string
    {
        $map = $this->fieldTypeMap();
        $lines = [];
        foreach ($this->fields as $f) {
            $cast = $map[$f['type']]['cast'];
            if ($cast !== null) {
                $lines[] = "        '{$f['name']}' => '{$cast}',";
            }
        }
        return implode("\n", $lines);
    }

    /** Reglas de validacion para los FormRequest. */
    protected function fieldsRuleLines(): string
    {
        $map = $this->fieldTypeMap();
        $lines = [];
        foreach ($this->fields as $f) {
            $base = $map[$f['type']]['rule'];
            $prefix = $f['nullable'] ? 'nullable' : 'required';
            $lines[] = "            '{$f['name']}' => '{$prefix}|{$base}',";
        }
        return implode("\n", $lines);
    }

    /** Lineas de la factory definition(). */
    protected function fieldsFactoryLines(): string
    {
        $map = $this->fieldTypeMap();
        $lines = [];
        foreach ($this->fields as $f) {
            $lines[] = "            '{$f['name']}' => {$map[$f['type']]['faker']},";
        }
        return implode("\n", $lines);
    }

    /** Keys de i18n para los labels de cada campo. */
    protected function fieldsLangLines(): string
    {
        $lines = [];
        foreach ($this->fields as $f) {
            $label = Str::headline($f['name']); // unit_price → Unit Price
            $lines[] = "    '{$f['name']}' => '{$label}',";
        }
        return implode("\n", $lines);
    }

    /**
     * Calcula todos los reemplazos. ORDEN crítico: plurales primero,
     * sino el replace de `Brand` también afecta `Brands`.
     */
    protected function buildReplacements(): array
    {
        // Names del módulo nuevo.
        $newSingular = $this->module;                       // Patient
        $newPlural   = $this->plural($newSingular);         // Patients
        $newLower    = Str::camel($newSingular);            // patient
        // BUG FIX: snake_case para rutas/tablas/i18n/slugs (oil_types), NO camel.
        // Con modulos de 1 palabra camel y snake coinciden (customers); con 2+
        // palabras camel daba "oilTypes" y rompia rutas/SQL/i18n. Separamos:
        //   $newLowerPl  → snake_case  (oil_types)  : rutas, tablas, i18n, module-ids
        //   $newCamelPl  → camelCase   (oilTypes)   : SOLO nombres de funcion JS
        $newLowerPl  = Str::snake($newPlural);             // oil_types
        $newCamelPl  = Str::camel($newPlural);             // oilTypes

        // Group names.
        $newGroup    = $this->group;                        // HealthManagement
        $newGroupLower = Str::snake($newGroup);             // health_management

        // ORDEN: primero todo lo plural/largo, después singular/corto.
        // PHP str_replace aplica los reemplazos en el orden del array y NO
        // re-procesa los strings ya reemplazados, así que es seguro.
        return [
            // ─── Request class names (renombrado completo Brand → New) ───
            // Los archivos de Brand ya están renombrados como Store{Brand}Request.php
            // así que aquí solo necesitamos mapear "Brand" en el class name.
            // Estos van primero para evitar el doble-replace del fallback final.
            'BulkDeleteBrandRequest'     => "BulkDelete{$newSingular}Request",
            'BulkSetActiveBrandRequest'  => "BulkSetActive{$newSingular}Request",
            'BulkRestoreBrandRequest'    => "BulkRestore{$newSingular}Request",
            'EditAllUpdateBrandRequest'  => "EditAllUpdate{$newSingular}Request",
            'ForceDeleteBrandRequest'    => "ForceDelete{$newSingular}Request",
            'DeleteBrandRequest'         => "Delete{$newSingular}Request",
            'UpdateBrandRequest'         => "Update{$newSingular}Request",
            'StoreBrandRequest'          => "Store{$newSingular}Request",
            'ImportBrandRequest'         => "Import{$newSingular}Request",

            // ─── Class names específicos (más largos primero) ───
            'BaseBrandExportJob'         => "Base{$newSingular}ExportJob",
            'BulkBrandsActionJob'        => "Bulk{$newPlural}ActionJob",
            'GenerateBrandsCsvJob'       => "Generate{$newPlural}CsvJob",
            'GenerateBrandsExcelJob'     => "Generate{$newPlural}ExcelJob",
            'GenerateBrandsPdfJob'       => "Generate{$newPlural}PdfJob",
            'GenerateBrandsWordJob'      => "Generate{$newPlural}WordJob",
            'BrandsImportTemplate'       => "{$newPlural}ImportTemplate",
            'BrandsExport'               => "{$newPlural}Export",
            'BrandsImport'               => "{$newPlural}Import",
            'BrandsWord'                 => "{$newPlural}Word",
            'BrandResource'              => "{$newSingular}Resource",
            'BrandController'            => "{$newSingular}Controller",
            'BrandService'               => "{$newSingular}Service",
            'BrandFactory'               => "{$newSingular}Factory",

            // ─── Component / Vue file names (más largos primero) ───
            'BrandsTrashBulkBar'         => "{$newPlural}TrashBulkBar",
            'BrandsBulkDeleteModal'      => "{$newPlural}BulkDeleteModal",
            'BrandsFavoriteCell'         => "{$newPlural}FavoriteCell",
            'BrandsEditAllTable'         => "{$newPlural}EditAllTable",
            'BrandsMobileBottomBar'      => "{$newPlural}MobileBottomBar",
            'BrandsForceDeleteModal'     => "{$newPlural}ForceDeleteModal",
            'BrandsMobileDrawers'        => "{$newPlural}MobileDrawers",
            'BrandsDetailDrawer'         => "{$newPlural}DetailDrawer",
            'BrandsActionsCell'          => "{$newPlural}ActionsCell",
            'BrandsPageHeader'           => "{$newPlural}PageHeader",
            'BrandsEmptyState'           => "{$newPlural}EmptyState",
            'BrandsBulkBar'              => "{$newPlural}BulkBar",

            // ─── Config exports / config filters function names (camelCase JS) ───
            'brandsFilterFields'         => "{$newCamelPl}FilterFields",
            'brandsEmptyFilters'         => "{$newCamelPl}EmptyFilters",
            'hydrateBrandsFilters'       => "hydrate{$newPlural}Filters",
            'brandsFiltersToQuery'       => "{$newCamelPl}FiltersToQuery",
            'brandsFiltersSummary'       => "{$newCamelPl}FiltersSummary",
            'brandsTableColumns'         => "{$newCamelPl}TableColumns",
            'brandsTrashColumns'         => "{$newCamelPl}TrashColumns",
            'brandsExportableColumns'    => "{$newCamelPl}ExportableColumns",
            'brandsExportEndpoints'      => "{$newCamelPl}ExportEndpoints",
            'brandsTourSteps'            => "{$newCamelPl}TourSteps",

            // ─── Plurales (Pages folder, Components folder) ───
            'Brands/'                    => "{$newPlural}/",
            "\\Brands\\"                 => "\\{$newPlural}\\",
            'Brands\\'                   => "{$newPlural}\\",
            '"Brands"'                   => "\"{$newPlural}\"",
            "'Brands'"                   => "'{$newPlural}'",
            'Brands '                    => "{$newPlural} ",
            ' Brands'                    => " {$newPlural}",

            // ─── Singulares (path Brand/, namespace \Brand\) ───
            'Brand/'                     => "{$newSingular}/",
            "\\Brand\\"                  => "\\{$newSingular}\\",
            'Brand\\'                    => "{$newSingular}\\",
            'Brand::class'               => "{$newSingular}::class",
            '\App\Models\Brand'          => "\\App\\Models\\{$newSingular}",
            'App\Models\Brand'           => "App\\Models\\{$newSingular}",
            'App\Models\\Brand'          => "App\\Models\\{$newSingular}",
            'use App\Models\Brand;'      => "use App\\Models\\{$newSingular};",
            ' Brand '                    => " {$newSingular} ",
            ' Brand,'                    => " {$newSingular},",
            ' Brand;'                    => " {$newSingular};",
            ' Brand$'                    => " {$newSingular}\$",
            '(Brand '                    => "({$newSingular} ",
            'Brand $'                    => "{$newSingular} \$",
            'extends Brand'              => "extends {$newSingular}",

            // ─── Group namespace (PascalCase) ───
            'BusinessManagement\\Brand'  => "{$newGroup}\\{$newSingular}",
            'BusinessManagement\\Brands' => "{$newGroup}\\{$newPlural}",
            'Controllers\\BusinessManagement' => "Controllers\\{$newGroup}",
            'Services\\BusinessManagement'    => "Services\\{$newGroup}",
            'Jobs\\BusinessManagement'        => "Jobs\\{$newGroup}",
            'Imports\\BusinessManagement'     => "Imports\\{$newGroup}",
            'Exports\\BusinessManagement'     => "Exports\\{$newGroup}",
            'Requests\\BusinessManagement'    => "Requests\\{$newGroup}",
            'Tests\\Feature\\BusinessManagement' => "Tests\\Feature\\{$newGroup}",
            'Feature\\BusinessManagement'     => "Feature\\{$newGroup}",
            'namespace App\Http\Controllers\BusinessManagement' => "namespace App\\Http\\Controllers\\{$newGroup}",
            'namespace Tests\Feature\BusinessManagement' => "namespace Tests\\Feature\\{$newGroup}",

            // ─── snake_case plural (table name, route prefix, slug) ───
            // `brands` como table → tiene que ir antes de `brand` singular.
            'brands_name_unique_active'  => "{$newLowerPl}_name_unique_active",
            'idx_brands_'                => "idx_{$newLowerPl}_",
            'brands.name'                => "{$newLowerPl}.name",
            'brands.code'                => "{$newLowerPl}.code",
            'brands.is_active'           => "{$newLowerPl}.is_active",
            'brands.sort_order'          => "{$newLowerPl}.sort_order",
            'brands.tenant_id'           => "{$newLowerPl}.tenant_id",
            'brands.created_at'          => "{$newLowerPl}.created_at",
            'brands.updated_at'          => "{$newLowerPl}.updated_at",
            'brands.created_by'          => "{$newLowerPl}.created_by",
            'brands.deleted_at'          => "{$newLowerPl}.deleted_at",
            'brands.deleted_by'          => "{$newLowerPl}.deleted_by",
            'brands.id'                  => "{$newLowerPl}.id",
            'brands.slug'                => "{$newLowerPl}.slug",
            'brands.show'                => "{$newLowerPl}.show",
            'brands.index'               => "{$newLowerPl}.index",
            'brands.store'               => "{$newLowerPl}.store",
            'brands.update'              => "{$newLowerPl}.update",
            'brands.edit'                => "{$newLowerPl}.edit",
            'brands.create'              => "{$newLowerPl}.create",
            'brands.destroy'             => "{$newLowerPl}.destroy",
            'brands.trash'               => "{$newLowerPl}.trash",
            'brands.restore'             => "{$newLowerPl}.restore",
            'brands.force_delete'        => "{$newLowerPl}.force_delete",
            'brands.export_'             => "{$newLowerPl}.export_",
            'brands.bulk_'               => "{$newLowerPl}.bulk_",
            'brands.import'              => "{$newLowerPl}.import",
            'brands.undo_'               => "{$newLowerPl}.undo_",
            'brands.delete'              => "{$newLowerPl}.delete",
            'brands.deleteSave'          => "{$newLowerPl}.deleteSave",
            'brands.duplicate'           => "{$newLowerPl}.duplicate",
            'brands.edit_all'            => "{$newLowerPl}.edit_all",
            'brands.lock'                => "{$newLowerPl}.lock",
            'brands.unlock'              => "{$newLowerPl}.unlock",
            "'brands'"                   => "'{$newLowerPl}'",
            '"brands"'                   => "\"{$newLowerPl}\"",
            '/brands'                    => "/{$newLowerPl}",
            'brands/'                    => "{$newLowerPl}/",
            'brands:'                    => "{$newLowerPl}:",
            'on brands'                  => "on {$newLowerPl}",
            'on `brands`'                => "on `{$newLowerPl}`",
            "Schema::create('brands'"    => "Schema::create('{$newLowerPl}'",
            "Schema::table('brands'"     => "Schema::table('{$newLowerPl}'",
            'create_brands_table'        => "create_{$newLowerPl}_table",
            'dropIfExists(\'brands\')'   => "dropIfExists('{$newLowerPl}')",
            'lang/brands'                => "lang/{$newLowerPl}",
            'brands.php'                 => "{$newLowerPl}.php",
            ' brands.'                   => " {$newLowerPl}.",
            '__(\'brands.'               => "__('{$newLowerPl}.",
            '__("brands.'                => "__(\"{$newLowerPl}.",
            't(\'brands.'                => "t('{$newLowerPl}.",
            't("brands.'                 => "t(\"{$newLowerPl}.",
            "trans('brands."             => "trans('{$newLowerPl}.",

            // ─── snake_case singular ───
            // `brand` solito como param de route, var, etc.
            'Brand $brand'               => "{$newSingular} \${$newLower}",
            '$brand '                    => "\${$newLower} ",
            '$brand->'                   => "\${$newLower}->",
            '$brand,'                    => "\${$newLower},",
            '$brand)'                    => "\${$newLower})",
            '$brand;'                    => "\${$newLower};",
            "'brand'"                    => "'{$newLower}'",
            '"brand"'                    => "\"{$newLower}\"",
            ' brand '                    => " {$newLower} ",
            '/{brand}'                   => "/{{$newLower}}",
            '{brand}'                    => "{{$newLower}}",
            'brand:slug'                 => "{$newLower}:slug",
            'brand_id'                   => "{$newLower}_id",

            // ─── Group URL prefix (snake_case) ───
            'business_management.'       => "{$newGroupLower}.",
            "'business_management'"      => "'{$newGroupLower}'",
            'business_management/'       => "{$newGroupLower}/",
            'prefix(\'business_management\')' => "prefix('{$newGroupLower}')",
            'name(\'business_management.\')'  => "name('{$newGroupLower}.')",

            // ─── auditModule (string en model) ───
            "auditModule = 'brands'"     => "auditModule = '{$newLowerPl}'",
            "'module'         => 'brands'" => "'module'         => '{$newLowerPl}'",
            "'module' => 'brands'"       => "'module' => '{$newLowerPl}'",

            // ─── Test class names ───
            'class BrandTestCase'        => "class {$newSingular}TestCase",
            'class BrandCrudTest'        => "class {$newSingular}CrudTest",
            'class BrandSoftDeleteTest'  => "class {$newSingular}SoftDeleteTest",
            'class BrandPermissionTest'  => "class {$newSingular}PermissionTest",
            'class BrandImportTest'      => "class {$newSingular}ImportTest",
            'class BrandExportJobTest'   => "class {$newSingular}ExportJobTest",
            'class BrandAuditLogTest'    => "class {$newSingular}AuditLogTest",
            'class BrandPerformanceTest' => "class {$newSingular}PerformanceTest",
            'class BrandAdvancedFeaturesTest' => "class {$newSingular}AdvancedFeaturesTest",
            'class BrandDuplicatePreventionTest' => "class {$newSingular}DuplicatePreventionTest",
            'class BrandTest '           => "class {$newSingular}Test ",
            'class BrandServiceTest'     => "class {$newSingular}ServiceTest",
            'BrandTestCase'              => "{$newSingular}TestCase",

            // ─── Fallback: cualquier Brand/brand restante (al final) ───
            // Cuidado: el orden anterior ya capturó los casos seguros.
            // Solo dejamos un fallback genérico para identificadores no listados.
            'Brands'                     => $newPlural,
            'Brand'                      => $newSingular,
            'brands'                     => $newLowerPl,
            'brand'                      => $newLower,
        ];
    }

    protected function plural(string $singular): string
    {
        // Casos comunes en español que Doctrine inflector no pluraliza bien.
        $map = [
            'Patient'     => 'Patients',
            'Doctor'      => 'Doctors',
            'Transformer' => 'Transformers',
            'Provider'    => 'Providers',
            'Paciente'    => 'Pacientes',
            'Producto'    => 'Productos',
            'Cliente'     => 'Clientes',
        ];
        if (isset($map[$singular])) {
            return $map[$singular];
        }
        return Str::plural($singular);
    }

    protected function moduleAlreadyExists(): bool
    {
        $checks = [
            "app/Models/{$this->module}.php",
            "app/Http/Controllers/{$this->group}/{$this->module}Controller.php",
            "app/Services/{$this->group}/{$this->module}Service.php",
            "config/" . $this->snakePlural() . ".php",
            "resources/js/Pages/{$this->plural($this->module)}/Index.vue",
        ];
        foreach ($checks as $rel) {
            if (File::exists(base_path($rel))) {
                $this->error("Archivo ya existe: {$rel}");
                return true;
            }
        }
        return false;
    }

    protected function snakePlural(): string
    {
        return Str::snake($this->plural($this->module));
    }

    /**
     * Clona un archivo aplicando find-replace.
     */
    protected function cloneFile(string $sourceRel, string $destRel): void
    {
        $sourceAbs = base_path($sourceRel);
        $destAbs   = base_path($destRel);

        if (!File::exists($sourceAbs)) {
            throw new \RuntimeException("Source no existe: {$sourceRel}");
        }
        if (File::exists($destAbs)) {
            $this->warn("  SKIP (ya existe): {$destRel}");
            return;
        }

        $content = file_get_contents($sourceAbs);
        $content = str_replace(array_keys($this->replacements), array_values($this->replacements), $content);

        File::ensureDirectoryExists(dirname($destAbs));
        // Escritura sin BOM — crítico en Windows PowerShell.
        file_put_contents($destAbs, $content);

        $this->createdFiles[] = $destAbs;
        $this->line("  CREADO: {$destRel}");
    }

    protected function cloneBackend(): void
    {
        $this->info('Backend...');
        $singular = $this->module;
        $plural   = $this->plural($singular);
        $group    = $this->group;

        // Controller.
        $this->cloneFile(
            'app/Http/Controllers/BusinessManagement/BrandController.php',
            "app/Http/Controllers/{$group}/{$singular}Controller.php"
        );

        // Service.
        $this->cloneFile(
            'app/Services/BusinessManagement/BrandService.php',
            "app/Services/{$group}/{$singular}Service.php"
        );

        // Model.
        $this->cloneFile(
            'app/Models/Brand.php',
            "app/Models/{$singular}.php"
        );

        // NOTA: NO clonamos BrandResource (Brand no expone API). La capa API
        // (Resource + ApiController + rutas en routes/api.php) es opcional y
        // específica del módulo — se implementa a mano post-scaffold solo si el
        // módulo va a exponerse via API. Los módulos generados por defecto son
        // web-only (Inertia).

        // FormRequests (9 archivos).
        // Brand ya los tiene renombrados como Store{Brand}Request.php,
        // los reemplazos del array buildReplacements() se encargan del rename
        // a Store{NewName}Request.
        $requests = [
            'StoreBrandRequest.php'         => "Store{$singular}Request.php",
            'UpdateBrandRequest.php'        => "Update{$singular}Request.php",
            'DeleteBrandRequest.php'        => "Delete{$singular}Request.php",
            'ForceDeleteBrandRequest.php'   => "ForceDelete{$singular}Request.php",
            'BulkDeleteBrandRequest.php'    => "BulkDelete{$singular}Request.php",
            'BulkSetActiveBrandRequest.php' => "BulkSetActive{$singular}Request.php",
            'BulkRestoreBrandRequest.php'   => "BulkRestore{$singular}Request.php",
            'EditAllUpdateBrandRequest.php' => "EditAllUpdate{$singular}Request.php",
            'ImportBrandRequest.php'        => "Import{$singular}Request.php",
        ];
        foreach ($requests as $src => $dst) {
            $srcRel = "app/Http/Requests/BusinessManagement/Brand/{$src}";
            if (File::exists(base_path($srcRel))) {
                $this->cloneFile(
                    $srcRel,
                    "app/Http/Requests/{$group}/{$singular}/{$dst}"
                );
            }
        }

        // Imports.
        $this->cloneFile(
            'app/Imports/BusinessManagement/Brands/BrandsImport.php',
            "app/Imports/{$group}/{$plural}/{$plural}Import.php"
        );

        // Exports (3 archivos).
        $exports = [
            'BrandsExport.php'         => "{$plural}Export.php",
            'BrandsWord.php'           => "{$plural}Word.php",
            'BrandsImportTemplate.php' => "{$plural}ImportTemplate.php",
        ];
        foreach ($exports as $src => $dst) {
            $srcRel = "app/Exports/BusinessManagement/Brands/{$src}";
            if (File::exists(base_path($srcRel))) {
                $this->cloneFile(
                    $srcRel,
                    "app/Exports/{$group}/{$plural}/{$dst}"
                );
            }
        }

        // Jobs (6 archivos).
        $jobs = [
            'BaseBrandExportJob.php'     => "Base{$singular}ExportJob.php",
            'BulkBrandsActionJob.php'    => "Bulk{$plural}ActionJob.php",
            'GenerateBrandsCsvJob.php'   => "Generate{$plural}CsvJob.php",
            'GenerateBrandsExcelJob.php' => "Generate{$plural}ExcelJob.php",
            'GenerateBrandsPdfJob.php'   => "Generate{$plural}PdfJob.php",
            'GenerateBrandsWordJob.php'  => "Generate{$plural}WordJob.php",
        ];
        foreach ($jobs as $src => $dst) {
            $srcRel = "app/Jobs/BusinessManagement/Brands/{$src}";
            if (File::exists(base_path($srcRel))) {
                $this->cloneFile(
                    $srcRel,
                    "app/Jobs/{$group}/{$plural}/{$dst}"
                );
            }
        }
    }

    protected function cloneFrontend(): void
    {
        $this->info('Frontend...');
        $plural = $this->plural($this->module);

        // Pages (6 archivos: Index, Show, Form, Delete, Trash, EditAll).
        foreach (['Index', 'Show', 'Form', 'Delete', 'Trash', 'EditAll'] as $page) {
            $src = "resources/js/Pages/Brands/{$page}.vue";
            $dst = "resources/js/Pages/{$plural}/{$page}.vue";
            if (File::exists(base_path($src))) {
                $this->cloneFile($src, $dst);
            }
        }

        // Page configs (5 archivos).
        foreach (['columns', 'filters', 'exports', 'tour', 'trashColumns'] as $cfg) {
            $src = "resources/js/Pages/Brands/config/{$cfg}.js";
            $dst = "resources/js/Pages/{$plural}/config/{$cfg}.js";
            if (File::exists(base_path($src))) {
                $this->cloneFile($src, $dst);
            }
        }

        // Components (todos los Brands/Brands*.vue).
        $componentDir = base_path('resources/js/Components/Brands');
        if (File::isDirectory($componentDir)) {
            foreach (File::files($componentDir) as $file) {
                $filename = $file->getFilename(); // BrandsBulkBar.vue
                // Renombrar Brands* a {Plural}*.
                $newFilename = str_replace('Brands', $plural, $filename);
                $src = "resources/js/Components/Brands/{$filename}";
                $dst = "resources/js/Components/{$plural}/{$newFilename}";
                $this->cloneFile($src, $dst);
            }
        }
    }

    protected function cloneTests(): void
    {
        $this->info('Tests...');
        $singular = $this->module;
        $plural   = $this->plural($singular);
        $group    = $this->group;

        // Feature tests (si existen — Brand puede no tener tests todavía).
        $testDir = base_path('tests/Feature/BusinessManagement/Brands');
        if (File::isDirectory($testDir)) {
            foreach (File::files($testDir) as $file) {
                $filename = $file->getFilename();
                // BrandCrudTest.php → PatientCrudTest.php
                $newFilename = preg_replace('/^Brand/', $singular, $filename);
                $src = "tests/Feature/BusinessManagement/Brands/{$filename}";
                $dst = "tests/Feature/{$group}/{$plural}/{$newFilename}";
                $this->cloneFile($src, $dst);
            }
        } else {
            $this->line('  SKIP tests/Feature/BusinessManagement/Brands (no existe — Brand aun sin tests)');
        }

        // Unit tests.
        if (File::exists(base_path('tests/Unit/Models/BrandTest.php'))) {
            $this->cloneFile(
                'tests/Unit/Models/BrandTest.php',
                "tests/Unit/Models/{$singular}Test.php"
            );
        }
        if (File::exists(base_path('tests/Unit/Services/BrandServiceTest.php'))) {
            $this->cloneFile(
                'tests/Unit/Services/BrandServiceTest.php',
                "tests/Unit/Services/{$singular}ServiceTest.php"
            );
        }
    }

    protected function cloneConfigAndLang(): void
    {
        $this->info('Config + i18n...');
        $newLowerPl = $this->snakePlural();

        // config.
        if (File::exists(base_path('config/brands.php'))) {
            $this->cloneFile('config/brands.php', "config/{$newLowerPl}.php");
        }

        // lang es / en.
        foreach (['es', 'en'] as $locale) {
            $src = "resources/lang/{$locale}/brands.php";
            if (File::exists(base_path($src))) {
                $this->cloneFile($src, "resources/lang/{$locale}/{$newLowerPl}.php");
            }
        }
    }

    protected function cloneMigrationAndFactory(): void
    {
        $this->info('Database...');
        $newLowerPl = $this->snakePlural();
        $singular   = $this->module;

        // Migration con timestamp nuevo.
        $sourceMigration = $this->findBrandsMigration();
        if ($sourceMigration === null) {
            throw new \RuntimeException("No se encontró migration create_brands_table");
        }
        $timestamp = date('Y_m_d_His');
        $destMigration = "database/migrations/{$timestamp}_create_{$newLowerPl}_table.php";
        $this->cloneFile($sourceMigration, $destMigration);

        // Factory.
        if (File::exists(base_path('database/factories/BrandFactory.php'))) {
            $this->cloneFile(
                'database/factories/BrandFactory.php',
                "database/factories/{$singular}Factory.php"
            );
        }
    }

    protected function findBrandsMigration(): ?string
    {
        $dir = base_path('database/migrations');
        foreach (File::files($dir) as $file) {
            // El master es exactamente create_brands_table — evitar matchear
            // create_tap_changer_brands_table u otros que contienen "brands".
            // El timestamp lleva guiones bajos (2026_05_30_100020), por eso NO
            // sirve ^\d+; anclamos el sufijo exacto _create_brands_table.php.
            if (preg_match('/_create_brands_table\.php$/', $file->getFilename())) {
                return 'database/migrations/' . $file->getFilename();
            }
        }
        return null;
    }

    /**
     * Append a routes/{group}.php un bloque de rutas para el módulo nuevo.
     * Si el archivo no existe, lo crea con el scaffold base.
     */
    protected function appendRoutes(): void
    {
        $this->info('Routes...');
        $singular = $this->module;
        $plural   = $this->plural($singular);
        $group    = $this->group;
        $groupSnake = Str::snake($group);
        $newLowerPl = $this->snakePlural();
        $newLower   = Str::camel($singular);

        $routeFile = base_path("routes/{$groupSnake}.php");
        $newFile   = !File::exists($routeFile);

        $controllerFqcn = "App\\Http\\Controllers\\{$group}\\{$singular}Controller";

        if ($newFile) {
            // Crear archivo con scaffold base.
            $header = <<<PHP
<?php

use Illuminate\Support\Facades\Route;
use {$controllerFqcn};

/*
|--------------------------------------------------------------------------
| {$group}
|--------------------------------------------------------------------------
| Modulos generados con make:module. Cada modulo se gobierna por permisos
| Spatie: {$newLowerPl}.view, {$newLowerPl}.create, etc.
|
| ORDEN DE RUTAS CRITICO: las rutas con paths estaticos ({$newLowerPl}/create,
| {$newLowerPl}/trash, {$newLowerPl}/export_*) DEBEN ir ANTES que {$newLowerPl}/{{$newLower}}.
*/

Route::prefix('{$groupSnake}')->name('{$groupSnake}.')->group(function () {

PHP;
            file_put_contents($routeFile, $header);
            $this->createdFiles[] = $routeFile;
            $this->line("  CREADO: routes/{$groupSnake}.php");
        } else {
            $this->modifiedFiles[$routeFile] = file_get_contents($routeFile);
        }

        $block = $this->buildRoutesBlock();

        if ($newFile) {
            // Cerramos el group function.
            file_put_contents($routeFile, $block . "\n});\n", FILE_APPEND);
        } else {
            // Insertar antes del último `});` que cierra el `Route::prefix(...)` group.
            $existing = file_get_contents($routeFile);
            // Buscar el último `});` del archivo.
            $lastClose = strrpos($existing, '});');
            if ($lastClose === false) {
                // Archivo no tiene el patrón esperado — append al final.
                file_put_contents($routeFile, "\n" . $block . "\n", FILE_APPEND);
            } else {
                $before = substr($existing, 0, $lastClose);
                $after  = substr($existing, $lastClose);
                file_put_contents($routeFile, $before . "\n" . $block . "\n" . $after);
            }

            // Insertar use statement si no está.
            $current = file_get_contents($routeFile);
            if (!str_contains($current, "use {$controllerFqcn};")) {
                $current = preg_replace(
                    '/(use Illuminate\\\\Support\\\\Facades\\\\Route;)/',
                    "$1\nuse {$controllerFqcn};",
                    $current,
                    1
                );
                file_put_contents($routeFile, $current);
            }

            $this->line("  MODIFICADO: routes/{$groupSnake}.php (block appended)");
        }
    }

    protected function buildRoutesBlock(): string
    {
        $singular   = $this->module;
        $plural     = $this->plural($singular);
        $newLowerPl = $this->snakePlural();
        $newLower   = Str::camel($singular);
        $groupSnake = Str::snake($this->group);
        $ctrl       = "{$singular}Controller";

        return <<<PHP

    // ── {$plural} ──
    // Bloque generado por make:module. Reordena o ajusta permisos según tu dominio.

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('{$newLowerPl}/trash',                  [{$ctrl}::class, 'trash'])->name('{$newLowerPl}.trash');
        Route::post('{$newLowerPl}/bulk_restore',          [{$ctrl}::class, 'bulkRestore'])->name('{$newLowerPl}.bulk_restore');
        Route::post('{$newLowerPl}/{slug}/restore',        [{$ctrl}::class, 'restore'])->name('{$newLowerPl}.restore');
        Route::get('{$newLowerPl}/{slug}/restore',         fn () => redirect()->route('{$groupSnake}.{$newLowerPl}.trash'));
        Route::delete('{$newLowerPl}/{slug}/force_delete', [{$ctrl}::class, 'forceDelete'])->name('{$newLowerPl}.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:{$newLowerPl}.view')->group(function () {
        Route::middleware(['throttle:5,1', 'plan_feature:export_excel'])
            ->post('{$newLowerPl}/export_excel', [{$ctrl}::class, 'exportExcel'])->name('{$newLowerPl}.export_excel');
        Route::middleware(['throttle:5,1', 'plan_feature:export_pdf'])
            ->post('{$newLowerPl}/export_pdf',   [{$ctrl}::class, 'exportPdf'])->name('{$newLowerPl}.export_pdf');
        Route::middleware(['throttle:5,1', 'plan_feature:export_word'])
            ->post('{$newLowerPl}/export_word',  [{$ctrl}::class, 'exportWord'])->name('{$newLowerPl}.export_word');
        Route::middleware('throttle:5,1')
            ->post('{$newLowerPl}/export_csv',   [{$ctrl}::class, 'exportCsv'])->name('{$newLowerPl}.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:{$newLowerPl}.create', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('{$newLowerPl}/import',          [{$ctrl}::class, 'import'])->name('{$newLowerPl}.import');
        Route::get('{$newLowerPl}/import_template',  [{$ctrl}::class, 'importTemplate'])->name('{$newLowerPl}.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:{$newLowerPl}.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('{$newLowerPl}/bulk_delete',     [{$ctrl}::class, 'bulkDelete'])->name('{$newLowerPl}.bulk_delete');
        Route::post('{$newLowerPl}/bulk_set_active', [{$ctrl}::class, 'bulkSetActive'])->name('{$newLowerPl}.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:{$newLowerPl}.delete')->group(function () {
        Route::post('{$newLowerPl}/undo_last_delete', [{$ctrl}::class, 'undoLastDelete'])->name('{$newLowerPl}.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:{$newLowerPl}.edit')->group(function () {
        Route::get('{$newLowerPl}/edit_all',         [{$ctrl}::class, 'editAll'])->name('{$newLowerPl}.edit_all');
        Route::post('{$newLowerPl}/edit_all/update', [{$ctrl}::class, 'editAllUpdate'])->name('{$newLowerPl}.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:{$newLowerPl}.create')->group(function () {
        Route::get('{$newLowerPl}/create', [{$ctrl}::class, 'create'])->name('{$newLowerPl}.create');
        Route::post('{$newLowerPl}',       [{$ctrl}::class, 'store'])->name('{$newLowerPl}.store');
        Route::post('{$newLowerPl}/{{$newLower}}/duplicate', [{$ctrl}::class, 'duplicate'])->name('{$newLowerPl}.duplicate');
    });

    Route::middleware('permission:{$newLowerPl}.view')->group(function () {
        Route::get('{$newLowerPl}',                [{$ctrl}::class, 'index'])->name('{$newLowerPl}.index');
        Route::get('{$newLowerPl}/{{$newLower}}',  [{$ctrl}::class, 'show'])->name('{$newLowerPl}.show');
    });
    Route::middleware('permission:{$newLowerPl}.edit')->group(function () {
        Route::get('{$newLowerPl}/{{$newLower}}/edit', [{$ctrl}::class, 'edit'])->name('{$newLowerPl}.edit');
        Route::put('{$newLowerPl}/{{$newLower}}',      [{$ctrl}::class, 'update'])->name('{$newLowerPl}.update');
    });
    Route::middleware('permission:{$newLowerPl}.delete')->group(function () {
        Route::get('{$newLowerPl}/{{$newLower}}/delete',        [{$ctrl}::class, 'delete'])->name('{$newLowerPl}.delete');
        Route::delete('{$newLowerPl}/{{$newLower}}/deleteSave', [{$ctrl}::class, 'deleteSave'])->name('{$newLowerPl}.deleteSave');
    });

    // Bloquear/desbloquear (Lockable) — solo super|admin.
    Route::middleware('role:super|admin')->group(function () {
        Route::post('{$newLowerPl}/{{$newLower}}/lock',   [{$ctrl}::class, 'lock'])->name('{$newLowerPl}.lock');
        Route::post('{$newLowerPl}/{{$newLower}}/unlock', [{$ctrl}::class, 'unlock'])->name('{$newLowerPl}.unlock');
    });
PHP;
    }

    protected function registerInPolymorphicConfig(): void
    {
        $path = base_path('config/polymorphic.php');
        if (!File::exists($path)) return;

        $content = file_get_contents($path);
        $newLowerPl = $this->snakePlural();
        $singular   = $this->module;
        $groupSnake = Str::snake($this->group);

        // Si ya está registrado (uso real, no comentario), skip.
        if (preg_match("/'{$newLowerPl}'\\s*=>\\s*\\[\\s*\\n/m", $content)) {
            $this->line("  SKIP polymorphic ({$newLowerPl} ya registrado)");
            return;
        }

        $entry = "'{$newLowerPl}' => [\n" .
                 "            'model'      => \\App\\Models\\{$singular}::class,\n" .
                 "            'show_route' => '{$groupSnake}.{$newLowerPl}.show',\n" .
                 "        ],\n        ";

        $marker = "// Agrega modulos nuevos aqui";
        if (str_contains($content, $marker)) {
            $this->modifiedFiles[$path] = $content;
            $new = str_replace($marker, $entry . $marker, $content);
            file_put_contents($path, $new);
            $this->line("  MODIFICADO: config/polymorphic.php (entry agregada)");
        } else {
            $this->modifiedFiles[$path] = $content;
            $pattern = "/(\\s+\\],\\s*\\];\\s*$)/";
            $new = preg_replace($pattern, "\n" . $entry . "    ],\n];\n", $content, 1);
            file_put_contents($path, $new);
            $this->line("  MODIFICADO: config/polymorphic.php (entry agregada al final)");
        }
    }

    protected function registerInPurgeConfig(): void
    {
        $path = base_path('config/purge.php');
        if (!File::exists($path)) return;

        $content = file_get_contents($path);
        $newLowerPl = $this->snakePlural();
        $singular   = $this->module;

        if (preg_match("/'{$newLowerPl}'\\s*=>\\s*\\[\\s*\\n/m", $content)) {
            $this->line("  SKIP purge ({$newLowerPl} ya registrado)");
            return;
        }

        $entry = "'{$newLowerPl}' => [\n" .
                 "            'model' => \\App\\Models\\{$singular}::class,\n" .
                 "            'days'  => 90,\n" .
                 "        ],\n        ";

        $marker = "// Suma modulos nuevos aqui:";
        if (str_contains($content, $marker)) {
            $this->modifiedFiles[$path] = $content;
            $new = str_replace($marker, $entry . $marker, $content);
            file_put_contents($path, $new);
            $this->line("  MODIFICADO: config/purge.php (entry agregada)");
        } else {
            $this->modifiedFiles[$path] = $content;
            $pattern = "/(\\s+\\],\\s*\\];\\s*$)/";
            $new = preg_replace($pattern, "\n" . $entry . "    ],\n];\n", $content, 1);
            file_put_contents($path, $new);
            $this->line("  MODIFICADO: config/purge.php (entry agregada al final)");
        }
    }

    /**
     * Auto-registra el módulo en system_modules (si la tabla existe).
     * Es idempotente — si la fila para el módulo nuevo ya existe, skip.
     */
    protected function registerInSystemModulesTable(): void
    {
        if (!Schema::hasTable('system_modules')) {
            $this->warn('Tabla system_modules no existe — skip auto-registro.');
            return;
        }

        $newSingular = $this->module;

        // Idempotente: skip si ya existe una fila con ese name.
        $exists = DB::table('system_modules')->where('name', $newSingular)->exists();
        if ($exists) {
            $this->line("  SKIP system_modules ({$newSingular} ya registrado)");
            return;
        }

        $slug = Str::random(22);
        $newLowerPl = $this->snakePlural();

        // permission_key es UNIQUE y obligatorio. Usamos el patron estandar
        // `{plural}.view` que cualquier rol custom puede asignar via Spatie.
        $permissionKey = "{$newLowerPl}.view";

        // Si el permission_key ya existe (segunda corrida del scaffold con el
        // mismo modulo limpiado), skipear para no chocar contra la unique.
        if (DB::table('system_modules')->where('permission_key', $permissionKey)->exists()) {
            $this->line("  SKIP system_modules ({$permissionKey} ya existe)");
            return;
        }

        DB::table('system_modules')->insert([
            'slug'           => $slug,
            'name'           => $newSingular,
            'permission_key' => $permissionKey,
            'is_active'      => true,
            'created_by'     => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        $this->line("  CREADO en system_modules: {$newSingular} (permission_key={$permissionKey})");
    }

    /**
     * Post-procesado del módulo clonado de Brand.
     *
     * Brand es un master LIMPIO (name + code + is_active + sort_order +
     * tenant_id, sin cod/country/logo/jerarquía), así que NO hay que quitar
     * columnas de dominio. Aquí solo:
     *   - inyectamos las columnas de lock (Lockable) en la migración, que el
     *     master controller/model ya esperan pero la migración no trae;
     *   - generamos el blade del PDF (las vistas no se clonan);
     *   - opcionalmente aplicamos --no-tenant y --fields.
     *
     * Si un patrón no matchea exactamente, emite warning y continúa — no falla.
     */
    protected function applyFieldTransformations(): void
    {
        $this->info('Post-procesado (inyectar columnas de lock, PDF, opcionales)...');

        $singular   = $this->module;
        $plural     = $this->plural($singular);
        $newLower   = Str::camel($singular);
        $newLowerPl = $this->snakePlural();
        $group      = $this->group;

        // Migration generada: inyectar columnas de lock (Lockable). El modelo
        // clonado usa el trait Lockable y el controller usa lock()/unlock(),
        // pero la migración de Brand NO trae las columnas de lock → sin esto el
        // bloqueo revienta al primer uso.
        $migrationPath = $this->findGeneratedMigration($newLowerPl);
        if ($migrationPath !== null) {
            $this->injectLockColumns($migrationPath);
            $this->patchMigration($migrationPath, $newLowerPl);
        } else {
            $this->warn("  No se encontró migration generada para {$newLowerPl} — skip patch migration");
        }

        // Vista PDF: el master Brands la tiene en resources/views, pero el
        // clonado NO copia blades → el job de PDF apuntaba a una vista inexistente
        // (export PDF reventaba). Generamos una plantilla genérica name-only.
        $this->generatePdfTemplate($group, $newLowerPl);

        // ── Catalogo global: quitar tenant (--no-tenant) ────────────────
        if ($this->noTenant) {
            $this->applyNoTenant($singular, $plural, $newLowerPl, $group);
        }

        // ── Inyectar campos del dominio (--fields) ──────────────────────
        if (!empty($this->fields)) {
            $this->injectDomainFields($singular, $plural, $newLowerPl, $newLower, $group);
        }
    }

    /**
     * Inyecta las columnas de lock (Lockable) dentro del Schema::create(...) de
     * la migración generada. El modelo clonado usa el trait Lockable y el
     * controller usa lock()/unlock(), pero la migración base de Brand NO trae
     * estas columnas. Sin esto el bloqueo de registros revienta.
     *
     * Punto de inserción robusto: antes de `$table->timestamps();` si existe;
     * si no, antes del `$table->softDeletes();`; si tampoco, antes del cierre
     * `});` del closure de Schema::create. Idempotente: si ya hay una columna
     * locked_at, no inserta de nuevo.
     */
    protected function injectLockColumns(string $file): void
    {
        if (!File::exists($file)) return;

        $content = file_get_contents($file);

        // Idempotencia: si ya tiene la columna lock, no hacer nada.
        if (str_contains($content, "'locked_at'") || str_contains($content, '->index();' . "\n            \$table->timestamp('locked_at')")) {
            return;
        }

        // Bloque de columnas de lock. La PRIMERA línea va SIN indentación porque
        // se inserta justo donde el str_replace ya tiene las 12 espacios del
        // anchor (`$table->timestamps();`/`$table->softDeletes();`).
        $lockBlock =
            "// Lockable: bloqueo de registros (lock()/unlock()).\n" .
            "            \$table->timestamp('locked_at')->nullable()->index();\n" .
            "            \$table->unsignedBigInteger('locked_by')->nullable();\n" .
            "            \$table->string('lock_scope', 10)->nullable();\n" .
            "\n            ";

        $original = $content;

        // 1) Preferido: antes de la línea `$table->timestamps();`.
        if (str_contains($content, "\$table->timestamps();")) {
            $content = str_replace(
                "\$table->timestamps();",
                $lockBlock . "\$table->timestamps();",
                $content
            );
        } elseif (str_contains($content, "\$table->softDeletes();")) {
            // 2) Antes de softDeletes().
            $content = str_replace(
                "\$table->softDeletes();",
                $lockBlock . "\$table->softDeletes();",
                $content
            );
        } else {
            // 3) Último recurso: antes del cierre del closure de create.
            // Aquí sí necesitamos indentación completa en la primera línea.
            $content = preg_replace(
                "/(\n        \}\);)/",
                "\n            " . $lockBlock . "$1",
                $content,
                1
            );
        }

        if ($content !== null && $content !== $original) {
            file_put_contents($file, $content);
            $this->line("  POST: columnas de lock (Lockable) inyectadas en la migración " . $this->relPath($file));
        } else {
            $this->warn("  No se pudieron inyectar las columnas de lock en " . $this->relPath($file));
        }
    }

    /**
     * --no-tenant: convierte el modulo en catalogo global. Quita el trait de
     * tenant del modelo (Brand usa BelongsToTenantOrGlobal), la columna
     * tenant_id de la migracion, y los indices/where que dependen de tenant. El
     * scope de tenant es lo que filtra por workspace; sin el, todos los tenants
     * ven el mismo catalogo.
     */
    protected function applyNoTenant(string $singular, string $plural, string $newLowerPl, string $group): void
    {
        // Modelo: quitar trait, import, y la columna tenant_id del fillable.
        // Brand usa BelongsToTenantOrGlobal; cubrimos ambos nombres por si el
        // master cambiara (BelongsToTenant / BelongsToTenantOrGlobal). El más
        // largo PRIMERO para no dejar el sufijo "OrGlobal" colgando.
        $model = base_path("app/Models/{$singular}.php");
        if (File::exists($model)) {
            $c = file_get_contents($model);
            foreach (['BelongsToTenantOrGlobal', 'BelongsToTenant'] as $trait) {
                // Quitar de la lista `use Trait1, Trait2, ...;` (cubrir comas a
                // ambos lados: inicio, medio o fin de la lista).
                $c = str_replace(", {$trait},", ',', $c);
                $c = str_replace(", {$trait}", '', $c);
                $c = str_replace("{$trait}, ", '', $c);
                // Quitar el import `use App\Traits\Trait;`.
                $c = preg_replace("/^use App\\\\Traits\\\\{$trait};\s*\n/m", '', $c);
            }
            // tenant_id en fillable: cubrir ", 'tenant_id'" y "'tenant_id', ".
            $c = str_replace(", 'tenant_id',", ",", $c);
            $c = str_replace("'tenant_id', ", '', $c);
            $c = str_replace("'tenant_id',", '', $c);
            // Comentario del docblock que menciona el trait de tenant.
            $c = str_replace('SoftDeletes + Auditable + BelongsToTenantOrGlobal + HasFavorites',
                             'catalogo global: SoftDeletes + Auditable + HasFavorites (sin tenant)', $c);
            $c = str_replace('per-tenant: SoftDeletes + Auditable + BelongsToTenant + HasFavorites',
                             'catalogo global: SoftDeletes + Auditable + HasFavorites (sin tenant)', $c);
            file_put_contents($model, $c);
            $this->line("  POST: --no-tenant aplicado al modelo {$singular}");
        }

        // Migracion: quitar tenant_id column + FK + indices que lo usan.
        $mig = $this->findGeneratedMigration($newLowerPl);
        if ($mig !== null) {
            $this->stripTenantFromMigration($mig, $newLowerPl);
        }
    }

    /**
     * Quita tenant_id de la migracion generada SIN romper indices parciales.
     * Dos casos:
     *   1. Lineas que SOLO tratan de tenant (columna foreignId, indices que
     *      empiezan por tenant_id) → se borran enteras.
     *   2. Indices/uniques compuestos que incluyen tenant_id junto a otras
     *      columnas → se reescribe quitando solo 'tenant_id' (preservando el
     *      resto). Idem el `(tenant_id, name)` del CREATE UNIQUE INDEX pgsql.
     */
    protected function stripTenantFromMigration(string $file, string $newLowerPl): void
    {
        $c = file_get_contents($file);

        // 1) Reescribir indices compuestos que MEZCLAN tenant_id con otras cols
        //    (preservar las demas). Ej: ['tenant_id', 'is_active', 'created_at']
        $c = preg_replace("/\['tenant_id',\s*/", "[", $c);          // al inicio del array
        $c = preg_replace("/,\s*'tenant_id'\]/", "]", $c);          // al final
        $c = preg_replace("/,\s*'tenant_id',/", ",", $c);           // en medio
        // pgsql CREATE INDEX ... (tenant_id, name) → (name)
        $c = str_replace('(tenant_id, ', '(', $c);
        $c = str_replace(', tenant_id)', ')', $c);

        // 2) Borrar lineas que quedan SOLO de tenant (columna + FK + indice solo-tenant).
        $lines = preg_split('/\r\n|\n/', $c);
        $out = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if (preg_match("/foreignId\('tenant_id'\)/", $line)) continue;          // columna FK
            if (str_contains($line, "->constrained('tenants')")) continue;          // continuacion del constrained
            if (preg_match("/index\(\['tenant_id'\]/", $line)) continue;            // indice solo tenant
            if (preg_match("/unique\(\['tenant_id'/", $line)) continue;             // unique solo/inicia tenant (cod ya removido)
            // comentarios huerfanos sobre tenant
            if ($t !== '' && str_starts_with($t, '//') && stripos($t, 'tenant') !== false) continue;
            $out[] = $line;
        }
        $c = implode("\n", $out);

        // 3) Si un CREATE UNIQUE INDEX quedo sin columnas (caso name unique que
        //    dependia de tenant), reescribir a indice unico solo por name.
        $c = preg_replace(
            "/\"CREATE UNIQUE INDEX ({$newLowerPl}_tenant_name_unique_active) \" \.\s*\n\s*\"WHERE deleted_at IS NULL\"/",
            "\"CREATE UNIQUE INDEX \$1 ON {$newLowerPl} (name) \" .\n                \"WHERE deleted_at IS NULL\"",
            $c
        );

        file_put_contents($file, $c);
        $this->line("  POST: --no-tenant quito tenant_id de la migracion (indices preservados)");
    }

    /**
     * Inyecta los campos de --fields en migracion, modelo (fillable+casts),
     * FormRequests, factory, lang, Form.vue, Show.vue y columns.js.
     * Usa anclas de texto fijas que el modulo tiene tras el post-procesado
     * (mas robusto que regex amplios).
     */
    protected function injectDomainFields(string $singular, string $plural, string $newLowerPl, string $newLower, string $group): void
    {
        $this->info('Inyectando campos del dominio (--fields)...');

        // 1) Migracion: tras la linea de name.
        $mig = $this->findGeneratedMigration($newLowerPl);
        if ($mig !== null && ($lines = $this->fieldsMigrationLines()) !== '') {
            $c = file_get_contents($mig);
            $c = str_replace(
                "\$table->string('name')->index();",
                "\$table->string('name')->index();\n{$lines}",
                $c
            );
            file_put_contents($mig, $c);
        }

        // 2) Modelo: fillable + casts.
        $model = base_path("app/Models/{$singular}.php");
        if (File::exists($model)) {
            $c = file_get_contents($model);
            // fillable: agregar tras 'name',
            if (($fill = $this->fieldsFillable()) !== '') {
                $c = preg_replace("/('slug',\s*'name',)/", "\$1 {$fill},", $c, 1);
            }
            // casts: agregar tras 'is_active' => 'boolean'. Brand ALINEA los casts
            // ('is_active'  => 'boolean', con 2 espacios), por eso usamos regex
            // tolerante a espacios en vez de str_replace de 1 espacio.
            if (($casts = $this->fieldsCastLines()) !== '') {
                $c = preg_replace(
                    "/('is_active'\s*=>\s*'boolean',)/",
                    "\$1\n{$casts}",
                    $c, 1
                );
            }
            file_put_contents($model, $c);
        }

        // 3) FormRequests Store/Update: tras la rule de name.
        foreach (["Store{$singular}Request.php", "Update{$singular}Request.php"] as $rf) {
            $path = base_path("app/Http/Requests/{$group}/{$singular}/{$rf}");
            if (File::exists($path) && ($rules = $this->fieldsRuleLines()) !== '') {
                $c = file_get_contents($path);
                // Ancla: la linea 'is_active' => [...] o 'is_active' => 'sometimes...'
                $c = preg_replace(
                    "/(\n\s*'is_active'\s*=>[^\n]*\n)/",
                    "\$1{$rules}\n",
                    $c, 1
                );
                file_put_contents($path, $c);
            }
        }

        // 4) Factory: tras 'is_active' => true,
        $factory = base_path("database/factories/{$singular}Factory.php");
        if (File::exists($factory) && ($fl = $this->fieldsFactoryLines()) !== '') {
            $c = file_get_contents($factory);
            $c = str_replace(
                "'is_active' => true,",
                "'is_active' => true,\n{$fl}",
                $c
            );
            file_put_contents($factory, $c);
        }

        // 5) Lang es/en: tras 'name' => '...',
        foreach (['es', 'en'] as $loc) {
            $lf = base_path("resources/lang/{$loc}/{$newLowerPl}.php");
            if (File::exists($lf) && ($ll = $this->fieldsLangLines()) !== '') {
                $c = file_get_contents($lf);
                $c = preg_replace("/(\n\s*'name'\s*=>\s*'[^']*',\n)/", "\$1{$ll}\n", $c, 1);
                file_put_contents($lf, $c);
            }
        }

        // 6) Frontend: Form.vue, Show.vue, columns.js.
        $this->injectFieldsFrontend($singular, $plural, $newLowerPl, $newLower);

        $this->line("  POST: " . count($this->fields) . " campo(s) inyectado(s): " .
            implode(', ', array_map(fn ($f) => $f['name'] . ':' . $f['type'] . ($f['nullable'] ? '?' : ''), $this->fields)));
    }

    /**
     * Inyecta los campos en el frontend: Form.vue (useForm + FormItems),
     * Show.vue (DescriptionsItem) y columns.js (columnas de la tabla).
     * Cada tipo usa el control adecuado (Input/InputNumber/Switch/Textarea).
     */
    protected function injectFieldsFrontend(string $singular, string $plural, string $newLowerPl, string $newLower): void
    {
        $map = $this->fieldTypeMap();

        // ── Form.vue ────────────────────────────────────────────────────
        $form = base_path("resources/js/Pages/{$plural}/Form.vue");
        if (File::exists($form)) {
            $c = file_get_contents($form);

            // a) useForm: agregar defaults tras la linea de name.
            $defaults = [];
            foreach ($this->fields as $f) {
                $def = match ($map[$f['type']]['input']) {
                    'switch' => 'false',
                    'number' => 'null',
                    default  => "''",
                };
                $defaults[] = "    {$f['name']}: props.{$newLower}?.{$f['name']} ?? {$def},";
            }
            if ($defaults) {
                $block = implode("\n", $defaults);
                $c = preg_replace(
                    "/(name:\s*props\.{$newLower}\?\.name \?\? '',\n)/",
                    "\$1{$block}\n",
                    $c, 1
                );
            }

            // b) FormItems: insertar antes del <Col v-if="isEdit"> (is_active).
            $items = [];
            foreach ($this->fields as $f) {
                $items[] = $this->vueFormItem($f, $newLowerPl, $map[$f['type']]['input']);
            }
            if ($items) {
                $block = implode("\n", $items);
                // Ancla: el Col del is_active (que el master tiene con v-if="isEdit").
                $c = preg_replace(
                    '/(\n\s*<Col v-if="isEdit"[^>]*>)/',
                    "\n{$block}\$1",
                    $c, 1
                );
                // Fallback: si no hay Col v-if isEdit, insertar antes de FormFooter.
                if (!str_contains($c, $items[0])) {
                    $c = str_replace('<FormFooter', "{$block}\n\n                <FormFooter", $c);
                }
            }

            // c) Asegurar imports de ant-design-vue segun los controles usados.
            $needed = [];
            foreach ($this->fields as $f) {
                $needed[] = match ($map[$f['type']]['input']) {
                    'number'   => 'InputNumber',
                    'textarea' => 'Textarea',
                    'date'     => 'DatePicker',
                    'switch'   => 'Switch',
                    default    => 'Input',
                };
            }
            $needed = array_unique($needed);
            // Parsear la lista actual del import de ant-design-vue y mergear.
            if (preg_match('/import \{\s*([\s\S]*?)\s*\} from \'ant-design-vue\';/', $c, $m)) {
                $current = array_map('trim', explode(',', $m[1]));
                $current = array_filter($current, fn ($x) => $x !== '');
                $merged  = array_values(array_unique(array_merge($current, $needed)));
                $newImport = "import {\n    " . implode(', ', $merged) . ",\n} from 'ant-design-vue';";
                $c = preg_replace('/import \{[\s\S]*?\} from \'ant-design-vue\';/', $newImport, $c, 1);
            }

            file_put_contents($form, $c);
        }

        // ── Show.vue: celda spec-cell tras la de name ───────────────────
        // Brand usa el patrón spec-grid/spec-cell (NO Ant Descriptions), así que
        // generamos una celda por campo y la anclamos a la celda de name.
        $show = base_path("resources/js/Pages/{$plural}/Show.vue");
        if (File::exists($show)) {
            $c = file_get_contents($show);
            $items = [];
            foreach ($this->fields as $f) {
                $items[] = "                        <div class=\"spec-cell\">\n"
                    . "                            <span class=\"spec-cell__label\">{{ \$t('{$newLowerPl}.{$f['name']}') }}</span>\n"
                    . "                            <span class=\"spec-cell__value\">{{ {$newLower}.{$f['name']} }}</span>\n"
                    . "                        </div>";
            }
            if ($items) {
                $block = implode("\n", $items);
                $c = preg_replace(
                    "/(<span class=\"spec-cell__value\">\{\{ {$newLower}\.name \}\}<\/span>\n\s*<\/div>\n)/",
                    "\$1{$block}\n",
                    $c, 1
                );
                file_put_contents($show, $c);
            }
        }

        // ── columns.js: columnas tras la de name ────────────────────────
        $cols = base_path("resources/js/Pages/{$plural}/config/columns.js");
        if (File::exists($cols)) {
            $c = file_get_contents($cols);
            $lines = [];
            foreach ($this->fields as $f) {
                $lines[] = "    { title: t('{$newLowerPl}.{$f['name']}'), dataIndex: '{$f['name']}', key: '{$f['name']}', mobile: { role: 'meta' } },";
            }
            if ($lines) {
                $block = implode("\n", $lines);
                // Ancla: la columna de name (key: 'name').
                $c = preg_replace(
                    "/(\{ title: t\('{$newLowerPl}\.name'\),[^\n]*key: 'name',[^\n]*\},\n)/",
                    "\$1{$block}\n",
                    $c, 1
                );
                file_put_contents($cols, $c);
            }
        }
    }

    /** Genera un <Col><FormItem> Vue para un campo segun su tipo de input. */
    protected function vueFormItem(array $f, string $newLowerPl, string $input): string
    {
        $label = "\$t('{$newLowerPl}.{$f['name']}')";
        $vmodel = "form.{$f['name']}";
        $err = "form.errors.{$f['name']}";
        $required = $f['nullable'] ? '' : "\n                            required";

        $control = match ($input) {
            'number'   => "<InputNumber v-model:value=\"{$vmodel}\" size=\"large\" style=\"width: 100%\" />",
            'switch'   => "<Switch v-model:checked=\"{$vmodel}\" />",
            'textarea' => "<Textarea v-model:value=\"{$vmodel}\" :rows=\"3\" />",
            'date'     => "<DatePicker v-model:value=\"{$vmodel}\" style=\"width: 100%\" value-format=\"YYYY-MM-DD\" />",
            default    => "<Input v-model:value=\"{$vmodel}\" size=\"large\" :maxlength=\"255\" />",
        };

        return <<<VUE
                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="{$label}"{$required}
                            :validate-status="{$err} ? 'error' : ''"
                            :help="{$err}"
                        >
                            {$control}
                        </FormItem>
                    </Col>
VUE;
    }

    /**
     * Limpieza ROBUSTA line-based de cod/country en archivos PHP/JS/Vue.
     *
     * En vez de regex multilinea (fragiles con llaves anidadas como
     * `mobile: { role: 'meta' }`), recorremos linea por linea y descartamos
     * cualquier linea que referencie la columna `cod` o la relacion `country`
     * del template Customer. Para bloques multilinea (metodo countryOptions(),
     * payload country => [...], <template>/<DescriptionsItem>/<div v-if>),
     * detectamos la apertura y saltamos hasta el cierre balanceado.
     *
     * Esto cubre controller, service, jobs, configs JS y .vue de una sola vez.
     */
    protected function stripCodCountryLines(string $file): void
    {
        if (!File::exists($file)) return;

        $lines = preg_split('/\r\n|\n/', file_get_contents($file));
        $out   = [];
        $closeTag    = null;   // tag de cierre HTML esperado (</template> etc.)
        $braceDepth  = 0;      // balance para bloques PHP/JS { } o [ ]
        $inBrace     = false;  // estamos dentro de un bloque balanceado
        $braceOpen   = '{';    // caracter de apertura del bloque actual
        $braceClose  = '}';    // caracter de cierre del bloque actual
        $bracePending = false; // la apertura aun no aparecio (function \n {)
        $removed = 0;

        // Patrones de UNA linea: si matchea, se descarta esa linea sola.
        $singleLine = [
            "/'cod'\s*=>/",                       // payload/audit/factory/array 'cod' =>
            "/'country'\s*=>/",                    // csv heading/value 'country' =>
            "/'country_id'\s*=>/",                 // 'country_id' =>
            "/->cod\b/",                           // \$x->cod
            "/->country(?!Options)\b/",            // \$x->country / ->country?->name (no countryOptions)
            "/\bcod:\s/",                          // cod: '' (JS filters)
            "/\bcountry_id:\s/",                   // country_id: [] (JS filters)
            "/\bcountryOptions:\s/",               // countryOptions: { type: Array } (defineProps)
            "/\.cod\b/",                           // record.cod (JS/columns)
            "/->loadMissing\('country/",           // loadMissing('country...
            "/'countryOptions'\s*=>/",             // prop countryOptions
            "/if \(f\.cod\)/",                     // JS summary cod
            "/if \(f\.country_id/",                // JS summary country_id
            "/key: 'cod'/",                        // JS config column/field cod
            "/key: 'country'/",                    // JS config column/field country
            "/key: 'country_id'/",                 // JS filter field country_id
            "/t\('[^']*\.cod'\)/",                 // columns/trash title t('x.cod')
            "/t\('[^']*\.country'\)/",             // columns/trash title t('x.country')
        ];

        // Bloques por TAG HTML: [regex_apertura, tag_cierre].
        $blockTag = [
            ["/<template v-else-if=\"column\.key === 'cod'\">/", '</template>'],
            ["/<template v-else-if=\"column\.key === 'country'\">/", '</template>'],
            ["/<DescriptionsItem v-if=\"\w+\.cod\"/", '</DescriptionsItem>'],
            ["/<DescriptionsItem v-if=\"\w+\.country\"/", '</DescriptionsItem>'],
            ["/<div v-if=\"\w+\.cod\"/", '</div>'],
            ["/<div v-if=\"\w+\.country\"/", '</div>'],
            ["/<th class=\"col-cod\">/", '</th>'],
            ["/<td class=\"col-cod\">/", '</td>'],
        ];

        // Bloques por LLAVES balanceadas (PHP/JS): regex de apertura.
        // Contamos { y } desde la apertura hasta volver a balance 0.
        $blockBrace = [
            "/protected function countryOptions\(\): array/",
            "/'country'\s*=>\s*\\\$\w+->country\s*\?\s*\[/",   // payload country => [ ... ] : null,
            "/if \(!empty\(\\\$f\['cod'\]\)\)/",
            "/if \(!empty\(\\\$f\['country_id'\]\)\)/",
            "/if \(in_array\('country', \\\$columns\)\)/",
        ];

        foreach ($lines as $line) {
            // Dentro de bloque por-tag: saltar hasta el cierre.
            if ($closeTag !== null) {
                $removed++;
                if (str_contains($line, $closeTag)) $closeTag = null;
                continue;
            }
            // Dentro de bloque balanceado: contar hasta volver a 0.
            if ($inBrace) {
                $removed++;
                $delta = substr_count($line, $braceOpen) - substr_count($line, $braceClose);
                if ($bracePending) {
                    // Esperando la primera apertura. Cuando aparezca, empezamos a contar.
                    if (substr_count($line, $braceOpen) > 0) {
                        $bracePending = false;
                        $braceDepth = $delta;
                        if ($braceDepth <= 0) $inBrace = false;
                    }
                    continue;
                }
                $braceDepth += $delta;
                if ($braceDepth <= 0) $inBrace = false;
                continue;
            }

            // Apertura bloque por-tag.
            $opened = false;
            foreach ($blockTag as [$re, $tag]) {
                if (preg_match($re, $line)) {
                    $removed++;
                    if (!str_contains($line, $tag)) $closeTag = $tag;   // multi-linea
                    $opened = true; break;
                }
            }
            if ($opened) continue;

            // Apertura bloque por-llaves/corchetes.
            foreach ($blockBrace as $idx => $re) {
                if (preg_match($re, $line)) {
                    $removed++;
                    // payload country => [...] : null,  (corchetes, puede ser 1 linea)
                    $isBracketBlock = ($idx === 1);
                    $open  = $isBracketBlock ? '[' : '{';
                    $close = $isBracketBlock ? ']' : '}';
                    $depth = substr_count($line, $open) - substr_count($line, $close);
                    // Si el bloque abre llaves en la linea SIGUIENTE (ej. function
                    // foo(): array \n {), depth=0 aqui pero igual es multi-linea.
                    // Forzamos modo bloque salvo que la linea ya cierre sola.
                    $closesOnSameLine = $isBracketBlock
                        ? str_contains($line, '] : null,')
                        : ($depth <= 0 && (substr_count($line, $close) > 0));
                    if (!$closesOnSameLine) {
                        $inBrace = true;
                        $braceDepth = max($depth, 0);
                        $braceOpen = $open;
                        $braceClose = $close;
                        // Si aun no vimos la llave de apertura, marcamos pendiente.
                        $bracePending = ($depth === 0);
                    }
                    $opened = true; break;
                }
            }
            if ($opened) continue;

            // Patrones de una linea.
            $isSingle = false;
            foreach ($singleLine as $re) {
                if (preg_match($re, $line)) { $isSingle = true; break; }
            }
            if ($isSingle) { $removed++; continue; }

            $out[] = $line;
        }

        if ($removed > 0) {
            file_put_contents($file, implode("\n", $out));
            $this->line("  POST: stripCodCountry removed {$removed} line(s) in " . $this->relPath($file));
        }
    }

    /**
     * Reemplazos in-line de cod/country que NO son lineas completas a borrar,
     * sino fragmentos dentro de una linea que hay que limpiar conservando el
     * resto (eager-loads en arrays, selects, allowlists, only(), etc.).
     */
    protected function stripCodCountryInline(string $file, string $newLowerPl): void
    {
        if (!File::exists($file)) return;

        $content = file_get_contents($file);
        $orig = $content;

        $content = str_replace(", 'country:id,name,iso_code'", '', $content);
        $content = str_replace("'country:id,name,iso_code', ", '', $content);
        $content = str_replace("'{$newLowerPl}.cod', ", '', $content);
        $content = str_replace(", '{$newLowerPl}.cod'", '', $content);
        $content = str_replace("'cod', 'country', ", '', $content);
        $content = str_replace("'id', 'name', 'cod', 'country', 'is_active'", "'id', 'name', 'is_active'", $content);
        $content = str_replace("['creator', 'country']", "['creator']", $content);
        $content = str_replace("only(['country_id', 'is_active'])", "only(['is_active'])", $content);
        // JS: firma de filterFields con countryOptions.
        $content = str_replace(", { countryOptions = [] } = {}", '', $content);
        $content = preg_replace("/\(t, \{ countryOptions: [^)]*\)/", '(t)', $content);

        if ($content !== $orig) {
            file_put_contents($file, $content);
            $this->line("  POST: stripCodCountry inline-cleaned " . $this->relPath($file));
        }
    }

    protected function findGeneratedMigration(string $newLowerPl): ?string
    {
        $dir = base_path('database/migrations');
        $matches = [];
        foreach (File::files($dir) as $file) {
            if (str_contains($file->getFilename(), "create_{$newLowerPl}_table")) {
                $matches[] = $file->getRealPath();
            }
        }
        if (empty($matches)) return null;
        rsort($matches);
        return $matches[0];
    }

    /**
     * Helper: aplica un set de transformaciones a un archivo y reporta.
     * $transforms: array de [pattern, replacement, description, isRegex=bool].
     */
    protected function applyPatches(string $file, array $transforms): void
    {
        if (!File::exists($file)) {
            $this->warn("  Could not patch — archivo no existe: " . $this->relPath($file));
            return;
        }
        $rel = $this->relPath($file);
        $content = file_get_contents($file);
        $original = $content;
        $applied = [];
        $missed  = [];

        foreach ($transforms as $t) {
            [$search, $replace, $desc] = [$t[0], $t[1], $t[2]];
            $isRegex = $t[3] ?? false;
            $before = $content;
            if ($isRegex) {
                $result = preg_replace($search, $replace, $content);
                if ($result === null) {
                    $this->warn("  Could not patch (regex error) {$desc} in {$rel}");
                    $missed[] = $desc;
                    continue;
                }
                $content = $result;
            } else {
                $content = str_replace($search, $replace, $content);
            }
            if ($content !== $before) {
                $applied[] = $desc;
            } else {
                $missed[] = $desc;
            }
        }

        if ($content !== $original) {
            file_put_contents($file, $content);
            foreach ($applied as $d) {
                $this->line("  POST: {$d} in {$rel}");
            }
        }
        foreach ($missed as $d) {
            $this->warn("  Could not patch '{$d}' in {$rel}");
        }
    }

    protected function relPath(string $abs): string
    {
        return str_replace(base_path() . DIRECTORY_SEPARATOR, '', $abs);
    }

    protected function patchMigration(string $file, string $newLowerPl): void
    {
        // Brand es un master LIMPIO (name + code + is_active + sort_order):
        // NO hay columnas cod/country que quitar. Este método ahora solo
        // aplica el guard hasTable() para tablas preexistentes. Las columnas
        // del dominio (price/stock/sku/FKs/etc.) se agregan a mano post-scaffold.

        // ── BUG FIX #3: tabla preexistente ──────────────────────────────
        // Si la tabla ya existe en la BD (ej. la creó otro módulo/motor antes),
        // un Schema::create plano revienta al migrar. Envolvemos el create con
        // un guard hasTable() para que la migración sea segura de correr.
        // El dev igualmente debería revisar si necesita las columnas del create
        // o solo un ALTER, pero al menos no rompe la suite de migraciones.
        if (Schema::hasTable($newLowerPl)) {
            $content  = file_get_contents($file);
            $original = $content;

            // up(): envolver Schema::create('tabla', function (...) {...}); con guard.
            $content = preg_replace(
                "/(Schema::create\('{$newLowerPl}',\s*function \(Blueprint \\\$table\) \{[\s\S]*?\n        \}\);)/",
                "if (!Schema::hasTable('{$newLowerPl}')) {\n        \$1\n        }",
                $content,
                1
            );

            if ($content !== null && $content !== $original) {
                file_put_contents($file, $content);
                $this->warn("  POST: tabla '{$newLowerPl}' YA EXISTE — Schema::create envuelto en guard hasTable(). Revisa si necesitas un ALTER en su lugar.");
            }
        }
    }

    protected function patchModel(string $file): void
    {
        $transforms = [
            // Reemplazar fillable. El Customer model parte el fillable en
            // múltiples líneas: usar regex que tolere espacios/saltos entre
            // 'name' y 'cod', 'country_id'. Conserva las keys de auditoría
            // que viven en la segunda línea (created_by, deleted_by, etc.).
            // Módulo nuevo queda con SOLO `name` como campo de dominio.
            [
                "/'slug',\s*'name',\s*'cod',\s*'country_id',\s*'is_active',\s*'tenant_id',/",
                "'slug', 'name', 'is_active', 'tenant_id',",
                'fillable: removed cod/country_id',
                true,
            ],
            // Remove country() relation method.
            [
                '/\s*public function country\(\): BelongsTo\s*\{\s*return \$this->belongsTo\(Country::class\);\s*\}\s*\n/',
                "\n",
                'removed country() relation method',
                true,
            ],
            // Remove Country use import.
            [
                "/^use App\\\\Models\\\\Country;\s*\n/m",
                '',
                'removed Country import',
                true,
            ],
            // Quitar scope filter de cod.
            [
                '/\s*\$query->when\(\$request->filled\(\'cod\'\), function \(\$q\) use \(\$request, \$tbl\) \{\s*\n\s*\$q->where\("\{\$tbl\}\.cod", \'like\', \'%\' \. \$request->cod \. \'%\'\);\s*\n\s*\}\);\s*\n/',
                "\n",
                'removed scope filter for cod',
                true,
            ],
            // Quitar scope filter de country_id.
            [
                '/\s*\$query->when\(\$request->filled\(\'country_id\'\), function \(\$q\) use \(\$request, \$tbl\) \{\s*\n\s*\$ids = is_array\(\$request->country_id\) \? \$request->country_id : \[\$request->country_id\];\s*\n\s*\$ids = array_filter\(\$ids\);\s*\n\s*if \(!empty\(\$ids\)\) \$q->whereIn\("\{\$tbl\}\.country_id", \$ids\);\s*\n\s*\}\);\s*\n/',
                "\n",
                'removed scope filter for country_id',
                true,
            ],
            // sort whitelist: quitar 'cod'.
            [
                "['id', 'name', 'cod', 'is_active', 'created_at', 'updated_at']",
                "['id', 'name', 'is_active', 'created_at', 'updated_at']",
                'removed cod from sort whitelist',
            ],
            // filterSchema(): quitar la línea de 'cod'.
            [
                "/\s*\['key'\s*=>\s*'cod',[^\]]*'operators'\s*=>\s*\[[^\]]+\]\],\s*\n/",
                "\n",
                'removed cod from filterSchema',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);
    }

    protected function patchFormVue(string $file, string $newLower): void
    {
        if (!File::exists($file)) {
            $this->warn("  Could not patch — Form.vue no existe: " . $this->relPath($file));
            return;
        }

        $transforms = [
            // useForm: quitar cod y country_id.
            [
                "/\s*cod:\s*props\.{$newLower}\?\.cod \?\?\s*'',\s*\n/",
                "\n",
                'useForm: removed cod',
                true,
            ],
            [
                "/\s*country_id:\s*props\.{$newLower}\?\.country_id \?\?\s*null,\s*\n/",
                "\n",
                'useForm: removed country_id',
                true,
            ],
            // Quitar countryOptions de defineProps.
            [
                "/,\s*\n\s*countryOptions:\s*\{\s*type:\s*Array,\s*default:\s*\(\)\s*=>\s*\[\]\s*\},/",
                ',',
                'defineProps: removed countryOptions',
                true,
            ],
            // Quitar FormItem de cod (Col + FormItem).
            [
                '/\s*<Col :xs="24" :md="8">\s*\n\s*<FormItem\s*\n\s*:label="\$t\(\'[^\']+\.cod\'\)"[\s\S]*?<\/FormItem>\s*\n\s*<\/Col>\s*\n/',
                "\n",
                'removed cod FormItem',
                true,
            ],
            // Quitar FormItem de country.
            [
                '/\s*<Col :xs="24" :md="16">\s*\n\s*<FormItem\s*\n\s*:label="\$t\(\'[^\']+\.country\'\)"[\s\S]*?<\/FormItem>\s*\n\s*<\/Col>\s*\n/',
                "\n",
                'removed country FormItem',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);

        // Form.vue queda con solo el FormItem de `name`. El dev suma los
        // FormItems del dominio post-scaffold (ver checklist printChecklist).
    }

    protected function patchShowVue(string $file, string $newLower, string $newLowerPl): void
    {
        if (!File::exists($file)) {
            $this->warn("  Could not patch — Show.vue no existe: " . $this->relPath($file));
            return;
        }

        $transforms = [
            // Quitar DescriptionsItem cod.
            [
                '/\s*<DescriptionsItem :label="\$t\(\'[^\']+\.cod\'\)">[\s\S]*?<\/DescriptionsItem>\s*\n/',
                "\n",
                'removed cod DescriptionsItem',
                true,
            ],
            // Quitar DescriptionsItem country.
            [
                '/\s*<DescriptionsItem :label="\$t\(\'[^\']+\.country\'\)">[\s\S]*?<\/DescriptionsItem>\s*\n/',
                "\n",
                'removed country DescriptionsItem',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);

        // Show.vue queda con solo el DescriptionsItem de `name` + las columnas
        // de sistema (slug, is_active, timestamps). El dev suma los items del
        // dominio post-scaffold.
    }

    protected function patchIndexColumns(string $file): void
    {
        $transforms = [
            [
                '/\s*\{ title: t\(\'[^\']+\.cod\'\)[^}]*\},\s*\n/',
                "\n",
                'removed cod column',
                true,
            ],
            [
                '/\s*\{ title: t\(\'[^\']+\.country\'\)[^}]*\},\s*\n/',
                "\n",
                'removed country column',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);
    }

    protected function patchFormRequest(string $file): void
    {
        if (!File::exists($file)) return;

        $transforms = [
            // Borrar bloque rule de cod (multi-linea con Rule::unique).
            [
                "/\s*'cod'\s*=>\s*\[\s*\n\s*'nullable',\s*'string',\s*'max:50',\s*\n\s*Rule::unique\([^)]+\)[\s\S]*?\->where\(fn[\s\S]*?\),\s*\n\s*\],\s*\n/",
                "\n",
                'removed cod rule block',
                true,
            ],
            // Borrar la rule de country_id.
            [
                "/\s*'country_id'\s*=>\s*\['nullable',\s*'integer',\s*'exists:countries,id'\],\s*\n/",
                "\n",
                'removed country_id rule',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);

        // Module nuevo queda con solo la rule de `name` (que ya viene del
        // Customer master). El dev suma las rules del dominio post-scaffold.

        // Quitar `use Rule` si ya no se usa — al quitar la rule de cod
        // (que era la única que usaba `Rule::unique`), el import queda huérfano.
        $content = file_get_contents($file);
        if (!str_contains($content, 'Rule::')) {
            $newContent = preg_replace("/^use Illuminate\\\\Validation\\\\Rule;\s*\n/m", '', $content);
            if ($newContent !== null && $newContent !== $content) {
                file_put_contents($file, $newContent);
            }
        }
    }

    protected function patchFactory(string $file): void
    {
        if (!File::exists($file)) return;

        $transforms = [
            // CustomerFactory tiene 'cod' con valor `'C-' . strtoupper(Str::random(6))`
            // — no es siempre faker. Regex genérico que mata cualquier expresión
            // de una sola línea entre 'cod' => y la coma final.
            [
                "/\s*'cod'\s*=>\s*[^\n]+,\s*\n/",
                "\n",
                'removed cod from factory definition',
                true,
            ],
            [
                "/\s*'country_id'\s*=>\s*[^\n]+,\s*\n/",
                "\n",
                'removed country_id from factory definition',
                true,
            ],
            [
                "/^use App\\\\Models\\\\Country;\s*\n/m",
                '',
                'removed Country import from factory',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);

        // Factory queda con solo `name` + `is_active`. El dev suma campos
        // del dominio post-scaffold (matching the migration columns).
    }

    /**
     * Helper genérico para patchear lang files: quita keys cod/country/cod_*
     * (incluyendo `*_help` / `*_placeholder` que el master template tiene desde
     * que los formularios usan tooltips de ayuda). El módulo nuevo queda con
     * las keys de `name`, `is_active` y demás del sistema.
     */
    protected function patchLangFile(string $file): void
    {
        if (!File::exists($file)) return;

        $transforms = [
            [
                "/\s*'cod'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'cod' key",
                true,
            ],
            [
                "/\s*'cod_hint'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'cod_hint' key",
                true,
            ],
            [
                "/\s*'cod_help'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'cod_help' key",
                true,
            ],
            [
                "/\s*'cod_placeholder'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'cod_placeholder' key",
                true,
            ],
            [
                "/\s*'country'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'country' key",
                true,
            ],
            [
                "/\s*'country_help'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'country_help' key",
                true,
            ],
            [
                "/\s*'country_id_help'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'country_id_help' key",
                true,
            ],
            [
                "/\s*'country_placeholder'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'country_placeholder' key",
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);
    }

    /**
     * Patch XLSX export: quitar entries cod/country del columnDefs, agregar description.
     */
    protected function patchExportXlsx(string $file, string $newLowerPl): void
    {
        if (!File::exists($file)) return;

        $transforms = [
            [
                "/\s*'cod'\s*=>\s*\['heading'\s*=>\s*__\([^)]+\),\s*'value'\s*=>\s*fn\([^)]+\)\s*=>[^]]+\],\s*\n/",
                "\n",
                'removed cod from columnDefs',
                true,
            ],
            [
                "/\s*'country'\s*=>\s*\['heading'\s*=>\s*__\([^)]+\),\s*'value'\s*=>\s*fn\([^)]+\)\s*=>[^]]+\],\s*\n/",
                "\n",
                'removed country from columnDefs',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);

        // Export queda con columnas de name + sistema (id, slug, is_active,
        // created_at, etc.). El dev suma las columnas del dominio post-scaffold.
    }

    protected function patchExportWord(string $file, string $newLowerPl): void
    {
        if (!File::exists($file)) return;

        $transforms = [
            [
                "/\s*'cod'\s*=>\s*\['heading'\s*=>\s*__\([^)]+\),\s*'value'\s*=>\s*fn\([^)]+\)\s*=>[^]]+\],\s*\n/",
                "\n",
                'removed cod from Word columnDefs',
                true,
            ],
            [
                "/\s*'country'\s*=>\s*\['heading'\s*=>\s*__\([^)]+\),\s*'value'\s*=>\s*fn\([^)]+\)\s*=>[^]]+\],\s*\n/",
                "\n",
                'removed country from Word columnDefs',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);

        // Word export queda con columnas de name + sistema. El dev suma las
        // del dominio post-scaffold.
    }

    /**
     * Patch import template: reescribir columnas a [name, description, is_active].
     */
    /**
     * Genera la plantilla blade del export PDF del módulo nuevo. El scaffold
     * clona PHP pero NO las vistas, así que sin esto el job de PDF apunta a una
     * vista inexistente y revienta. La plantilla es genérica (id + name + estado
     * + columnas de sistema), iterando la colección que el job inyecta con la
     * key del módulo (ej. 'oil_types' => $oilTypes → blade itera $oil_types).
     * El dev agrega las columnas del dominio editando este blade post-scaffold.
     */
    protected function generatePdfTemplate(string $group, string $newLowerPl): void
    {
        $viewGroup = \Illuminate\Support\Str::snake($group);
        $dir  = base_path("resources/views/{$viewGroup}/{$newLowerPl}/pdf");
        $file = "{$dir}/template.blade.php";
        if (File::exists($file)) {
            return; // no pisar una vista ya escrita a mano
        }

        $blade = <<<'BLADE'
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        /* DomPDF: solo Helvetica, font-weight normal/bold. Clon del master. */
        @page { margin: 24px 28px 24px 28px; }
        body { font-family: Helvetica; font-size: 9pt; color: #32363A; margin: 0; }
        .brand-band { background: #354A5F; color: #ffffff; padding: 14px 18px; margin-bottom: 14px; }
        .brand-band__meta { float: right; font-size: 8pt; color: #cbd5e1; text-align: right; line-height: 1.4; }
        .brand-band__meta strong { color: #ffffff; font-weight: bold; }
        .brand-band__title { font-size: 14pt; font-weight: bold; margin: 0; letter-spacing: 0.01em; }
        .brand-band__sub { font-size: 8pt; color: #cbd5e1; margin: 4px 0 0 0; }
        .filters { background: #F0F6FB; border-left: 3px solid #0A6ED1; padding: 8px 12px; margin: 0 0 12px 0; font-size: 8.5pt; color: #334155; }
        .filters__title { display: block; font-weight: bold; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.06em; color: #0A6ED1; margin: 0 0 4px 0; }
        .filters__list { margin: 0; padding: 0; list-style: none; }
        .filters__list li { line-height: 1.5; }
        .filters__list li b { font-weight: bold; color: #1f2937; }
        .counter { font-size: 8.5pt; color: #6A6D70; margin: 0 0 8px 0; }
        table.data { width: 100%; border-collapse: collapse; margin: 0; }
        table.data thead th { background: #0A6ED1; color: #ffffff; font-weight: bold; font-size: 9pt; text-align: left; padding: 6px 8px; border: 1px solid #085CAF; }
        table.data tbody td { padding: 5px 8px; border: 1px solid #E5E5E5; font-size: 8.5pt; color: #32363A; }
        table.data tbody tr:nth-child(even) td { background: #F8FAFC; }
        .status-active   { color: #1D7044; font-weight: bold; }
        .status-inactive { color: #C8281D; font-weight: bold; }
        .empty { text-align: center; padding: 32px 20px; color: #6A6D70; font-size: 9pt; }
        .doc-footer { margin-top: 16px; padding-top: 8px; border-top: 1px solid #E5E5E5; font-size: 7.5pt; color: #6A6D70; text-align: center; }
    </style>
</head>
<body>
    <div class="brand-band">
        <div class="brand-band__meta">
            <strong>{{ config('app.name') }}</strong><br>
            {{ __('global.created_by') }}: {{ $generatedBy }}
        </div>
        <h1 class="brand-band__title">{{ $title }}</h1>
        <p class="brand-band__sub">
            {{ __('global.generated_at') }}: {{ now()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT) }}
        </p>
    </div>

    @if (!empty($filtersSummary))
        <div class="filters">
            <span class="filters__title">{{ __('global.filters_applied') }}</span>
            <ul class="filters__list">
                @foreach ($filtersSummary as $f)
                    <li><b>{{ $f['label'] }}:</b> {{ $f['value'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="counter">
        {{ trans_choice('global.records_in_report', $totalCount, ['count' => $totalCount]) }}
    </p>

    @php
        $headings = [
            'id'         => __('__NS__.id'),
            'name'       => __('__NS__.name'),
            'is_active'  => __('__NS__.is_active'),
            'slug'       => 'Slug',
            'created_at' => __('global.created_at'),
            'updated_at' => __('global.updated_at'),
            'creator'    => __('global.created_by'),
        ];
    @endphp

    @if ($totalCount === 0)
        <div class="empty">{{ __('global.no_matching_records') }}</div>
    @else
        <table class="data">
            <thead>
                <tr>
                    @foreach ($columns as $col)
                        <th>{{ $headings[$col] ?? $col }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($__VAR__ as $item)
                    <tr>
                        @foreach ($columns as $col)
                            <td>
                                @switch($col)
                                    @case('id')         {{ $item->id }} @break
                                    @case('name')       {{ $item->name }} @break
                                    @case('is_active')
                                        <span class="{{ $item->is_active ? 'status-active' : 'status-inactive' }}">
                                            {{ $item->state_text }}
                                        </span>
                                    @break
                                    @case('slug')       {{ $item->slug }} @break
                                    @case('created_at') {{ $item->created_at?->copy()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT) }} @break
                                    @case('updated_at') {{ $item->updated_at?->copy()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT) }} @break
                                    @case('creator')    {{ $item->creator->name ?? '—' }} @break
                                    @default {{ $item->{$col} ?? '' }}
                                @endswitch
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="doc-footer">
        {{ config('app.name') }} · {{ now()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATE_FORMAT) }}
    </div>
</body>
</html>
BLADE;

        $blade = str_replace(['__NS__', '$__VAR__'], [$newLowerPl, '$' . $newLowerPl], $blade);

        File::ensureDirectoryExists($dir);
        File::put($file, $blade);
        $this->createdFiles[] = $file;
        $this->line("  POST: generated PDF template " . $this->relPath($file));
    }

    protected function patchImportTemplate(string $file): void
    {
        if (!File::exists($file)) return;

        $content = file_get_contents($file);
        $original = $content;

        // Reescribir el método array() a SOLO [name] — el módulo nuevo arranca
        // con name como único campo de dominio. NO se incluye is_active: toda
        // alta importada nace activa (el estado se gestiona desde la UI), igual
        // que Customers. El dev expande el template con las columnas del dominio.
        $newArray = "    public function array(): array\n    {\n        return [\n            ['name'],\n            ['Ejemplo 1'],\n            ['Ejemplo 2'],\n            ['Ejemplo 3'],\n        ];\n    }";
        $content = preg_replace(
            '/public function array\(\): array\s*\{\s*\n\s*return \[\s*\n[\s\S]*?\];\s*\n\s*\}/',
            $newArray,
            $content,
            1
        );

        // Una sola columna (name): rango de estilo y autosize → solo A.
        $content = str_replace(["'A1:D1'", "'A1:C1'", "'A1:B1'"], "'A1:A1'", $content);
        $content = str_replace(
            ["['A', 'B', 'C', 'D']", "['A', 'B', 'C']", "['A', 'B']"],
            "['A']",
            $content
        );

        // Quitar comments de cod (B1) e ISO (C1) que vienen del template Customer.
        $content = preg_replace(
            '/\s*\$commentCod\s*=\s*\$sheet->getComment\(\'B1\'\);[\s\S]*?\$commentCod->setHeight\(\'60pt\'\);\s*\n/',
            "\n",
            $content
        );
        $content = preg_replace(
            '/\s*\$commentIso\s*=\s*\$sheet->getComment\(\'C1\'\);[\s\S]*?\$commentIso->setHeight\(\'80pt\'\);\s*\n/',
            "\n",
            $content
        );

        if ($content !== $original) {
            file_put_contents($file, $content);
            $this->line("  POST: rewrote template columns to [name] in " . $this->relPath($file));
        } else {
            $this->warn("  Could not patch import template columns in " . $this->relPath($file));
        }
    }

    /**
     * Patch import: quita la lectura de cod/country_iso del template Customer.
     * El módulo nuevo arranca con SOLO `name` + `is_active`. El dev agrega
     * las columnas del dominio post-scaffold editando este archivo.
     */
    protected function patchImport(string $file, string $singular, string $newLowerPl): void
    {
        if (!File::exists($file)) return;

        $content = file_get_contents($file);
        $original = $content;

        // Quitar import de Country.
        $content = preg_replace("/^use App\\\\Models\\\\Country;\s*\n/m", '', $content);

        // Quitar property countryIsoCache.
        $content = preg_replace(
            "/\s*\/\*\* Cache iso_code → country_id[^*]*\*\/\s*\n\s*protected array \\\$countryIsoCache = \[\];\s*\n/",
            "\n",
            $content
        );
        // Quitar la precarga ISO → id en el constructor.
        $content = preg_replace(
            "/\s*\/\/ Precarga ISO → id[\s\S]*?->each\(function \(\\\$c\) \{\s*\n\s*\\\$this->countryIsoCache\[mb_strtoupper\(trim\(\\\$c->iso_code\)\)\] = \\\$c->id;\s*\n\s*\}\);\s*\n/",
            "\n",
            $content
        );

        // Quitar dedup de cod.
        $content = preg_replace(
            "/\s*\/\/ Dedup intra-archivo por cod[\s\S]*?\\\$seenInFileByCod\s*=\s*\[\];\s*\n/",
            "\n",
            $content
        );
        $content = preg_replace(
            "/\s*\\\$cod = \\\$this->normalizeCod\(\\\$row\['cod'\] \?\? null\);[\s\S]*?\\\$seenInFileByCod\[\\\$cod\] = \\\$absoluteRow;\s*\n\s*\}\s*\n/",
            "\n",
            $content
        );

        // Quitar countryIso + countryId reads del loop.
        $content = preg_replace(
            "/\s*\\\$countryIso = \\\$this->normalizeIso\(\\\$row\['country_iso'\] \?\? null\);\s*\n\s*\\\$countryId\s*=\s*\\\$countryIso !== null \? \(\\\$this->countryIsoCache\[\\\$countryIso\] \?\? null\) : null;\s*\n/",
            "\n",
            $content
        );

        // Update block: quitar las líneas de cod/country_id del $patch (sin
        // reemplazar por description ni nada — el módulo nuevo solo update name).
        $content = preg_replace(
            "/\s*if \(\\\$cod !== null && \\\$existing->cod !== \\\$cod\)\s*\\\$patch\['cod'\]\s*=\s*\\\$cod;\s*\n\s*if \(\\\$countryId !== null && \\\$existing->country_id !== \\\$countryId\) \{\s*\n\s*\\\$patch\['country_id'\] = \\\$countryId;\s*\n\s*\}\s*\n/",
            "\n",
            $content
        );

        // create() block: solo name + is_active + created_by.
        $createPattern = "/(Customer|{$singular})::create\(\[\s*\n\s*'name'\s*=>\s*\\\$name,\s*\n\s*'cod'\s*=>\s*\\\$cod,\s*\n\s*'country_id'\s*=>\s*\\\$countryId,\s*\n\s*'is_active'\s*=>\s*\\\$isActive,\s*\n\s*'created_by'\s*=>\s*Auth::id\(\),/";
        $content = preg_replace(
            $createPattern,
            "{$singular}::create([\n                        'name'        => \$name,\n                        'is_active'   => \$isActive,\n                        'created_by'  => Auth::id(),",
            $content
        );

        // Limpiar entries 'cod' / 'country_iso' del preview array.
        $content = preg_replace("/\s*'cod'\s*=>\s*\\\$cod,\s*\n/", "\n", $content);
        $content = preg_replace("/\s*'country_iso'\s*=>\s*\\\$countryIso,\s*\n/", "\n", $content);

        // Quitar helpers normalizeCod y normalizeIso (ya no se usan).
        $content = preg_replace(
            "/\s*protected function normalizeCod\([\s\S]*?return \\\$cod === '' \? null : \\\$cod;\s*\n\s*\}\s*\n/",
            "\n",
            $content
        );
        $content = preg_replace(
            "/\s*protected function normalizeIso\([\s\S]*?return \\\$iso === '' \? null : \\\$iso;\s*\n\s*\}\s*\n/",
            "\n",
            $content
        );

        // Actualizar phpdoc del array preview: row con solo name + is_active + action.
        $content = preg_replace(
            "/@var array<int, array\{row:int, name:string, cod:\?string, country_iso:\?string, is_active:bool, action:string\}>/",
            "@var array<int, array{row:int, name:string, is_active:bool, action:string}>",
            $content
        );

        if ($content !== $original) {
            file_put_contents($file, $content);
            $this->line("  POST: rewrote import to [name, is_active] only in " . $this->relPath($file));
        } else {
            $this->warn("  Could not patch import in " . $this->relPath($file));
        }
    }

    /**
     * Rollback: borra archivos creados y restaura modificados.
     */
    protected function rollback(): void
    {
        foreach ($this->createdFiles as $path) {
            if (File::exists($path)) {
                File::delete($path);
                $this->line("  REVERTIDO (deleted): " . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path));
            }
        }
        foreach ($this->modifiedFiles as $path => $original) {
            file_put_contents($path, $original);
            $this->line("  REVERTIDO (restored): " . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path));
        }
    }

    protected function printChecklist(): void
    {
        $singular    = $this->module;
        $plural      = $this->plural($singular);
        $newLowerPl  = $this->snakePlural();
        $newLower    = Str::camel($singular);
        $groupSnake  = Str::snake($this->group);

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info("  MODULO {$this->module} GENERADO");
        $this->info('═══════════════════════════════════════════════════════════');
        $this->line("  Archivos creados:     " . count($this->createdFiles));
        $this->line("  Archivos modificados: " . count($this->modifiedFiles));
        $this->newLine();

        $this->line("Ubicaciones principales:");
        $this->line("  Backend:    app/Http/Controllers/{$this->group}/{$singular}Controller.php");
        $this->line("  Service:    app/Services/{$this->group}/{$singular}Service.php");
        $this->line("  Model:      app/Models/{$singular}.php");
        $this->line("  Migration:  database/migrations/*_create_{$newLowerPl}_table.php");
        $this->line("  Factory:    database/factories/{$singular}Factory.php");
        $this->line("  Frontend:   resources/js/Pages/{$plural}/  (6 paginas + 5 configs)");
        $this->line("  Components: resources/js/Components/{$plural}/  (13 componentes)");
        $this->line("  Tests:      tests/Feature/{$this->group}/{$plural}/");
        $this->line("  Routes:     routes/{$groupSnake}.php  (bloque appendeado)");
        $this->line("  Config:     config/{$newLowerPl}.php");
        $this->line("  Lang:       resources/lang/{es,en}/{$newLowerPl}.php");
        $this->newLine();

        $this->warn("PASOS MANUALES OBLIGATORIOS (sin esto el modulo no funciona):");
        $this->newLine();

        $this->line("  1) Migrar la base de datos");
        $this->line("     php artisan migrate");
        $this->line("     Si la migracion falla, revisa el archivo generado y reintenta.");
        $this->newLine();

        $this->line("  2) Permisos Spatie en el seeder de roles");
        $this->line("     Editar: database/seeders/RolesAndPermissionsSeeder.php");
        $this->line("     Agregar los 4 permisos basicos al array de permisos:");
        $this->line("         '{$newLowerPl}.view'");
        $this->line("         '{$newLowerPl}.create'");
        $this->line("         '{$newLowerPl}.edit'");
        $this->line("         '{$newLowerPl}.delete'");
        $this->line("     Asignar al rol super (y a admin/user segun corresponda).");
        $this->line("     Luego correr: php artisan db:seed --class=RolesAndPermissionsSeeder");
        $this->newLine();

        $this->line("  3) Sidebar (icono + entrada en el menu lateral)");
        $this->line("     resources/js/Layouts/AppLayout.vue");
        $this->line("       Importar el icono deseado de @ant-design/icons-vue.");
        $this->line("       Agregar el item en el array correspondiente al grupo:");
        $this->line("         { key: '{$newLowerPl}',");
        $this->line("           label: t('sidebar.{$newLowerPl}'),");
        $this->line("           icon: TuIcono,");
        $this->line("           href: route('{$groupSnake}.{$newLowerPl}.index'),");
        $this->line("           inertia: true,");
        $this->line("           visible: () => can('{$newLowerPl}.view') }");
        $this->line("     Agregar la traduccion en los 2 archivos de lang:");
        $this->line("       resources/lang/es/sidebar.php  =>  '{$newLowerPl}' => 'Nombre en espanol'");
        $this->line("       resources/lang/en/sidebar.php  =>  '{$newLowerPl}' => 'Name in english'");
        $this->newLine();

        $this->line("  4) Verificar build y limpieza de cache");
        $this->line("     npm run build");
        $this->line("     php artisan config:clear");
        $this->line("     php artisan route:clear");
        $this->newLine();

        $this->warn("PASOS RECOMENDADOS (segun el dominio del modulo):");
        $this->newLine();

        $this->line("  5) Columnas del dominio en la migracion");
        $this->line("     El scaffold solo trae 'name' (required) como campo de dominio,");
        $this->line("     mas las columnas del sistema (slug, is_active, tenant_id, audit).");
        $this->line("     Editar la migracion para sumar campos especificos:");
        $this->line("         price, stock, sku, description, birth_date, FKs, etc.");
        $this->line("     Tambien sumarlas al fillable del modelo, casts, factory,");
        $this->line("     Form.vue, Show.vue, columns.js, lang files, exports, imports");
        $this->line("     y las reglas de los FormRequests.");
        $this->newLine();

        $this->line("  6) Relaciones del modelo (FKs salientes)");
        $this->line("     Si el modulo tiene FKs hacia otras tablas, agregar el metodo");
        $this->line("     belongsTo() correspondiente en app/Models/{$singular}.php");
        $this->line("     y cargar la relacion con with() en el Service.");
        $this->newLine();

        $this->line("  7) Dependientes (FKs entrantes a este modulo)");
        $this->line("     Si OTROS modelos referencian a {$singular} con FK, declarar el");
        $this->line("     metodo dependents() para que el sistema avise antes de borrar:");
        $this->line("       app/Models/{$singular}.php  =>  public function dependents(): array");
        $this->newLine();

        $this->line("  8) Plan gating (si el modulo es premium)");
        $this->line("     Si el modulo debe estar disponible solo en planes pro/enterprise:");
        $this->line("       a) Sumar la feature en config/features.php (matrix de planes).");
        $this->line("       b) Aplicar middleware('plan_feature:nombre_feature') en las");
        $this->line("          rutas correspondientes en routes/{$groupSnake}.php");
        $this->line("       c) Sumar canUsePlanFeature() al visible del item del sidebar.");
        $this->newLine();

        $this->line("  9) Filtros avanzados (opcional)");
        $this->line("     Para usar el query builder de filtros avanzados, declarar:");
        $this->line("       app/Models/{$singular}.php  =>  public static function filterSchema(): array");
        $this->line("     Ejemplo en app/Models/Customer.php (master template).");
        $this->newLine();

        $this->line(" 10) Data source para Automatizaciones (opcional)");
        $this->line("     Si quieres que las automatizaciones puedan consultar este modulo:");
        $this->line("       a) Crear app/Services/Automations/DataSources/{$plural}DataSource.php");
        $this->line("          implementando DataSourceContract.");
        $this->line("       b) Registrarlo en DataSourceRegistry::register().");
        $this->line("     Detalles en docs/AUTOMATIONS.md (seccion 8).");
        $this->newLine();

        $this->line(" 11) Capa API REST (opcional — el scaffold NO la genera)");
        $this->line("     Por defecto los modulos generados son web-only (Inertia).");
        $this->line("     Si necesitas exponer el modulo via API REST:");
        $this->line("       a) Crear app/Http/Resources/{$singular}Resource.php");
        $this->line("          (mirar CustomerResource.php como referencia).");
        $this->line("       b) Crear app/Http/Controllers/Api/V1/{$singular}ApiController.php");
        $this->line("          (mirar CustomerApiController.php como referencia).");
        $this->line("       c) Agregar las rutas en routes/api.php con abilities Sanctum:");
        $this->line("          {$newLowerPl}:read / {$newLowerPl}:write / {$newLowerPl}:delete");
        $this->line("       d) Documentar con anotaciones Scribe y regenerar docs:");
        $this->line("          php artisan scribe:generate");
        $this->newLine();

        $this->line(" 12) Tests");
        $this->line("     El scaffold clona los tests si el master Brand los tiene. Verificar:");
        $this->line("       php artisan test --filter={$singular}");
        $this->newLine();

        $this->info("URL del modulo nuevo: /{$groupSnake}/{$newLowerPl}");
        $this->info("Documentacion completa: docs/CREATE-MODULE.md");
        $this->info('═══════════════════════════════════════════════════════════');
    }
}
