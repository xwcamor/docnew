<?php

use App\Http\Controllers\AutomationManagement\AutomationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Automation Management — per-tenant
|--------------------------------------------------------------------------
| Triple gate:
|   1. auth (definido en el group padre de web.php)
|   2. role:super|admin → solo el dueño del workspace (admin) o la
|      plataforma (super) gestiona automations. Los workers (roles
|      custom como "Customer Editor") NO entran, aunque su tenant tenga
|      el plan_feature activo — son "Equipos de trabajo", no del worker.
|   3. plan_feature:automations → solo planes con la feature activa.
|
| Las acciones críticas (trash, restore, force_delete) están dentro del
| middleware role:super como defense in depth.
*/

Route::prefix('automation_management')
    ->name('automation_management.')
    ->middleware(['role:super|admin', 'plan_feature:automations'])
    ->group(function () {

        // Trash + restore + force_delete (super only).
        Route::middleware('role:super')->group(function () {
            Route::get('automations/trash',                  [AutomationController::class, 'trash'])->name('automations.trash');
            Route::post('automations/{automation}/restore',  [AutomationController::class, 'restore'])->name('automations.restore');
            Route::get('automations/{automation}/restore',   fn () => redirect()->route('automation_management.automations.trash'));
            Route::delete('automations/{automation}/force_delete', [AutomationController::class, 'forceDelete'])->name('automations.force_delete');

            // Bulk restore vive en el grupo super (defense-in-depth).
            Route::middleware('throttle:10,1')->group(function () {
                Route::post('automations/bulk_restore', [AutomationController::class, 'bulkRestore'])->name('automations.bulk_restore');
            });
        });

        // Edit-all (batch edit name + is_active).
        Route::get('automations/edit_all',         [AutomationController::class, 'editAll'])->name('automations.edit_all');
        Route::post('automations/edit_all/update', [AutomationController::class, 'editAllUpdate'])->name('automations.edit_all.update');

        // Exports — gated por plan_feature por formato. CSV es libre en todos
        // los planes (streaming, sin limites). Excel/PDF/Word requieren plan
        // features explicitas. Throttle 5/min cubre uso humano sin DoS.
        Route::middleware(['throttle:5,1', 'plan_feature:export_excel'])
            ->post('automations/export_excel', [AutomationController::class, 'exportExcel'])->name('automations.export_excel');
        Route::middleware(['throttle:5,1', 'plan_feature:export_pdf'])
            ->post('automations/export_pdf',   [AutomationController::class, 'exportPdf'])->name('automations.export_pdf');
        Route::middleware(['throttle:5,1', 'plan_feature:export_word'])
            ->post('automations/export_word',  [AutomationController::class, 'exportWord'])->name('automations.export_word');
        Route::middleware('throttle:5,1')
            ->post('automations/export_csv',   [AutomationController::class, 'exportCsv'])->name('automations.export_csv');

        // Imports — gated por plan_feature:bulk_operations (mismo que customers).
        Route::middleware('plan_feature:bulk_operations')->group(function () {
            Route::post('automations/import',         [AutomationController::class, 'import'])->name('automations.import');
            Route::get('automations/import_template', [AutomationController::class, 'importTemplate'])->name('automations.import_template');
        });

        // Undo de borrado (60s window). Sin throttle — el claim caduca solo.
        Route::post('automations/undo_last_delete', [AutomationController::class, 'undoLastDelete'])->name('automations.undo_last_delete');

        // Bulk endpoints — throttle estricto. Cada request puede afectar hasta
        // 500 IDs (max validation). 10/min sigue siendo holgado para uso humano.
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('automations/bulk_delete',     [AutomationController::class, 'bulkDelete'])->name('automations.bulk_delete');
            Route::post('automations/bulk_set_active', [AutomationController::class, 'bulkSetActive'])->name('automations.bulk_set_active');
        });

        // CRUD principal.
        Route::get('automations',                       [AutomationController::class, 'index'])->name('automations.index');
        Route::get('automations/create',                [AutomationController::class, 'create'])->name('automations.create');
        Route::post('automations',                      [AutomationController::class, 'store'])->name('automations.store');
        Route::get('automations/{automation}',          [AutomationController::class, 'show'])->name('automations.show');
        Route::get('automations/{automation}/edit',     [AutomationController::class, 'edit'])->name('automations.edit');
        Route::put('automations/{automation}',          [AutomationController::class, 'update'])->name('automations.update');
        Route::get('automations/{automation}/delete',   [AutomationController::class, 'delete'])->name('automations.delete');
        Route::delete('automations/{automation}/deleteSave', [AutomationController::class, 'deleteSave'])->name('automations.deleteSave');

        // Duplicate (clona la automation con sufijo "(copia)").
        Route::post('automations/{automation}/duplicate', [AutomationController::class, 'duplicate'])->name('automations.duplicate');

        // Acciones directas: toggle is_active + run-now (test).
        Route::post('automations/{automation}/toggle',  [AutomationController::class, 'toggleActive'])->name('automations.toggle');
        Route::post('automations/{automation}/run-now', [AutomationController::class, 'runNow'])->name('automations.run_now');
    });
