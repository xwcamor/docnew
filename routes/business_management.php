<?php

use App\Http\Controllers\BusinessManagement\CustomerHierarchyController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BusinessManagement\ApprovalRuleController;
use App\Http\Controllers\BusinessManagement\ApproverRoleController;
use App\Http\Controllers\BusinessManagement\FormTemplateController;
use App\Http\Controllers\BusinessManagement\WorkPlanController;
use App\Http\Controllers\BusinessManagement\WorkPlanSetupController;
use App\Http\Controllers\BusinessManagement\PersonController;
use App\Http\Controllers\BusinessManagement\CompanyController;
use App\Http\Controllers\BusinessManagement\BrandController;
use App\Http\Controllers\BusinessManagement\CustomerController;
use App\Http\Controllers\BusinessManagement\CommentController;

/*
|--------------------------------------------------------------------------
| Business Management
|--------------------------------------------------------------------------
| Modulos de negocio del SaaS (no del core). Cada modulo se gobierna por
| permisos Spatie: customers.view, customers.create, etc. El admin del
| workspace asigna esos permisos a roles desde el modulo de Perfiles.
|
| Customers es el primer modulo real del SaaS, generado con make:module.
|
| ORDEN DE RUTAS CRITICO: las rutas con paths estaticos (customers/create,
| customers/trash, customers/export_*) DEBEN ir ANTES que customers/{customer}.
| Sin esto, Laravel hace route model binding con customer='create' y 404.
*/

