<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SystemManagement\SystemModuleController;
use App\Http\Controllers\SystemManagement\SettingController;
use App\Http\Controllers\SystemManagement\CountryController;
use App\Http\Controllers\SystemManagement\LanguageController;
use App\Http\Controllers\SystemManagement\LocaleController;
use App\Http\Controllers\SystemManagement\TenantController;
use App\Http\Controllers\SystemManagement\TenantSubscriptionController;
use App\Http\Controllers\SystemManagement\RegionController;
use App\Http\Controllers\SystemManagement\PlanController;
use App\Http\Controllers\SystemManagement\AuditLogController;
use App\Http\Controllers\SystemManagement\DiagnosticRulesController;

Route::prefix('system_management')->name('system_management.')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Audit Logs — super OR admin
    |--------------------------------------------------------------------------
    | Read-only ledger across all modules. Super sees everything;
    | admin sees only their tenant's logs (filtered by query in controller).
    | Solo por rol (sin plan_feature): cada tenant ve su propia auditoría.
    */
    Route::middleware(['role:super|admin'])->group(function () {
        Route::get('audit_logs', [AuditLogController::class, 'index'])->name('audit_logs.index');
    });

    /*
    |--------------------------------------------------------------------------
    | SUPER ONLY — master tables
    |--------------------------------------------------------------------------
    | These modules manage system-wide configuration. Tenant admins and
    | their workers can NEVER see, manipulate, or even reach these URLs.
    | The role middleware blocks them with 403 even if they guess the URL.
    */
    /*
    | Reglas de diagnóstico — super OR admin del workspace.
    | El motor es híbrido: super edita el ESTÁNDAR GLOBAL; un admin edita el
    | OVERRIDE de SU workspace (aislado, no afecta a otros ni al global). Los
    | "cuadros de reglas" (sets) son tenant-safe vía copy-on-write: el admin que
    | edita un cuadro global crea/edita su propia copia (gateado en el controlador).
    */
    Route::middleware(['role:super|admin'])->group(function () {
        Route::get('diagnostic-rules', [DiagnosticRulesController::class, 'index'])->name('diagnostic_rules.index');
        // Cuadros de reglas — tenant-safe (copy-on-write, gateado en el controlador).
        Route::get('diagnostic-rules/sets', [DiagnosticRulesController::class, 'sets'])->name('diagnostic_rules.sets');
        Route::get('diagnostic-rules/sets/{ruleSet}', [DiagnosticRulesController::class, 'editSet'])->name('diagnostic_rules.set_edit');
        Route::put('diagnostic-rules/sets/{ruleSet}', [DiagnosticRulesController::class, 'updateSet'])->name('diagnostic_rules.set_update');
        Route::post('diagnostic-rules/sets/{ruleSet}/restore', [DiagnosticRulesController::class, 'restoreSet'])->name('diagnostic_rules.set_restore');
        Route::post('diagnostic-rules/restore', [DiagnosticRulesController::class, 'restore'])->name('diagnostic_rules.restore');
        Route::put('diagnostic-rules/labels', [DiagnosticRulesController::class, 'updateLabels'])->name('diagnostic_rules.labels_update');
        Route::post('diagnostic-rules/labels/restore', [DiagnosticRulesController::class, 'restoreLabels'])->name('diagnostic_rules.labels_restore');
        Route::put('diagnostic-rules/colors', [DiagnosticRulesController::class, 'updateColors'])->name('diagnostic_rules.colors_update');
        Route::post('diagnostic-rules/colors/restore', [DiagnosticRulesController::class, 'restoreColors'])->name('diagnostic_rules.colors_restore');
        Route::get('diagnostic-rules/data/{key}', [DiagnosticRulesController::class, 'editData'])->name('diagnostic_rules.data_edit');
        Route::put('diagnostic-rules/data/{key}', [DiagnosticRulesController::class, 'updateData'])->name('diagnostic_rules.data_update');
        Route::post('diagnostic-rules/data/{key}/restore', [DiagnosticRulesController::class, 'restoreData'])->name('diagnostic_rules.data_restore');
        Route::put('diagnostic-rules/{code}', [DiagnosticRulesController::class, 'update'])->name('diagnostic_rules.update');
    });

    Route::middleware('role:super')->group(function () {

        // ── System Modules (mismo patrón que Regions) ──
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('system_modules/export_excel', [SystemModuleController::class, 'exportExcel'])->name('system_modules.export_excel');
            Route::post('system_modules/export_pdf',   [SystemModuleController::class, 'exportPdf'])->name('system_modules.export_pdf');
            Route::post('system_modules/export_word',  [SystemModuleController::class, 'exportWord'])->name('system_modules.export_word');
            Route::post('system_modules/export_csv',   [SystemModuleController::class, 'exportCsv'])->name('system_modules.export_csv');
            Route::post('system_modules/import',       [SystemModuleController::class, 'import'])->name('system_modules.import');
        });
        Route::get('system_modules/import_template', [SystemModuleController::class, 'importTemplate'])->name('system_modules.import_template');
        Route::get('system_modules/edit_all',         [SystemModuleController::class, 'editAll'])->name('system_modules.edit_all');
        Route::post('system_modules/edit_all/update', [SystemModuleController::class, 'editAllUpdate'])->name('system_modules.edit_all.update');
        Route::get('system_modules/trash',           [SystemModuleController::class, 'trash'])->name('system_modules.trash');
        Route::post('system_modules/{slug}/restore', [SystemModuleController::class, 'restore'])->name('system_modules.restore');
        Route::get('system_modules/{slug}/restore',  fn () => redirect()->route('system_management.system_modules.trash'));
        Route::resource('system_modules', SystemModuleController::class)->names('system_modules');
        Route::get('system_modules/{system_module}/delete',        [SystemModuleController::class, 'delete'])->name('system_modules.delete');
        Route::delete('system_modules/{system_module}/deleteSave', [SystemModuleController::class, 'deleteSave'])->name('system_modules.deleteSave');
        Route::post('system_modules/{system_module}/duplicate',    [SystemModuleController::class, 'duplicate'])->name('system_modules.duplicate');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('system_modules/bulk_delete',     [SystemModuleController::class, 'bulkDelete'])->name('system_modules.bulk_delete');
            Route::post('system_modules/bulk_set_active', [SystemModuleController::class, 'bulkSetActive'])->name('system_modules.bulk_set_active');
            Route::post('system_modules/bulk_restore',    [SystemModuleController::class, 'bulkRestore'])->name('system_modules.bulk_restore');
        });
        Route::post('system_modules/undo_last_delete',      [SystemModuleController::class, 'undoLastDelete'])->name('system_modules.undo_last_delete');
        Route::delete('system_modules/{slug}/force_delete', [SystemModuleController::class, 'forceDelete'])->name('system_modules.force_delete');

        // ── Permissions management — agregar/eliminar acciones custom ──
        Route::post('system_modules/{system_module}/permissions',                  [SystemModuleController::class, 'storePermission'])->name('system_modules.permissions.store');
        Route::delete('system_modules/{system_module}/permissions/{permissionId}', [SystemModuleController::class, 'destroyPermission'])->name('system_modules.permissions.destroy');

        // ── Tenants (Workspaces) — mismo patrón que Regions ──
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('tenants/export_excel', [TenantController::class, 'exportExcel'])->name('tenants.export_excel');
            Route::post('tenants/export_pdf',   [TenantController::class, 'exportPdf'])->name('tenants.export_pdf');
            Route::post('tenants/export_word',  [TenantController::class, 'exportWord'])->name('tenants.export_word');
            Route::post('tenants/export_csv',   [TenantController::class, 'exportCsv'])->name('tenants.export_csv');
            Route::post('tenants/import',       [TenantController::class, 'import'])->name('tenants.import');
        });
        Route::get('tenants/import_template', [TenantController::class, 'importTemplate'])->name('tenants.import_template');
        Route::get('tenants/edit_all',         [TenantController::class, 'editAll'])->name('tenants.edit_all');
        Route::post('tenants/edit_all/update', [TenantController::class, 'editAllUpdate'])->name('tenants.edit_all.update');
        Route::get('tenants/trash',           [TenantController::class, 'trash'])->name('tenants.trash');
        Route::post('tenants/{slug}/restore', [TenantController::class, 'restore'])->name('tenants.restore');
        Route::get('tenants/{slug}/restore',  fn () => redirect()->route('system_management.tenants.trash'));
        // API tokens (Sanctum) — super only via parent middleware.
        Route::post('tenants/{tenant}/tokens',             [TenantController::class, 'createToken'])->name('tenants.tokens.create');
        Route::delete('tenants/{tenant}/tokens/{tokenId}', [TenantController::class, 'revokeToken'])->name('tenants.tokens.revoke');

        // Subscriptions del tenant — gobernanza de billing.
        Route::post('tenants/{tenant}/subscriptions',                          [TenantSubscriptionController::class, 'store'])->name('tenants.subscriptions.store');
        Route::post('tenants/{tenant}/subscriptions/renew',                    [TenantSubscriptionController::class, 'renew'])->name('tenants.subscriptions.renew');
        Route::post('tenants/{tenant}/subscriptions/{subscription}/cancel',    [TenantSubscriptionController::class, 'cancel'])->name('tenants.subscriptions.cancel');

        // Plans — pricing tiers editables (super only).
        // CRUD + soft-delete con motivo (patrón Regions). Sin import/export/edit_all
        // porque el dataset es chico (4-10 planes típico) y no aplica.
        Route::get('plans/trash',                  [PlanController::class, 'trash'])->name('plans.trash');
        Route::post('plans/{plan}/restore',        [PlanController::class, 'restore'])->name('plans.restore');
        Route::get('plans/{plan}/restore',         fn () => redirect()->route('system_management.plans.trash'));
        Route::delete('plans/{plan}/force_delete', [PlanController::class, 'forceDelete'])->name('plans.force_delete');
        Route::get('plans',                        [PlanController::class, 'index'])->name('plans.index');
        Route::get('plans/create',                 [PlanController::class, 'create'])->name('plans.create');
        Route::post('plans',                       [PlanController::class, 'store'])->name('plans.store');
        Route::get('plans/{plan}',                 [PlanController::class, 'show'])->name('plans.show');
        Route::get('plans/{plan}/edit',            [PlanController::class, 'edit'])->name('plans.edit');
        Route::put('plans/{plan}',                 [PlanController::class, 'update'])->name('plans.update');
        Route::get('plans/{plan}/delete',          [PlanController::class, 'delete'])->name('plans.delete');
        Route::delete('plans/{plan}/deleteSave',   [PlanController::class, 'deleteSave'])->name('plans.deleteSave');

        Route::resource('tenants', TenantController::class)->names('tenants');
        Route::get('tenants/{tenant}/delete',        [TenantController::class, 'delete'])->name('tenants.delete');
        Route::delete('tenants/{tenant}/deleteSave', [TenantController::class, 'deleteSave'])->name('tenants.deleteSave');
        Route::post('tenants/{tenant}/duplicate',    [TenantController::class, 'duplicate'])->name('tenants.duplicate');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('tenants/bulk_delete',     [TenantController::class, 'bulkDelete'])->name('tenants.bulk_delete');
            Route::post('tenants/bulk_set_active', [TenantController::class, 'bulkSetActive'])->name('tenants.bulk_set_active');
            Route::post('tenants/bulk_restore',    [TenantController::class, 'bulkRestore'])->name('tenants.bulk_restore');
        });
        Route::post('tenants/undo_last_delete',      [TenantController::class, 'undoLastDelete'])->name('tenants.undo_last_delete');
        Route::delete('tenants/{slug}/force_delete', [TenantController::class, 'forceDelete'])->name('tenants.force_delete');

        // ── Regions ──
        // Exports usan POST porque el dialog manda payload con columnas, alcance y opciones.
        // Rate limit estricto en exports/imports — son operaciones pesadas (encolan jobs,
        // generan archivos). Sin throttle, un usuario puede saturar la queue. 5/min es
        // generoso para uso humano normal y filtra abusos automatizados.
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('regions/export_excel', [RegionController::class, 'exportExcel'])->name('regions.export_excel');
            Route::post('regions/export_pdf',   [RegionController::class, 'exportPdf'])->name('regions.export_pdf');
            Route::post('regions/export_word',  [RegionController::class, 'exportWord'])->name('regions.export_word');
            Route::post('regions/export_csv',   [RegionController::class, 'exportCsv'])->name('regions.export_csv');
            Route::post('regions/import',       [RegionController::class, 'import'])->name('regions.import');
        });
        // Template download es liviano (XLSX armado en memoria, sin job) — sin throttle.
        Route::get('regions/import_template', [RegionController::class, 'importTemplate'])->name('regions.import_template');
        Route::get('regions/edit_all',         [RegionController::class, 'editAll'])->name('regions.edit_all');
        Route::post('regions/edit_all/update', [RegionController::class, 'editAllUpdate'])->name('regions.edit_all.update');
        Route::get('regions/trash',           [RegionController::class, 'trash'])->name('regions.trash');
        Route::post('regions/{slug}/restore', [RegionController::class, 'restore'])->name('regions.restore');
        // GET fallback for /restore — redirect super to trash list (cleaner than 405).
        Route::get('regions/{slug}/restore',  fn () => redirect()->route('system_management.regions.trash'));
        Route::resource('regions', RegionController::class)->names('regions');
        Route::get('regions/{region}/delete',        [RegionController::class, 'delete'])->name('regions.delete');
        Route::delete('regions/{region}/deleteSave', [RegionController::class, 'deleteSave'])->name('regions.deleteSave');
        Route::post('regions/{region}/duplicate',    [RegionController::class, 'duplicate'])->name('regions.duplicate');
        // Bulk endpoints — throttle más estricto: cada request puede afectar
        // hasta `bulk_async_threshold` * cantidad de IDs. Sin esto, un cliente
        // puede mandar 50 requests con 500 IDs cada uno = 25k registros tocados.
        // 10/min sigue siendo holgado para uso humano (toolbar bulk actions).
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('regions/bulk_delete',      [RegionController::class, 'bulkDelete'])->name('regions.bulk_delete');
            Route::post('regions/bulk_set_active',  [RegionController::class, 'bulkSetActive'])->name('regions.bulk_set_active');
            Route::post('regions/bulk_restore',     [RegionController::class, 'bulkRestore'])->name('regions.bulk_restore');
        });
        Route::post('regions/undo_last_delete',      [RegionController::class, 'undoLastDelete'])->name('regions.undo_last_delete');
        Route::delete('regions/{slug}/force_delete', [RegionController::class, 'forceDelete'])->name('regions.force_delete');

        // ── Languages (mismo patrón que Regions) ──
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('languages/export_excel', [LanguageController::class, 'exportExcel'])->name('languages.export_excel');
            Route::post('languages/export_pdf',   [LanguageController::class, 'exportPdf'])->name('languages.export_pdf');
            Route::post('languages/export_word',  [LanguageController::class, 'exportWord'])->name('languages.export_word');
            Route::post('languages/export_csv',   [LanguageController::class, 'exportCsv'])->name('languages.export_csv');
            Route::post('languages/import',       [LanguageController::class, 'import'])->name('languages.import');
        });
        Route::get('languages/import_template', [LanguageController::class, 'importTemplate'])->name('languages.import_template');
        Route::get('languages/edit_all',         [LanguageController::class, 'editAll'])->name('languages.edit_all');
        Route::post('languages/edit_all/update', [LanguageController::class, 'editAllUpdate'])->name('languages.edit_all.update');
        Route::get('languages/trash',           [LanguageController::class, 'trash'])->name('languages.trash');
        Route::post('languages/{slug}/restore', [LanguageController::class, 'restore'])->name('languages.restore');
        Route::get('languages/{slug}/restore',  fn () => redirect()->route('system_management.languages.trash'));
        Route::resource('languages', LanguageController::class)->names('languages');
        Route::get('languages/{language}/delete',        [LanguageController::class, 'delete'])->name('languages.delete');
        Route::delete('languages/{language}/deleteSave', [LanguageController::class, 'deleteSave'])->name('languages.deleteSave');
        Route::post('languages/{language}/duplicate',    [LanguageController::class, 'duplicate'])->name('languages.duplicate');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('languages/bulk_delete',     [LanguageController::class, 'bulkDelete'])->name('languages.bulk_delete');
            Route::post('languages/bulk_set_active', [LanguageController::class, 'bulkSetActive'])->name('languages.bulk_set_active');
            Route::post('languages/bulk_restore',    [LanguageController::class, 'bulkRestore'])->name('languages.bulk_restore');
        });
        Route::post('languages/undo_last_delete',      [LanguageController::class, 'undoLastDelete'])->name('languages.undo_last_delete');
        Route::delete('languages/{slug}/force_delete', [LanguageController::class, 'forceDelete'])->name('languages.force_delete');

        // ── Settings (mismo patrón que Regions) ──
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('settings/export_excel', [SettingController::class, 'exportExcel'])->name('settings.export_excel');
            Route::post('settings/export_pdf',   [SettingController::class, 'exportPdf'])->name('settings.export_pdf');
            Route::post('settings/export_word',  [SettingController::class, 'exportWord'])->name('settings.export_word');
            Route::post('settings/export_csv',   [SettingController::class, 'exportCsv'])->name('settings.export_csv');
            Route::post('settings/import',       [SettingController::class, 'import'])->name('settings.import');
            Route::post('settings/branding/logo',   [SettingController::class, 'uploadAppLogo'])->name('settings.branding.upload_logo');
            Route::delete('settings/branding/logo', [SettingController::class, 'removeAppLogo'])->name('settings.branding.remove_logo');
        });
        Route::get('settings/import_template', [SettingController::class, 'importTemplate'])->name('settings.import_template');
        Route::get('settings/edit_all',         [SettingController::class, 'editAll'])->name('settings.edit_all');
        Route::post('settings/edit_all/update', [SettingController::class, 'editAllUpdate'])->name('settings.edit_all.update');
        Route::get('settings/trash',           [SettingController::class, 'trash'])->name('settings.trash');
        Route::post('settings/{slug}/restore', [SettingController::class, 'restore'])->name('settings.restore');
        Route::get('settings/{slug}/restore',  fn () => redirect()->route('system_management.settings.trash'));
        Route::resource('settings', SettingController::class)->names('settings');
        Route::get('settings/{setting}/delete',        [SettingController::class, 'delete'])->name('settings.delete');
        Route::delete('settings/{setting}/deleteSave', [SettingController::class, 'deleteSave'])->name('settings.deleteSave');
        Route::post('settings/{setting}/duplicate',    [SettingController::class, 'duplicate'])->name('settings.duplicate');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('settings/bulk_delete',     [SettingController::class, 'bulkDelete'])->name('settings.bulk_delete');
            Route::post('settings/bulk_set_active', [SettingController::class, 'bulkSetActive'])->name('settings.bulk_set_active');
            Route::post('settings/bulk_restore',    [SettingController::class, 'bulkRestore'])->name('settings.bulk_restore');
        });
        Route::post('settings/undo_last_delete',      [SettingController::class, 'undoLastDelete'])->name('settings.undo_last_delete');
        Route::delete('settings/{slug}/force_delete', [SettingController::class, 'forceDelete'])->name('settings.force_delete');

        // ── Countries (mismo patrón que Regions/Languages) ──
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('countries/export_excel', [CountryController::class, 'exportExcel'])->name('countries.export_excel');
            Route::post('countries/export_pdf',   [CountryController::class, 'exportPdf'])->name('countries.export_pdf');
            Route::post('countries/export_word',  [CountryController::class, 'exportWord'])->name('countries.export_word');
            Route::post('countries/export_csv',   [CountryController::class, 'exportCsv'])->name('countries.export_csv');
            Route::post('countries/import',       [CountryController::class, 'import'])->name('countries.import');
        });
        Route::get('countries/import_template', [CountryController::class, 'importTemplate'])->name('countries.import_template');
        Route::get('countries/edit_all',         [CountryController::class, 'editAll'])->name('countries.edit_all');
        Route::post('countries/edit_all/update', [CountryController::class, 'editAllUpdate'])->name('countries.edit_all.update');
        Route::get('countries/trash',           [CountryController::class, 'trash'])->name('countries.trash');
        Route::post('countries/{slug}/restore', [CountryController::class, 'restore'])->name('countries.restore');
        Route::get('countries/{slug}/restore',  fn () => redirect()->route('system_management.countries.trash'));
        Route::resource('countries', CountryController::class)->names('countries');
        Route::get('countries/{country}/delete',        [CountryController::class, 'delete'])->name('countries.delete');
        Route::delete('countries/{country}/deleteSave', [CountryController::class, 'deleteSave'])->name('countries.deleteSave');
        Route::post('countries/{country}/duplicate',    [CountryController::class, 'duplicate'])->name('countries.duplicate');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('countries/bulk_delete',     [CountryController::class, 'bulkDelete'])->name('countries.bulk_delete');
            Route::post('countries/bulk_set_active', [CountryController::class, 'bulkSetActive'])->name('countries.bulk_set_active');
            Route::post('countries/bulk_restore',    [CountryController::class, 'bulkRestore'])->name('countries.bulk_restore');
        });
        Route::post('countries/undo_last_delete',      [CountryController::class, 'undoLastDelete'])->name('countries.undo_last_delete');
        Route::delete('countries/{slug}/force_delete', [CountryController::class, 'forceDelete'])->name('countries.force_delete');

        // ── Locales (mismo patrón que Regions/Languages/Countries) ──
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('locales/export_excel', [LocaleController::class, 'exportExcel'])->name('locales.export_excel');
            Route::post('locales/export_pdf',   [LocaleController::class, 'exportPdf'])->name('locales.export_pdf');
            Route::post('locales/export_word',  [LocaleController::class, 'exportWord'])->name('locales.export_word');
            Route::post('locales/export_csv',   [LocaleController::class, 'exportCsv'])->name('locales.export_csv');
            Route::post('locales/import',       [LocaleController::class, 'import'])->name('locales.import');
        });
        Route::get('locales/import_template', [LocaleController::class, 'importTemplate'])->name('locales.import_template');
        Route::get('locales/edit_all',         [LocaleController::class, 'editAll'])->name('locales.edit_all');
        Route::post('locales/edit_all/update', [LocaleController::class, 'editAllUpdate'])->name('locales.edit_all.update');
        Route::get('locales/trash',           [LocaleController::class, 'trash'])->name('locales.trash');
        Route::post('locales/{slug}/restore', [LocaleController::class, 'restore'])->name('locales.restore');
        Route::get('locales/{slug}/restore',  fn () => redirect()->route('system_management.locales.trash'));
        Route::resource('locales', LocaleController::class)->names('locales');
        Route::get('locales/{locale}/delete',        [LocaleController::class, 'delete'])->name('locales.delete');
        Route::delete('locales/{locale}/deleteSave', [LocaleController::class, 'deleteSave'])->name('locales.deleteSave');
        Route::post('locales/{locale}/duplicate',    [LocaleController::class, 'duplicate'])->name('locales.duplicate');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('locales/bulk_delete',     [LocaleController::class, 'bulkDelete'])->name('locales.bulk_delete');
            Route::post('locales/bulk_set_active', [LocaleController::class, 'bulkSetActive'])->name('locales.bulk_set_active');
            Route::post('locales/bulk_restore',    [LocaleController::class, 'bulkRestore'])->name('locales.bulk_restore');
        });
        Route::post('locales/undo_last_delete',      [LocaleController::class, 'undoLastDelete'])->name('locales.undo_last_delete');
        Route::delete('locales/{slug}/force_delete', [LocaleController::class, 'forceDelete'])->name('locales.force_delete');

    });
});
