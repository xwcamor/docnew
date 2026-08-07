<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BusinessManagement\WorkPlanController;
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
    Route::middleware('permission:people.edit')->group(function () {
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
});
