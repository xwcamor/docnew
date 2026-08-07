<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthManagement\UserController;
use App\Http\Controllers\AuthManagement\RoleController;

/*
|--------------------------------------------------------------------------
| User Management
|--------------------------------------------------------------------------
| Users + Roles (Perfiles). Separado de auth_management.php (que es
| solo login/password/google) porque conceptualmente son gestión humana,
| no autenticación.
|
| Controllers viven todavía en App\Http\Controllers\AuthManagement\ por
| compatibilidad con el código existente — mover si se quiere full refactor.
*/

Route::prefix('user_management')->name('user_management.')->group(function () {

    // ── Trash + Restore + Force-delete — SUPER ONLY ──
    Route::middleware('role:super')->group(function () {
        Route::get('users/trash',                  [UserController::class, 'trash'])->name('users.trash');
        Route::post('users/{slug}/restore',        [UserController::class, 'restore'])->name('users.restore');
        Route::get('users/{slug}/restore',         fn () => redirect()->route('user_management.users.trash'));
        Route::delete('users/{slug}/force_delete', [UserController::class, 'forceDelete'])->name('users.force_delete');
    });

    // ── Users CRUD + Roles — super (cross-tenant) + admin (su tenant) ──
    // Gateado por plan_feature:team_management: los modulos Users y Roles
    // completos son "Equipos de trabajo" — solo planes pro/enterprise. free y
    // basic son operacion de 1 persona, no ven estos modulos. super
    // bypassa el gate (ver EnforcePlanFeature middleware).
    // Workers regulares (rol 'user') tampoco entran — gestion de equipo es
    // exclusiva del rol admin.
    Route::middleware(['role:super|admin', 'plan_feature:team_management'])->group(function () {
        // Bulk ops fuera del resource — gated por plan_feature:bulk_operations.
        Route::middleware(['throttle:10,1', 'plan_feature:bulk_operations'])->group(function () {
            Route::post('users/bulk_delete',     [UserController::class, 'bulkDelete'])->name('users.bulk_delete');
            Route::post('users/bulk_set_active', [UserController::class, 'bulkSetActive'])->name('users.bulk_set_active');
            Route::post('users/bulk_restore',    [UserController::class, 'bulkRestore'])->name('users.bulk_restore');
        });

        // ORDEN CRITICO: paths estaticos (export_*, import, edit_all) DEBEN ir
        // ANTES de Route::resource — sino users/{user} captura "export_csv" etc.

        // Exports — CSV libre (streaming); Excel/PDF/Word stubbed.
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('users/export_csv',   [UserController::class, 'exportCsv'])->name('users.export_csv');
            Route::post('users/export_excel', [UserController::class, 'exportExcel'])->name('users.export_excel');
            Route::post('users/export_pdf',   [UserController::class, 'exportPdf'])->name('users.export_pdf');
            Route::post('users/export_word',  [UserController::class, 'exportWord'])->name('users.export_word');
        });

        // Imports — gated por plan_feature:imports.
        Route::middleware('plan_feature:imports')->group(function () {
            Route::post('users/import',          [UserController::class, 'import'])->name('users.import');
            Route::get('users/import_template',  [UserController::class, 'importTemplate'])->name('users.import_template');
        });

        // Edit All — batch edit de name + is_active.
        Route::get('users/edit_all',          [UserController::class, 'editAll'])->name('users.edit_all');
        Route::post('users/edit_all/update',  [UserController::class, 'editAllUpdate'])->name('users.edit_all.update');

        // Undo del ultimo borrado (window 60s) — red de seguridad inmediata
        // para quien borro. NO es la papelera (esa es super only).
        Route::post('users/undo_last_delete', [UserController::class, 'undoLastDelete'])->name('users.undo_last_delete');

        Route::resource('users', UserController::class)->names('users');
        Route::get('users/{user}/delete',        [UserController::class, 'delete'])->name('users.delete');
        Route::delete('users/{user}/deleteSave', [UserController::class, 'deleteSave'])->name('users.deleteSave');

        // ── Roles (Perfiles) ──
        // El modulo Roles completo (lectura incluida) esta gateado por
        // `plan_feature:team_management` en el group padre — es parte de
        // "Equipos de trabajo". Planes free/basic NO ven Roles en absoluto
        // (ni el listado): son operacion de 1 persona. super bypassa
        // el gate. NO hay "lectura libre" — eso era el viejo modelo custom_roles.

        // ORDEN CRITICO: paths estaticos (trash, create, edit_all, export_*,
        // import) DEBEN ir ANTES que roles/{role}. Sin esto Laravel hace route
        // model binding con role='trash' y tira 404.

        // READ
        Route::get('roles',              [RoleController::class, 'index'])->name('roles.index');

        // Papelera + restore + force_delete — SUPER ONLY. El acceso a
        // datos eliminados y su restauracion/borrado definitivo se gestiona
        // por ticket (feature futura). Un admin de workspace NO entra aca.
        Route::middleware('role:super')->group(function () {
            Route::get('roles/trash',                   [RoleController::class, 'trash'])->name('roles.trash');
            Route::post('roles/{slug}/restore',         [RoleController::class, 'restore'])->name('roles.restore');
            Route::get('roles/{slug}/restore',          fn () => redirect()->route('user_management.roles.trash'));
            Route::delete('roles/{slug}/force_delete',  [RoleController::class, 'forceDelete'])->name('roles.force_delete');
        });

        // Exports — CSV libre (streaming); Excel/PDF/Word stubbed. Lectura, sin gate de plan.
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('roles/export_csv',   [RoleController::class, 'exportCsv'])->name('roles.export_csv');
            Route::post('roles/export_excel', [RoleController::class, 'exportExcel'])->name('roles.export_excel');
            Route::post('roles/export_pdf',   [RoleController::class, 'exportPdf'])->name('roles.export_pdf');
            Route::post('roles/export_word',  [RoleController::class, 'exportWord'])->name('roles.export_word');
        });

        // WRITE — el modulo entero ya esta gateado por plan_feature:team_management
        // en el grupo padre. No se duplica el gate aca.
        Route::group([], function () {
            Route::get('roles/create',                  [RoleController::class, 'create'])->name('roles.create');
            Route::post('roles',                        [RoleController::class, 'store'])->name('roles.store');

            // Edit All — batch edit (paths estaticos, antes de {role}).
            Route::get('roles/edit_all',          [RoleController::class, 'editAll'])->name('roles.edit_all');
            Route::post('roles/edit_all/update',  [RoleController::class, 'editAllUpdate'])->name('roles.edit_all.update');

            // Undo del ultimo borrado (window 60s) — la red de seguridad
            // inmediata para quien borro. NO es la papelera (esa es super).
            Route::post('roles/undo_last_delete', [RoleController::class, 'undoLastDelete'])->name('roles.undo_last_delete');

            // Imports — gated por plan_feature:imports.
            Route::middleware('plan_feature:imports')->group(function () {
                Route::post('roles/import',          [RoleController::class, 'import'])->name('roles.import');
                Route::get('roles/import_template',  [RoleController::class, 'importTemplate'])->name('roles.import_template');
            });

            Route::get('roles/{role}/edit',             [RoleController::class, 'edit'])->name('roles.edit');
            Route::put('roles/{role}',                  [RoleController::class, 'update'])->name('roles.update');
            Route::delete('roles/{role}',               [RoleController::class, 'destroy'])->name('roles.destroy');
            Route::get('roles/{role}/delete',           [RoleController::class, 'delete'])->name('roles.delete');
            Route::delete('roles/{role}/deleteSave',    [RoleController::class, 'deleteSave'])->name('roles.deleteSave');
            Route::post('roles/{role}/duplicate',       [RoleController::class, 'duplicate'])->name('roles.duplicate');

            Route::middleware('throttle:10,1')->group(function () {
                Route::post('roles/bulk_delete',     [RoleController::class, 'bulkDelete'])->name('roles.bulk_delete');
                Route::post('roles/bulk_set_active', [RoleController::class, 'bulkSetActive'])->name('roles.bulk_set_active');
                Route::post('roles/bulk_restore',    [RoleController::class, 'bulkRestore'])->name('roles.bulk_restore');
            });
        });

        // {role} dinamico — SIEMPRE al final.
        Route::get('roles/{role}',       [RoleController::class, 'show'])->name('roles.show');
    });
});