Route::prefix('business_management')->name('business_management.')->group(function () {

    // ── Customers ──

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('customers/trash',                  [CustomerController::class, 'trash'])->name('customers.trash');
        Route::post('customers/bulk_restore',          [CustomerController::class, 'bulkRestore'])->name('customers.bulk_restore');
        Route::post('customers/{slug}/restore',        [CustomerController::class, 'restore'])->name('customers.restore');
        Route::get('customers/{slug}/restore',         fn () => redirect()->route('business_management.customers.trash'));
        Route::delete('customers/{slug}/force_delete', [CustomerController::class, 'forceDelete'])->name('customers.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:customers.view')->group(function () {
        Route::middleware(['throttle:5,1', 'permission:customers.export', 'plan_feature:export_excel'])
            ->post('customers/export_excel', [CustomerController::class, 'exportExcel'])->name('customers.export_excel');
        Route::middleware(['throttle:5,1', 'permission:customers.export', 'plan_feature:export_pdf'])
            ->post('customers/export_pdf',   [CustomerController::class, 'exportPdf'])->name('customers.export_pdf');
        Route::middleware(['throttle:5,1', 'permission:customers.export', 'plan_feature:export_word'])
            ->post('customers/export_word',  [CustomerController::class, 'exportWord'])->name('customers.export_word');
        Route::middleware(['throttle:5,1', 'permission:customers.export']) // export_csv libre en todos los planes
            ->post('customers/export_csv',   [CustomerController::class, 'exportCsv'])->name('customers.export_csv');
    });

    // 3) Imports (gated por plan_feature:bulk_operations)
    Route::middleware(['permission:customers.create', 'permission:customers.import', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('customers/import',          [CustomerController::class, 'import'])->name('customers.import');
        Route::get('customers/import_template',  [CustomerController::class, 'importTemplate'])->name('customers.import_template');
    });

    // 4) Bulk operations (gated por plan_feature:bulk_operations)
    Route::middleware(['permission:customers.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('customers/bulk_delete',     [CustomerController::class, 'bulkDelete'])->name('customers.bulk_delete');
        Route::post('customers/bulk_set_active', [CustomerController::class, 'bulkSetActive'])->name('customers.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window) — gated por permiso de delete.
    Route::middleware('permission:customers.delete')->group(function () {
        Route::post('customers/undo_last_delete', [CustomerController::class, 'undoLastDelete'])->name('customers.undo_last_delete');
    });

    // Edit All — batch edit de name + is_active (gated por permiso de edit).
    Route::middleware('permission:customers.edit')->group(function () {
        Route::get('customers/edit_all',         [CustomerController::class, 'editAll'])->name('customers.edit_all');
        Route::post('customers/edit_all/update', [CustomerController::class, 'editAllUpdate'])->name('customers.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO (create), despues los con {customer}.
    Route::middleware('permission:customers.create')->group(function () {
        Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('customers',       [CustomerController::class, 'store'])->name('customers.store');
        // Alta rápida JSON desde otros módulos (ej. select de cliente en el form de trafos).
        Route::post('customers/quick_store', [CustomerController::class, 'quickStore'])->name('customers.quick_store');
        Route::post('customers/{customer}/duplicate', [CustomerController::class, 'duplicate'])->name('customers.duplicate');
    });

    // Acciones con slug — DESPUES de los paths estaticos.
    Route::middleware('permission:customers.view')->group(function () {
        Route::get('customers',            [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    });
    Route::middleware('permission:customers.edit')->group(function () {
        Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::put('customers/{customer}',      [CustomerController::class, 'update'])->name('customers.update');

        // Jerarquía del cliente (Ubicación/Área/Subestación) desde el árbol.
    });
    Route::middleware('permission:customers.delete')->group(function () {
        Route::get('customers/{customer}/delete',        [CustomerController::class, 'delete'])->name('customers.delete');
        Route::delete('customers/{customer}/deleteSave', [CustomerController::class, 'deleteSave'])->name('customers.deleteSave');
    });

    // Bloquear/desbloquear registro (Lockable) — solo super|admin. El nivel del
    // candado y quién puede sacarlo se resuelve en el controller (HandlesRecordLocking).
    Route::middleware('role:super|admin')->group(function () {
        Route::post('customers/{customer}/lock',   [CustomerController::class, 'lock'])->name('customers.lock');
        Route::post('customers/{customer}/unlock', [CustomerController::class, 'unlock'])->name('customers.unlock');
    });


    // ── Brands ──
    // Bloque generado por make:module. Reordena o ajusta permisos según tu dominio.

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('brands/trash',                  [BrandController::class, 'trash'])->name('brands.trash');
        Route::post('brands/bulk_restore',          [BrandController::class, 'bulkRestore'])->name('brands.bulk_restore');
        Route::post('brands/{slug}/restore',        [BrandController::class, 'restore'])->name('brands.restore');
        Route::get('brands/{slug}/restore',         fn () => redirect()->route('business_management.brands.trash'));
        Route::delete('brands/{slug}/force_delete', [BrandController::class, 'forceDelete'])->name('brands.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:brands.view')->group(function () {
        Route::middleware(['throttle:5,1', 'permission:brands.export', 'plan_feature:export_excel'])
            ->post('brands/export_excel', [BrandController::class, 'exportExcel'])->name('brands.export_excel');
        Route::middleware(['throttle:5,1', 'permission:brands.export', 'plan_feature:export_pdf'])
            ->post('brands/export_pdf',   [BrandController::class, 'exportPdf'])->name('brands.export_pdf');
        Route::middleware(['throttle:5,1', 'permission:brands.export', 'plan_feature:export_word'])
            ->post('brands/export_word',  [BrandController::class, 'exportWord'])->name('brands.export_word');
        Route::middleware(['throttle:5,1', 'permission:brands.export'])
            ->post('brands/export_csv',   [BrandController::class, 'exportCsv'])->name('brands.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:brands.create', 'permission:brands.import', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('brands/import',          [BrandController::class, 'import'])->name('brands.import');
        Route::get('brands/import_template',  [BrandController::class, 'importTemplate'])->name('brands.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:brands.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('brands/bulk_delete',     [BrandController::class, 'bulkDelete'])->name('brands.bulk_delete');
        Route::post('brands/bulk_set_active', [BrandController::class, 'bulkSetActive'])->name('brands.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:brands.delete')->group(function () {
        Route::post('brands/undo_last_delete', [BrandController::class, 'undoLastDelete'])->name('brands.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:brands.edit')->group(function () {
        Route::get('brands/edit_all',         [BrandController::class, 'editAll'])->name('brands.edit_all');
        Route::post('brands/edit_all/update', [BrandController::class, 'editAllUpdate'])->name('brands.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:brands.create')->group(function () {
        Route::get('brands/create', [BrandController::class, 'create'])->name('brands.create');
        Route::post('brands',       [BrandController::class, 'store'])->name('brands.store');
        // Alta rápida JSON desde otros módulos (ej. select de marca en el form de trafos).
        Route::post('brands/quick_store', [BrandController::class, 'quickStore'])->name('brands.quick_store');
        Route::post('brands/{brand}/duplicate', [BrandController::class, 'duplicate'])->name('brands.duplicate');
    });

    Route::middleware('permission:brands.view')->group(function () {
        Route::get('brands',                [BrandController::class, 'index'])->name('brands.index');
        Route::get('brands/{brand}',  [BrandController::class, 'show'])->name('brands.show');
    });
    Route::middleware('permission:brands.edit')->group(function () {
        Route::get('brands/{brand}/edit', [BrandController::class, 'edit'])->name('brands.edit');
        Route::put('brands/{brand}',      [BrandController::class, 'update'])->name('brands.update');
    });
    Route::middleware('permission:brands.delete')->group(function () {
        Route::get('brands/{brand}/delete',        [BrandController::class, 'delete'])->name('brands.delete');
        Route::delete('brands/{brand}/deleteSave', [BrandController::class, 'deleteSave'])->name('brands.deleteSave');
    });
    // Bloquear/desbloquear (Lockable) — solo super|admin.
    Route::middleware('role:super|admin')->group(function () {
        Route::post('brands/{brand}/lock',   [BrandController::class, 'lock'])->name('brands.lock');
        Route::post('brands/{brand}/unlock', [BrandController::class, 'unlock'])->name('brands.unlock');
    });


    // ── Companies ──
    // Bloque generado por make:module. Reordena o ajusta permisos según tu dominio.

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('companies/trash',                  [CompanyController::class, 'trash'])->name('companies.trash');
        Route::post('companies/bulk_restore',          [CompanyController::class, 'bulkRestore'])->name('companies.bulk_restore');
        Route::post('companies/{slug}/restore',        [CompanyController::class, 'restore'])->name('companies.restore');
        Route::get('companies/{slug}/restore',         fn () => redirect()->route('business_management.companies.trash'));
        Route::delete('companies/{slug}/force_delete', [CompanyController::class, 'forceDelete'])->name('companies.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:companies.view')->group(function () {
        Route::middleware(['throttle:5,1', 'plan_feature:export_excel'])
            ->post('companies/export_excel', [CompanyController::class, 'exportExcel'])->name('companies.export_excel');
        Route::middleware(['throttle:5,1', 'plan_feature:export_pdf'])
            ->post('companies/export_pdf',   [CompanyController::class, 'exportPdf'])->name('companies.export_pdf');
        Route::middleware(['throttle:5,1', 'plan_feature:export_word'])
            ->post('companies/export_word',  [CompanyController::class, 'exportWord'])->name('companies.export_word');
        Route::middleware('throttle:5,1')
            ->post('companies/export_csv',   [CompanyController::class, 'exportCsv'])->name('companies.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:companies.create', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('companies/import',          [CompanyController::class, 'import'])->name('companies.import');
        Route::get('companies/import_template',  [CompanyController::class, 'importTemplate'])->name('companies.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:companies.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('companies/bulk_delete',     [CompanyController::class, 'bulkDelete'])->name('companies.bulk_delete');
        Route::post('companies/bulk_set_active', [CompanyController::class, 'bulkSetActive'])->name('companies.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:companies.delete')->group(function () {
        Route::post('companies/undo_last_delete', [CompanyController::class, 'undoLastDelete'])->name('companies.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:companies.edit')->group(function () {
        Route::get('companies/edit_all',         [CompanyController::class, 'editAll'])->name('companies.edit_all');
        Route::post('companies/edit_all/update', [CompanyController::class, 'editAllUpdate'])->name('companies.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:companies.create')->group(function () {
        Route::get('companies/create', [CompanyController::class, 'create'])->name('companies.create');
        Route::post('companies',       [CompanyController::class, 'store'])->name('companies.store');
        Route::post('companies/{company}/duplicate', [CompanyController::class, 'duplicate'])->name('companies.duplicate');
    });

    Route::middleware('permission:companies.view')->group(function () {
        Route::get('companies',                [CompanyController::class, 'index'])->name('companies.index');
        Route::get('companies/{company}',  [CompanyController::class, 'show'])->name('companies.show');
    });
    Route::middleware('permission:companies.edit')->group(function () {
        Route::get('companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
        Route::put('companies/{company}',      [CompanyController::class, 'update'])->name('companies.update');
    });
    Route::middleware('permission:companies.delete')->group(function () {
        Route::get('companies/{company}/delete',        [CompanyController::class, 'delete'])->name('companies.delete');
        Route::delete('companies/{company}/deleteSave', [CompanyController::class, 'deleteSave'])->name('companies.deleteSave');
    });

    // Bloquear/desbloquear (Lockable) — solo super|admin.
    Route::middleware('role:super|admin')->group(function () {
        Route::post('companies/{company}/lock',   [CompanyController::class, 'lock'])->name('companies.lock');
        Route::post('companies/{company}/unlock', [CompanyController::class, 'unlock'])->name('companies.unlock');
    });


    // ── People ──
    // Bloque generado por make:module. Reordena o ajusta permisos según tu dominio.

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('people/trash',                  [PersonController::class, 'trash'])->name('people.trash');
        Route::post('people/bulk_restore',          [PersonController::class, 'bulkRestore'])->name('people.bulk_restore');
        Route::post('people/{slug}/restore',        [PersonController::class, 'restore'])->name('people.restore');
        Route::get('people/{slug}/restore',         fn () => redirect()->route('business_management.people.trash'));
        Route::delete('people/{slug}/force_delete', [PersonController::class, 'forceDelete'])->name('people.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:people.view')->group(function () {
        Route::middleware(['throttle:5,1', 'plan_feature:export_excel'])
            ->post('people/export_excel', [PersonController::class, 'exportExcel'])->name('people.export_excel');
        Route::middleware(['throttle:5,1', 'plan_feature:export_pdf'])
            ->post('people/export_pdf',   [PersonController::class, 'exportPdf'])->name('people.export_pdf');
        Route::middleware(['throttle:5,1', 'plan_feature:export_word'])
            ->post('people/export_word',  [PersonController::class, 'exportWord'])->name('people.export_word');
        Route::middleware('throttle:5,1')
            ->post('people/export_csv',   [PersonController::class, 'exportCsv'])->name('people.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:people.create', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('people/import',          [PersonController::class, 'import'])->name('people.import');
        Route::get('people/import_template',  [PersonController::class, 'importTemplate'])->name('people.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:people.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('people/bulk_delete',     [PersonController::class, 'bulkDelete'])->name('people.bulk_delete');
        Route::post('people/bulk_set_active', [PersonController::class, 'bulkSetActive'])->name('people.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:people.delete')->group(function () {
        Route::post('people/undo_last_delete', [PersonController::class, 'undoLastDelete'])->name('people.undo_last_delete');
    });

    // Edit All
    // Pide ADEMAS `people.view_private_info`: esta pantalla edita el documento
    // de identidad, asi que tiene que enseñarlo entero — enmascarado no se
    // podria corregir. Es la unica de personas que manda el `num_doc` en crudo,
    // y por eso es la unica que exige el permiso de datos privados.
    Route::middleware(['permission:people.edit', 'permission:people.view_private_info'])->group(function () {
        Route::get('people/edit_all',         [PersonController::class, 'editAll'])->name('people.edit_all');
        Route::post('people/edit_all/update', [PersonController::class, 'editAllUpdate'])->name('people.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:people.create')->group(function () {
        Route::get('people/create', [PersonController::class, 'create'])->name('people.create');
        Route::post('people',       [PersonController::class, 'store'])->name('people.store');
        Route::post('people/{person}/duplicate', [PersonController::class, 'duplicate'])->name('people.duplicate');
    });

    Route::middleware('permission:people.view')->group(function () {
        Route::get('people',                [PersonController::class, 'index'])->name('people.index');
        Route::get('people/{person}',  [PersonController::class, 'show'])->name('people.show');
    });
    Route::middleware('permission:people.edit')->group(function () {
        Route::get('people/{person}/edit', [PersonController::class, 'edit'])->name('people.edit');
        Route::put('people/{person}',      [PersonController::class, 'update'])->name('people.update');
    });
    Route::middleware('permission:people.delete')->group(function () {
        Route::get('people/{person}/delete',        [PersonController::class, 'delete'])->name('people.delete');
        Route::delete('people/{person}/deleteSave', [PersonController::class, 'deleteSave'])->name('people.deleteSave');
    });

    // Bloquear/desbloquear (Lockable) — solo super|admin.
    Route::middleware('role:super|admin')->group(function () {
        Route::post('people/{person}/lock',   [PersonController::class, 'lock'])->name('people.lock');
        Route::post('people/{person}/unlock', [PersonController::class, 'unlock'])->name('people.unlock');
    });


    // ── WorkPlans ──
    // Bloque generado por make:module. Reordena o ajusta permisos según tu dominio.

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('work_plans/trash',                  [WorkPlanController::class, 'trash'])->name('work_plans.trash');
        Route::post('work_plans/bulk_restore',          [WorkPlanController::class, 'bulkRestore'])->name('work_plans.bulk_restore');
        Route::post('work_plans/{slug}/restore',        [WorkPlanController::class, 'restore'])->name('work_plans.restore');
        Route::get('work_plans/{slug}/restore',         fn () => redirect()->route('business_management.work_plans.trash'));
        Route::delete('work_plans/{slug}/force_delete', [WorkPlanController::class, 'forceDelete'])->name('work_plans.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:work_plans.view')->group(function () {
        Route::middleware(['throttle:5,1', 'plan_feature:export_excel'])
            ->post('work_plans/export_excel', [WorkPlanController::class, 'exportExcel'])->name('work_plans.export_excel');
        Route::middleware(['throttle:5,1', 'plan_feature:export_pdf'])
            ->post('work_plans/export_pdf',   [WorkPlanController::class, 'exportPdf'])->name('work_plans.export_pdf');
        Route::middleware(['throttle:5,1', 'plan_feature:export_word'])
            ->post('work_plans/export_word',  [WorkPlanController::class, 'exportWord'])->name('work_plans.export_word');
        Route::middleware('throttle:5,1')
            ->post('work_plans/export_csv',   [WorkPlanController::class, 'exportCsv'])->name('work_plans.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:work_plans.create', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('work_plans/import',          [WorkPlanController::class, 'import'])->name('work_plans.import');
        Route::get('work_plans/import_template',  [WorkPlanController::class, 'importTemplate'])->name('work_plans.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:work_plans.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('work_plans/bulk_delete',     [WorkPlanController::class, 'bulkDelete'])->name('work_plans.bulk_delete');
        Route::post('work_plans/bulk_set_active', [WorkPlanController::class, 'bulkSetActive'])->name('work_plans.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:work_plans.delete')->group(function () {
        Route::post('work_plans/undo_last_delete', [WorkPlanController::class, 'undoLastDelete'])->name('work_plans.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:work_plans.edit')->group(function () {
        Route::get('work_plans/edit_all',         [WorkPlanController::class, 'editAll'])->name('work_plans.edit_all');
        Route::post('work_plans/edit_all/update', [WorkPlanController::class, 'editAllUpdate'])->name('work_plans.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:work_plans.create')->group(function () {
        Route::get('work_plans/create', [WorkPlanController::class, 'create'])->name('work_plans.create');
        Route::post('work_plans',       [WorkPlanController::class, 'store'])->name('work_plans.store');
        Route::post('work_plans/{workPlan}/duplicate', [WorkPlanController::class, 'duplicate'])->name('work_plans.duplicate');
    });

    Route::middleware('permission:work_plans.view')->group(function () {
        Route::get('work_plans',                [WorkPlanController::class, 'index'])->name('work_plans.index');
        Route::get('work_plans/{workPlan}',  [WorkPlanController::class, 'show'])->name('work_plans.show');
    });
    Route::middleware('permission:work_plans.edit')->group(function () {
        Route::get('work_plans/{workPlan}/edit', [WorkPlanController::class, 'edit'])->name('work_plans.edit');
        Route::put('work_plans/{workPlan}',      [WorkPlanController::class, 'update'])->name('work_plans.update');
    });
    Route::middleware('permission:work_plans.delete')->group(function () {
        Route::get('work_plans/{workPlan}/delete',        [WorkPlanController::class, 'delete'])->name('work_plans.delete');
        Route::delete('work_plans/{workPlan}/deleteSave', [WorkPlanController::class, 'deleteSave'])->name('work_plans.deleteSave');
    });

    // Bloquear/desbloquear (Lockable) — solo super|admin.
    Route::middleware('role:super|admin')->group(function () {
        Route::post('work_plans/{workPlan}/lock',   [WorkPlanController::class, 'lock'])->name('work_plans.lock');
        Route::post('work_plans/{workPlan}/unlock', [WorkPlanController::class, 'unlock'])->name('work_plans.unlock');
    });

    // 6) Composición del plan: cuadrilla, formatos exigidos y aprobadores.
    // Va con work_plans.edit y no con los permisos de campo: armar el plan es
    // trabajo del supervisor. El usuario de campo llena y firma lo que ya está
    // armado; no decide quién entra a la obra ni qué formatos se exigen.
    // Los hijos se enlazan por slug, como todo el resto: el id es correlativo.
    // `withoutScopedBindings` porque el scoping automático de Laravel buscaría
    // relaciones llamadas `workPlanPeople`/`workPlanApprovals`, y aquí se
    // llaman `people` y `approvals`. Que el hijo sea de ESTE plan lo comprueba
    // el servicio, que además es donde vale para las llamadas internas.
    Route::middleware('permission:work_plans.edit')->group(function () {
        Route::get('work_plans/{workPlan}/crew/candidates',              [WorkPlanSetupController::class, 'personCandidates'])->name('work_plans.crew.candidates');
        Route::post('work_plans/{workPlan}/crew',                        [WorkPlanSetupController::class, 'addPerson'])->name('work_plans.crew.store');
        Route::delete('work_plans/{workPlan}/crew/{workPlanPerson:slug}', [WorkPlanSetupController::class, 'removePerson'])->name('work_plans.crew.destroy')->withoutScopedBindings();

        Route::post('work_plans/{workPlan}/forms',                  [WorkPlanSetupController::class, 'addForm'])->name('work_plans.forms.store');
        Route::delete('work_plans/{workPlan}/forms/{formTemplate}', [WorkPlanSetupController::class, 'removeForm'])->name('work_plans.forms.destroy');

        // Las aprobaciones NO se crean ni se borran desde la ficha: las genera
        // la regla del pais al nacer el plan (WorkPlanSetupService::
        // seedApprovalsFromRules) y pertenecen al flujo. Lo unico que falta es
        // quien las firma, y para eso esta esta ruta. Si el flujo cambia, se
        // cambia en `approval_rules`, que es donde se decide.
        Route::post('work_plans/{workPlan}/approvals',                           [WorkPlanSetupController::class, 'addApproval'])->name('work_plans.approvals.store');
        Route::put('work_plans/{workPlan}/approvals/{workPlanApproval:slug}/approver', [WorkPlanSetupController::class, 'assignApprover'])->name('work_plans.approvals.approver')->withoutScopedBindings();
    });


    // ── FormTemplates ──
    // Bloque generado por make:module. Reordena o ajusta permisos según tu dominio.

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('form_templates/trash',                  [FormTemplateController::class, 'trash'])->name('form_templates.trash');
        Route::post('form_templates/bulk_restore',          [FormTemplateController::class, 'bulkRestore'])->name('form_templates.bulk_restore');
        Route::post('form_templates/{slug}/restore',        [FormTemplateController::class, 'restore'])->name('form_templates.restore');
        Route::get('form_templates/{slug}/restore',         fn () => redirect()->route('business_management.form_templates.trash'));
        Route::delete('form_templates/{slug}/force_delete', [FormTemplateController::class, 'forceDelete'])->name('form_templates.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:form_templates.view')->group(function () {
        Route::middleware(['throttle:5,1', 'plan_feature:export_excel'])
            ->post('form_templates/export_excel', [FormTemplateController::class, 'exportExcel'])->name('form_templates.export_excel');
        Route::middleware(['throttle:5,1', 'plan_feature:export_pdf'])
            ->post('form_templates/export_pdf',   [FormTemplateController::class, 'exportPdf'])->name('form_templates.export_pdf');
        Route::middleware(['throttle:5,1', 'plan_feature:export_word'])
            ->post('form_templates/export_word',  [FormTemplateController::class, 'exportWord'])->name('form_templates.export_word');
        Route::middleware('throttle:5,1')
            ->post('form_templates/export_csv',   [FormTemplateController::class, 'exportCsv'])->name('form_templates.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:form_templates.create', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('form_templates/import',          [FormTemplateController::class, 'import'])->name('form_templates.import');
        Route::get('form_templates/import_template',  [FormTemplateController::class, 'importTemplate'])->name('form_templates.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:form_templates.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('form_templates/bulk_delete',     [FormTemplateController::class, 'bulkDelete'])->name('form_templates.bulk_delete');
        Route::post('form_templates/bulk_set_active', [FormTemplateController::class, 'bulkSetActive'])->name('form_templates.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:form_templates.delete')->group(function () {
        Route::post('form_templates/undo_last_delete', [FormTemplateController::class, 'undoLastDelete'])->name('form_templates.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:form_templates.edit')->group(function () {
        Route::get('form_templates/edit_all',         [FormTemplateController::class, 'editAll'])->name('form_templates.edit_all');
        Route::post('form_templates/edit_all/update', [FormTemplateController::class, 'editAllUpdate'])->name('form_templates.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:form_templates.create')->group(function () {
        Route::get('form_templates/create', [FormTemplateController::class, 'create'])->name('form_templates.create');
        Route::post('form_templates',       [FormTemplateController::class, 'store'])->name('form_templates.store');
        Route::post('form_templates/{formTemplate}/duplicate', [FormTemplateController::class, 'duplicate'])->name('form_templates.duplicate');
    });

    Route::middleware('permission:form_templates.view')->group(function () {
        Route::get('form_templates',                [FormTemplateController::class, 'index'])->name('form_templates.index');
        Route::get('form_templates/{formTemplate}',  [FormTemplateController::class, 'show'])->name('form_templates.show');
    });
    Route::middleware('permission:form_templates.edit')->group(function () {
        Route::get('form_templates/{formTemplate}/edit', [FormTemplateController::class, 'edit'])->name('form_templates.edit');
        Route::put('form_templates/{formTemplate}',      [FormTemplateController::class, 'update'])->name('form_templates.update');
    });
    Route::middleware('permission:form_templates.delete')->group(function () {
        Route::get('form_templates/{formTemplate}/delete',        [FormTemplateController::class, 'delete'])->name('form_templates.delete');
        Route::delete('form_templates/{formTemplate}/deleteSave', [FormTemplateController::class, 'deleteSave'])->name('form_templates.deleteSave');
    });

    // Bloquear/desbloquear (Lockable) — solo super|admin.
    Route::middleware('role:super|admin')->group(function () {
        Route::post('form_templates/{formTemplate}/lock',   [FormTemplateController::class, 'lock'])->name('form_templates.lock');
        Route::post('form_templates/{formTemplate}/unlock', [FormTemplateController::class, 'unlock'])->name('form_templates.unlock');
    });
    // ── Comentarios (polimórfico: transformer + muestras de cada prueba) ──
    // Texto libre del usuario, con autor + fecha. Ver/crear/borrar se gatean por
    // permiso (comments.*) para que el admin decida qué perfiles comentan.
    Route::middleware('permission:comments.view')->group(function () {
        // POST (no GET) para esquivar el redirect de localización en peticiones GET.
        Route::post('comments/list', [CommentController::class, 'index'])->name('comments.index');
    });
    // Crear: la "Nota del diagnosticador" (commentable = transformer) exige
    // diagnosis_notes.create; los comentarios POR MUESTRA exigen comments.create.
    // El middleware deja pasar a quien tenga CUALQUIERA de los dos; el controller
    // (store) hace valer el permiso correcto según el tipo de objeto comentado.
    Route::middleware('permission:comments.create')->group(function () {
        Route::post('comments', [CommentController::class, 'store'])->name('comments.store');
    });
    Route::middleware('permission:comments.delete')->group(function () {
        Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
    });


        Route::post('customers/{customer}/hierarchy', [CustomerHierarchyController::class, 'store'])->name('customers.hierarchy.store');
        Route::put('customers/{customer}/hierarchy/{level}/{id}', [CustomerHierarchyController::class, 'update'])->name('customers.hierarchy.update');
        Route::delete('customers/{customer}/hierarchy/{level}/{id}', [CustomerHierarchyController::class, 'destroy'])->name('customers.hierarchy.destroy');
        Route::post('customers/{customer}/hierarchy/{level}/{id}/restore', [CustomerHierarchyController::class, 'restore'])->name('customers.hierarchy.restore');


    // ── ApproverRoles ── catalogo de quien puede firmar un plan.
    // Sin lock ni duplicar: la tabla no tiene columnas de candado y clonar un
    // codigo unico no tiene sentido — se crea uno nuevo y ya.

    // 1) Papelera + restaurar + borrado definitivo (solo super)
    Route::middleware('role:super')->group(function () {
        Route::get('approver_roles/trash',                  [ApproverRoleController::class, 'trash'])->name('approver_roles.trash');
        Route::post('approver_roles/bulk_restore',          [ApproverRoleController::class, 'bulkRestore'])->name('approver_roles.bulk_restore');
        Route::post('approver_roles/{slug}/restore',        [ApproverRoleController::class, 'restore'])->name('approver_roles.restore');
        Route::get('approver_roles/{slug}/restore',         fn () => redirect()->route('business_management.approver_roles.trash'));
        Route::delete('approver_roles/{slug}/force_delete', [ApproverRoleController::class, 'forceDelete'])->name('approver_roles.force_delete');
    });

    // 2) Exportar (cada formato con su feature de plan)
    Route::middleware('permission:approver_roles.view')->group(function () {
        Route::middleware(['throttle:5,1', 'permission:approver_roles.export', 'plan_feature:export_excel'])
            ->post('approver_roles/export_excel', [ApproverRoleController::class, 'exportExcel'])->name('approver_roles.export_excel');
        Route::middleware(['throttle:5,1', 'permission:approver_roles.export', 'plan_feature:export_pdf'])
            ->post('approver_roles/export_pdf',   [ApproverRoleController::class, 'exportPdf'])->name('approver_roles.export_pdf');
        Route::middleware(['throttle:5,1', 'permission:approver_roles.export', 'plan_feature:export_word'])
            ->post('approver_roles/export_word',  [ApproverRoleController::class, 'exportWord'])->name('approver_roles.export_word');
        Route::middleware(['throttle:5,1', 'permission:approver_roles.export'])
            ->post('approver_roles/export_csv',   [ApproverRoleController::class, 'exportCsv'])->name('approver_roles.export_csv');
    });

    // 3) Importar
    Route::middleware(['permission:approver_roles.create', 'permission:approver_roles.import', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('approver_roles/import',          [ApproverRoleController::class, 'import'])->name('approver_roles.import');
        Route::get('approver_roles/import_template',  [ApproverRoleController::class, 'importTemplate'])->name('approver_roles.import_template');
    });

    // 4) Masivas
    Route::middleware(['permission:approver_roles.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('approver_roles/bulk_delete',     [ApproverRoleController::class, 'bulkDelete'])->name('approver_roles.bulk_delete');
        Route::post('approver_roles/bulk_set_active', [ApproverRoleController::class, 'bulkSetActive'])->name('approver_roles.bulk_set_active');
    });

    // Deshacer el ultimo borrado (ventana de 60s)
    Route::middleware('permission:approver_roles.delete')->group(function () {
        Route::post('approver_roles/undo_last_delete', [ApproverRoleController::class, 'undoLastDelete'])->name('approver_roles.undo_last_delete');
    });

    // Editar en masa
    Route::middleware('permission:approver_roles.edit')->group(function () {
        Route::get('approver_roles/edit_all',         [ApproverRoleController::class, 'editAll'])->name('approver_roles.edit_all');
        Route::post('approver_roles/edit_all/update', [ApproverRoleController::class, 'editAllUpdate'])->name('approver_roles.edit_all.update');
    });

    // Desactivar desde el aviso de "esta en uso" — es la salida que se le
    // ofrece al usuario cuando el borrado se rechaza, y por eso va con edit.
    Route::middleware('permission:approver_roles.edit')->group(function () {
        Route::post('approver_roles/{approverRole}/deactivate', [ApproverRoleController::class, 'deactivate'])->name('approver_roles.deactivate');
    });

    // 5) CRUD — rutas de path fijo ANTES de las que llevan {approverRole}.
    Route::middleware('permission:approver_roles.create')->group(function () {
        Route::get('approver_roles/create', [ApproverRoleController::class, 'create'])->name('approver_roles.create');
        Route::post('approver_roles',       [ApproverRoleController::class, 'store'])->name('approver_roles.store');
    });

    Route::middleware('permission:approver_roles.view')->group(function () {
        Route::get('approver_roles',                 [ApproverRoleController::class, 'index'])->name('approver_roles.index');
        Route::get('approver_roles/{approverRole}',  [ApproverRoleController::class, 'show'])->name('approver_roles.show');
    });
    Route::middleware('permission:approver_roles.edit')->group(function () {
        Route::get('approver_roles/{approverRole}/edit', [ApproverRoleController::class, 'edit'])->name('approver_roles.edit');
        Route::put('approver_roles/{approverRole}',      [ApproverRoleController::class, 'update'])->name('approver_roles.update');
    });
    Route::middleware('permission:approver_roles.delete')->group(function () {
        Route::get('approver_roles/{approverRole}/delete',        [ApproverRoleController::class, 'delete'])->name('approver_roles.delete');
        Route::delete('approver_roles/{approverRole}/deleteSave', [ApproverRoleController::class, 'deleteSave'])->name('approver_roles.deleteSave');
    });


    // ── ApprovalRules ── que firmas exige un plan antes de darse por aprobado.
    // Sin lock ni duplicar, por lo mismo que ApproverRoles.

    // 1) Papelera + restaurar + borrado definitivo (solo super)
    Route::middleware('role:super')->group(function () {
        Route::get('approval_rules/trash',                  [ApprovalRuleController::class, 'trash'])->name('approval_rules.trash');
        Route::post('approval_rules/bulk_restore',          [ApprovalRuleController::class, 'bulkRestore'])->name('approval_rules.bulk_restore');
        Route::post('approval_rules/{slug}/restore',        [ApprovalRuleController::class, 'restore'])->name('approval_rules.restore');
        Route::get('approval_rules/{slug}/restore',         fn () => redirect()->route('business_management.approval_rules.trash'));
        Route::delete('approval_rules/{slug}/force_delete', [ApprovalRuleController::class, 'forceDelete'])->name('approval_rules.force_delete');
    });

    // 2) Exportar
    Route::middleware('permission:approval_rules.view')->group(function () {
        Route::middleware(['throttle:5,1', 'permission:approval_rules.export', 'plan_feature:export_excel'])
            ->post('approval_rules/export_excel', [ApprovalRuleController::class, 'exportExcel'])->name('approval_rules.export_excel');
        Route::middleware(['throttle:5,1', 'permission:approval_rules.export', 'plan_feature:export_pdf'])
            ->post('approval_rules/export_pdf',   [ApprovalRuleController::class, 'exportPdf'])->name('approval_rules.export_pdf');
        Route::middleware(['throttle:5,1', 'permission:approval_rules.export', 'plan_feature:export_word'])
            ->post('approval_rules/export_word',  [ApprovalRuleController::class, 'exportWord'])->name('approval_rules.export_word');
        Route::middleware(['throttle:5,1', 'permission:approval_rules.export'])
            ->post('approval_rules/export_csv',   [ApprovalRuleController::class, 'exportCsv'])->name('approval_rules.export_csv');
    });

    // 3) Importar
    Route::middleware(['permission:approval_rules.create', 'permission:approval_rules.import', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('approval_rules/import',          [ApprovalRuleController::class, 'import'])->name('approval_rules.import');
        Route::get('approval_rules/import_template',  [ApprovalRuleController::class, 'importTemplate'])->name('approval_rules.import_template');
    });

    // 4) Masivas
    Route::middleware(['permission:approval_rules.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('approval_rules/bulk_delete',     [ApprovalRuleController::class, 'bulkDelete'])->name('approval_rules.bulk_delete');
        Route::post('approval_rules/bulk_set_active', [ApprovalRuleController::class, 'bulkSetActive'])->name('approval_rules.bulk_set_active');
    });

    Route::middleware('permission:approval_rules.delete')->group(function () {
        Route::post('approval_rules/undo_last_delete', [ApprovalRuleController::class, 'undoLastDelete'])->name('approval_rules.undo_last_delete');
    });

    Route::middleware('permission:approval_rules.edit')->group(function () {
        Route::get('approval_rules/edit_all',         [ApprovalRuleController::class, 'editAll'])->name('approval_rules.edit_all');
        Route::post('approval_rules/edit_all/update', [ApprovalRuleController::class, 'editAllUpdate'])->name('approval_rules.edit_all.update');
    });

    // Vista previa del flujo: "para IZAJE quedan estas 4 firmas, en este orden".
    // Sin esto el usuario configura a ciegas, porque la regla que ve en la
    // tabla no es la que se aplica si el tipo de trabajo tiene reglas propias.
    Route::middleware('permission:approval_rules.view')->group(function () {
        Route::get('approval_rules/preview', [ApprovalRuleController::class, 'preview'])->name('approval_rules.preview');
    });

    // 5) CRUD — rutas de path fijo ANTES de las que llevan {approvalRule}.
    Route::middleware('permission:approval_rules.create')->group(function () {
        Route::get('approval_rules/create', [ApprovalRuleController::class, 'create'])->name('approval_rules.create');
        Route::post('approval_rules',       [ApprovalRuleController::class, 'store'])->name('approval_rules.store');
    });

    Route::middleware('permission:approval_rules.view')->group(function () {
        Route::get('approval_rules',                 [ApprovalRuleController::class, 'index'])->name('approval_rules.index');
        Route::get('approval_rules/{approvalRule}',  [ApprovalRuleController::class, 'show'])->name('approval_rules.show');
    });
    Route::middleware('permission:approval_rules.edit')->group(function () {
        Route::get('approval_rules/{approvalRule}/edit', [ApprovalRuleController::class, 'edit'])->name('approval_rules.edit');
        Route::put('approval_rules/{approvalRule}',      [ApprovalRuleController::class, 'update'])->name('approval_rules.update');
    });
    Route::middleware('permission:approval_rules.delete')->group(function () {
        Route::get('approval_rules/{approvalRule}/delete',        [ApprovalRuleController::class, 'delete'])->name('approval_rules.delete');
        Route::delete('approval_rules/{approvalRule}/deleteSave', [ApprovalRuleController::class, 'deleteSave'])->name('approval_rules.deleteSave');
    });
});
