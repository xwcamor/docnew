<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BusinessManagement\BrandController;
use App\Http\Controllers\BusinessManagement\TapChangerTechnologyController;
use App\Http\Controllers\BusinessManagement\TapChangerModelController;
use App\Http\Controllers\BusinessManagement\TapChangerBrandController;
use App\Http\Controllers\BusinessManagement\LaboratoryController;
use App\Http\Controllers\BusinessManagement\TapChangerTypeController;
use App\Http\Controllers\BusinessManagement\TransformerTypeController;
use App\Http\Controllers\BusinessManagement\TransformerController;
use App\Http\Controllers\BusinessManagement\ReportShareController;
use App\Http\Controllers\BusinessManagement\ReportShareLogController;
use App\Http\Controllers\BusinessManagement\OilTypeController;
use App\Http\Controllers\BusinessManagement\CustomerController;
use App\Http\Controllers\BusinessManagement\CustomerHierarchyController;
use App\Http\Controllers\BusinessManagement\ChromatographicalController;
use App\Http\Controllers\BusinessManagement\FuranoController;
use App\Http\Controllers\BusinessManagement\FiquiController;
use App\Http\Controllers\BusinessManagement\FpotController;
use App\Http\Controllers\BusinessManagement\TransformerEventController;
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
        Route::post('customers/{customer}/hierarchy', [CustomerHierarchyController::class, 'store'])->name('customers.hierarchy.store');
        Route::put('customers/{customer}/hierarchy/{level}/{id}', [CustomerHierarchyController::class, 'update'])->name('customers.hierarchy.update');
        Route::delete('customers/{customer}/hierarchy/{level}/{id}', [CustomerHierarchyController::class, 'destroy'])->name('customers.hierarchy.destroy');
        Route::post('customers/{customer}/hierarchy/{level}/{id}/restore', [CustomerHierarchyController::class, 'restore'])->name('customers.hierarchy.restore');
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


    // ── OilTypes ── (catálogo interno del motor: SOLO super)
    // Todo el módulo va dentro de un grupo role:super: el admin del workspace no
    // lo ve en el sidebar ni puede navegar por URL directa. Las reglas del motor
    // viven en datos, no se ajustan por tenant.
    Route::middleware('role:super')->group(function () {

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('oil_types/trash',                  [OilTypeController::class, 'trash'])->name('oil_types.trash');
        Route::post('oil_types/bulk_restore',          [OilTypeController::class, 'bulkRestore'])->name('oil_types.bulk_restore');
        Route::post('oil_types/{slug}/restore',        [OilTypeController::class, 'restore'])->name('oil_types.restore');
        Route::get('oil_types/{slug}/restore',         fn () => redirect()->route('business_management.oil_types.trash'));
        Route::delete('oil_types/{slug}/force_delete', [OilTypeController::class, 'forceDelete'])->name('oil_types.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:oil_types.view')->group(function () {
        Route::middleware(['throttle:5,1', 'plan_feature:export_excel'])
            ->post('oil_types/export_excel', [OilTypeController::class, 'exportExcel'])->name('oil_types.export_excel');
        Route::middleware(['throttle:5,1', 'plan_feature:export_pdf'])
            ->post('oil_types/export_pdf',   [OilTypeController::class, 'exportPdf'])->name('oil_types.export_pdf');
        Route::middleware(['throttle:5,1', 'plan_feature:export_word'])
            ->post('oil_types/export_word',  [OilTypeController::class, 'exportWord'])->name('oil_types.export_word');
        Route::middleware('throttle:5,1')
            ->post('oil_types/export_csv',   [OilTypeController::class, 'exportCsv'])->name('oil_types.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:oil_types.create', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('oil_types/import',          [OilTypeController::class, 'import'])->name('oil_types.import');
        Route::get('oil_types/import_template',  [OilTypeController::class, 'importTemplate'])->name('oil_types.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:oil_types.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('oil_types/bulk_delete',     [OilTypeController::class, 'bulkDelete'])->name('oil_types.bulk_delete');
        Route::post('oil_types/bulk_set_active', [OilTypeController::class, 'bulkSetActive'])->name('oil_types.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:oil_types.delete')->group(function () {
        Route::post('oil_types/undo_last_delete', [OilTypeController::class, 'undoLastDelete'])->name('oil_types.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:oil_types.edit')->group(function () {
        Route::get('oil_types/edit_all',         [OilTypeController::class, 'editAll'])->name('oil_types.edit_all');
        Route::post('oil_types/edit_all/update', [OilTypeController::class, 'editAllUpdate'])->name('oil_types.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:oil_types.create')->group(function () {
        Route::get('oil_types/create', [OilTypeController::class, 'create'])->name('oil_types.create');
        Route::post('oil_types',       [OilTypeController::class, 'store'])->name('oil_types.store');
        Route::post('oil_types/{oilType}/duplicate', [OilTypeController::class, 'duplicate'])->name('oil_types.duplicate');
    });

    Route::middleware('permission:oil_types.view')->group(function () {
        Route::get('oil_types',                [OilTypeController::class, 'index'])->name('oil_types.index');
        Route::get('oil_types/{oilType}',  [OilTypeController::class, 'show'])->name('oil_types.show');
    });
    Route::middleware('permission:oil_types.edit')->group(function () {
        Route::get('oil_types/{oilType}/edit', [OilTypeController::class, 'edit'])->name('oil_types.edit');
        Route::put('oil_types/{oilType}',      [OilTypeController::class, 'update'])->name('oil_types.update');
    });
    Route::middleware('permission:oil_types.delete')->group(function () {
        Route::get('oil_types/{oilType}/delete',        [OilTypeController::class, 'delete'])->name('oil_types.delete');
        Route::delete('oil_types/{oilType}/deleteSave', [OilTypeController::class, 'deleteSave'])->name('oil_types.deleteSave');
    });
    }); // fin OilTypes (role:super)


    // ── Transformers ──
    // Bloque generado por make:module. Reordena o ajusta permisos según tu dominio.

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('transformers/trash',                  [TransformerController::class, 'trash'])->name('transformers.trash');
        Route::post('transformers/bulk_restore',          [TransformerController::class, 'bulkRestore'])->name('transformers.bulk_restore');
        Route::post('transformers/{slug}/restore',        [TransformerController::class, 'restore'])->name('transformers.restore');
        Route::get('transformers/{slug}/restore',         fn () => redirect()->route('business_management.transformers.trash'));
        Route::delete('transformers/{slug}/force_delete', [TransformerController::class, 'forceDelete'])->name('transformers.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:transformers.view')->group(function () {
        // Comparación "por grupos": 2 páginas independientes (sin query mode).
        Route::get('comparison/gases', [\App\Http\Controllers\BusinessManagement\ComparisonController::class, 'gases'])->name('comparison.gases');
        Route::get('comparison/patrones', [\App\Http\Controllers\BusinessManagement\ComparisonController::class, 'patrones'])->name('comparison.patrones');
        // Panel de flota: vista de águila (conteos por banda de salud + peores).
        Route::get('transformers/fleet', [TransformerController::class, 'fleet'])->name('transformers.fleet');
        // Reporte de flota consolidado (todas las pruebas de los trafos del
        // cliente en un solo Excel con pestañas).
        Route::middleware(['throttle:5,1', 'plan_feature:export_excel'])
            ->post('transformers/fleet_report_excel', [TransformerController::class, 'fleetReportExcel'])->name('transformers.fleet_report_excel');
        Route::middleware('throttle:5,1')
            ->post('transformers/fleet_report_csv', [TransformerController::class, 'fleetReportCsv'])->name('transformers.fleet_report_csv');
        Route::middleware(['throttle:5,1', 'plan_feature:export_pdf'])
            ->post('transformers/fleet_report_pdf', [TransformerController::class, 'fleetReportPdf'])->name('transformers.fleet_report_pdf');
        Route::middleware(['throttle:5,1', 'permission:transformers.export', 'plan_feature:export_excel'])
            ->post('transformers/export_excel', [TransformerController::class, 'exportExcel'])->name('transformers.export_excel');
        Route::middleware(['throttle:5,1', 'permission:transformers.export', 'plan_feature:export_pdf'])
            ->post('transformers/export_pdf',   [TransformerController::class, 'exportPdf'])->name('transformers.export_pdf');
        Route::middleware(['throttle:5,1', 'permission:transformers.export', 'plan_feature:export_word'])
            ->post('transformers/export_word',  [TransformerController::class, 'exportWord'])->name('transformers.export_word');
        Route::middleware(['throttle:5,1', 'permission:transformers.export'])
            ->post('transformers/export_csv',   [TransformerController::class, 'exportCsv'])->name('transformers.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:transformers.create', 'permission:transformers.import', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('transformers/import',          [TransformerController::class, 'import'])->name('transformers.import');
        Route::get('transformers/import_template',  [TransformerController::class, 'importTemplate'])->name('transformers.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:transformers.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('transformers/bulk_delete',     [TransformerController::class, 'bulkDelete'])->name('transformers.bulk_delete');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:transformers.delete')->group(function () {
        Route::post('transformers/undo_last_delete', [TransformerController::class, 'undoLastDelete'])->name('transformers.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:transformers.edit')->group(function () {
        Route::get('transformers/edit_all',         [TransformerController::class, 'editAll'])->name('transformers.edit_all');
        Route::post('transformers/edit_all/update', [TransformerController::class, 'editAllUpdate'])->name('transformers.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:transformers.create')->group(function () {
        Route::get('transformers/create', [TransformerController::class, 'create'])->name('transformers.create');
        Route::post('transformers',       [TransformerController::class, 'store'])->name('transformers.store');
        Route::post('transformers/{transformer}/duplicate', [TransformerController::class, 'duplicate'])->name('transformers.duplicate');
    });

    Route::middleware('permission:transformers.view')->group(function () {
        Route::get('transformers',                [TransformerController::class, 'index'])->name('transformers.index');
        Route::get('transformers/{transformer}',  [TransformerController::class, 'show'])->name('transformers.show');
        // "¿Por qué este resultado?" — detalle del diagnóstico (SOLO LECTURA,
        // no guarda nada). Disponible para cualquiera que pueda VER el trafo, no
        // solo para quien carga muestras (antes estaba con samples|edit → un
        // perfil de solo-lectura recibía 403 al abrir el drawer).
        Route::post('transformers/{transformer}/cromas-explain',  [ChromatographicalController::class, 'explain'])->name('transformers.cromas.explain');
        Route::post('transformers/{transformer}/furanos-explain', [FuranoController::class, 'explain'])->name('transformers.furanos.explain');
        Route::post('transformers/{transformer}/fiquis-explain',  [FiquiController::class, 'explain'])->name('transformers.fiquis.explain');
        Route::post('transformers/{transformer}/fpot-explain',    [FpotController::class, 'explain'])->name('transformers.fpot.explain');
        // Informe ÚNICO consolidado del transformador: incluye solo las pruebas
        // con datos. (Los PDFs por prueba se eliminaron a propósito.)
        Route::match(['get', 'post'], 'transformers/{transformer}/report', [TransformerController::class, 'report'])->name('transformers.report');
        // Enviar el informe a aprobación (etapa 2 de firmas; solo si el workspace lo exige).
        Route::post('transformers/{transformer}/send-for-approval', [TransformerController::class, 'sendForApproval'])->name('transformers.send_for_approval');
        // Auto-caché de gráficos del informe (lo manda Ver trafo en background
        // cuando el caché está vacío — alimenta el PDF del portal compartido).
        Route::post('transformers/{transformer}/report-charts', [TransformerController::class, 'storeReportCharts'])->name('transformers.report_charts');
        // Selección manual de muestras para Tabla 4 (DGA Status, IEEE C57.104).
        Route::post('transformers/{transformer}/dga-rate-selection', [TransformerController::class, 'saveDgaRateSelection'])->name('transformers.dga_rate_selection.save');
        Route::delete('transformers/{transformer}/dga-rate-selection', [TransformerController::class, 'clearDgaRateSelection'])->name('transformers.dga_rate_selection.clear');
        // Informe borrador en Word (editable, sin QR ni firmas). Solo 1 trafo.
        Route::post('transformers/{transformer}/report-word', [TransformerController::class, 'reportWord'])->name('transformers.report_word');
        // Compartir diagnóstico con clientes externos (link público + OTP). Premium.
        Route::middleware('plan_feature:report_sharing')->group(function () {
            Route::get('report-shares', [ReportShareController::class, 'index'])->name('report_shares.index');
            Route::post('report-shares', [ReportShareController::class, 'store'])->name('report_shares.store');
            Route::post('report-shares/{share}/resend', [ReportShareController::class, 'resend'])->name('report_shares.resend');
            Route::post('report-shares/{share}/extend', [ReportShareController::class, 'extend'])->name('report_shares.extend');
            Route::delete('report-shares/{share}', [ReportShareController::class, 'revoke'])->name('report_shares.revoke');

            // Historial CRUZANDO clientes ("Envíos de informes"). El modal solo
            // muestra el de un alcance; esta pantalla responde "qué mandé".
            Route::get('report-shares-log', [ReportShareLogController::class, 'index'])->name('report_shares_log.index');
            Route::delete('report-shares-log/{share}', [ReportShareLogController::class, 'revoke'])->name('report_shares_log.revoke');
        });
    });
    Route::middleware('permission:transformers.edit')->group(function () {
        Route::get('transformers/{transformer}/edit', [TransformerController::class, 'edit'])->name('transformers.edit');
        Route::put('transformers/{transformer}',      [TransformerController::class, 'update'])->name('transformers.update');

        // Bitácora del transformador (eventos / comentarios: timeline + kanban).
        Route::post('transformers/{transformer}/events', [TransformerEventController::class, 'store'])->name('transformers.events.store');
        Route::put('transformers/{transformer}/events/{event}', [TransformerEventController::class, 'update'])->name('transformers.events.update');
        Route::delete('transformers/{transformer}/events/{event}', [TransformerEventController::class, 'destroy'])->name('transformers.events.destroy');
    });

    // Muestras de ensayos (cromas/furanos/fiquis/fpot). Permiso PROPIO
    // (transformers.samples) separado de editar la FICHA del trafo — permite
    // perfiles tipo "Cliente Editor" que cargan resultados de laboratorio sin
    // poder tocar los datos de placa. transformers.edit lo incluye (OR) para
    // retrocompatibilidad con los roles existentes.
    Route::middleware('permission:transformers.samples|transformers.edit')->group(function () {
        // Ensayos de cromatografía (DGA) del transformador.
        Route::post('transformers/{transformer}/cromas', [ChromatographicalController::class, 'store'])->name('transformers.cromas.store');
        Route::put('transformers/{transformer}/cromas/{croma}', [ChromatographicalController::class, 'update'])->name('transformers.cromas.update');
        Route::delete('transformers/{transformer}/cromas/{croma}', [ChromatographicalController::class, 'destroy'])->name('transformers.cromas.destroy');
        // Editor estilo Excel: guardado por lote + preview en vivo del diagnóstico.
        Route::post('transformers/{transformer}/cromas-batch', [ChromatographicalController::class, 'batch'])->name('transformers.cromas.batch');
        Route::post('transformers/{transformer}/cromas-preview', [ChromatographicalController::class, 'preview'])->name('transformers.cromas.preview');

        // Ensayos de furanos (degradación del papel) del transformador.
        Route::post('transformers/{transformer}/furanos', [FuranoController::class, 'store'])->name('transformers.furanos.store');
        Route::put('transformers/{transformer}/furanos/{furano}', [FuranoController::class, 'update'])->name('transformers.furanos.update');
        Route::delete('transformers/{transformer}/furanos/{furano}', [FuranoController::class, 'destroy'])->name('transformers.furanos.destroy');
        Route::post('transformers/{transformer}/furanos-batch', [FuranoController::class, 'batch'])->name('transformers.furanos.batch');
        Route::post('transformers/{transformer}/furanos-preview', [FuranoController::class, 'preview'])->name('transformers.furanos.preview');

        // Ensayos fisicoquímicos del aceite del transformador.
        Route::post('transformers/{transformer}/fiquis', [FiquiController::class, 'store'])->name('transformers.fiquis.store');
        Route::put('transformers/{transformer}/fiquis/{fiqui}', [FiquiController::class, 'update'])->name('transformers.fiquis.update');
        Route::delete('transformers/{transformer}/fiquis/{fiqui}', [FiquiController::class, 'destroy'])->name('transformers.fiquis.destroy');
        Route::post('transformers/{transformer}/fiquis-batch', [FiquiController::class, 'batch'])->name('transformers.fiquis.batch');
        Route::post('transformers/{transformer}/fiquis-preview', [FiquiController::class, 'preview'])->name('transformers.fiquis.preview');

        // Ensayos de Factor de Potencia del aislamiento del transformador.
        Route::post('transformers/{transformer}/fpot', [FpotController::class, 'store'])->name('transformers.fpot.store');
        Route::put('transformers/{transformer}/fpot/{fpot}', [FpotController::class, 'update'])->name('transformers.fpot.update');
        Route::delete('transformers/{transformer}/fpot/{fpot}', [FpotController::class, 'destroy'])->name('transformers.fpot.destroy');
        Route::post('transformers/{transformer}/fpot-batch', [FpotController::class, 'batch'])->name('transformers.fpot.batch');
        Route::post('transformers/{transformer}/fpot-preview', [FpotController::class, 'preview'])->name('transformers.fpot.preview');
    });
    Route::middleware('permission:transformers.delete')->group(function () {
        Route::get('transformers/{transformer}/delete',        [TransformerController::class, 'delete'])->name('transformers.delete');
        Route::delete('transformers/{transformer}/deleteSave', [TransformerController::class, 'deleteSave'])->name('transformers.deleteSave');
    });

    // Bloquear/desbloquear trafo (Lockable) — solo super|admin. El nivel del
    // candado y quién lo saca se resuelve en el controller (HandlesRecordLocking).
    Route::middleware('role:super|admin')->group(function () {
        Route::post('transformers/{transformer}/lock',   [TransformerController::class, 'lock'])->name('transformers.lock');
        Route::post('transformers/{transformer}/unlock', [TransformerController::class, 'unlock'])->name('transformers.unlock');
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
    Route::middleware('permission:comments.create|diagnosis_notes.create')->group(function () {
        Route::post('comments', [CommentController::class, 'store'])->name('comments.store');
    });
    Route::middleware('permission:comments.delete')->group(function () {
        Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
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
    // ── Laboratories ──
    // Bloque generado por make:module. Reordena o ajusta permisos según tu dominio.

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('laboratories/trash',                  [LaboratoryController::class, 'trash'])->name('laboratories.trash');
        Route::post('laboratories/bulk_restore',          [LaboratoryController::class, 'bulkRestore'])->name('laboratories.bulk_restore');
        Route::post('laboratories/{slug}/restore',        [LaboratoryController::class, 'restore'])->name('laboratories.restore');
        Route::get('laboratories/{slug}/restore',         fn () => redirect()->route('business_management.laboratories.trash'));
        Route::delete('laboratories/{slug}/force_delete', [LaboratoryController::class, 'forceDelete'])->name('laboratories.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:laboratories.view')->group(function () {
        Route::middleware(['throttle:5,1', 'permission:laboratories.export', 'plan_feature:export_excel'])
            ->post('laboratories/export_excel', [LaboratoryController::class, 'exportExcel'])->name('laboratories.export_excel');
        Route::middleware(['throttle:5,1', 'permission:laboratories.export', 'plan_feature:export_pdf'])
            ->post('laboratories/export_pdf',   [LaboratoryController::class, 'exportPdf'])->name('laboratories.export_pdf');
        Route::middleware(['throttle:5,1', 'permission:laboratories.export', 'plan_feature:export_word'])
            ->post('laboratories/export_word',  [LaboratoryController::class, 'exportWord'])->name('laboratories.export_word');
        Route::middleware(['throttle:5,1', 'permission:laboratories.export'])
            ->post('laboratories/export_csv',   [LaboratoryController::class, 'exportCsv'])->name('laboratories.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:laboratories.create', 'permission:laboratories.import', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('laboratories/import',          [LaboratoryController::class, 'import'])->name('laboratories.import');
        Route::get('laboratories/import_template',  [LaboratoryController::class, 'importTemplate'])->name('laboratories.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:laboratories.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('laboratories/bulk_delete',     [LaboratoryController::class, 'bulkDelete'])->name('laboratories.bulk_delete');
        Route::post('laboratories/bulk_set_active', [LaboratoryController::class, 'bulkSetActive'])->name('laboratories.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:laboratories.delete')->group(function () {
        Route::post('laboratories/undo_last_delete', [LaboratoryController::class, 'undoLastDelete'])->name('laboratories.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:laboratories.edit')->group(function () {
        Route::get('laboratories/edit_all',         [LaboratoryController::class, 'editAll'])->name('laboratories.edit_all');
        Route::post('laboratories/edit_all/update', [LaboratoryController::class, 'editAllUpdate'])->name('laboratories.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:laboratories.create')->group(function () {
        Route::get('laboratories/create', [LaboratoryController::class, 'create'])->name('laboratories.create');
        Route::post('laboratories',       [LaboratoryController::class, 'store'])->name('laboratories.store');
        // Alta rápida JSON desde otros módulos (ej. alta rapida de laboratorio desde el form de trafos).
        Route::post('laboratories/quick_store', [LaboratoryController::class, 'quickStore'])->name('laboratories.quick_store');
        Route::post('laboratories/{laboratory}/duplicate', [LaboratoryController::class, 'duplicate'])->name('laboratories.duplicate');
    });

    Route::middleware('permission:laboratories.view')->group(function () {
        Route::get('laboratories',                [LaboratoryController::class, 'index'])->name('laboratories.index');
        Route::get('laboratories/{laboratory}',  [LaboratoryController::class, 'show'])->name('laboratories.show');
    });
    Route::middleware('permission:laboratories.edit')->group(function () {
        Route::get('laboratories/{laboratory}/edit', [LaboratoryController::class, 'edit'])->name('laboratories.edit');
        Route::put('laboratories/{laboratory}',      [LaboratoryController::class, 'update'])->name('laboratories.update');
    });
    Route::middleware('permission:laboratories.delete')->group(function () {
        Route::get('laboratories/{laboratory}/delete',        [LaboratoryController::class, 'delete'])->name('laboratories.delete');
        Route::delete('laboratories/{laboratory}/deleteSave', [LaboratoryController::class, 'deleteSave'])->name('laboratories.deleteSave');
    });
    // Bloquear/desbloquear (Lockable) — solo super|admin.
    Route::middleware('role:super|admin')->group(function () {
        Route::post('laboratories/{laboratory}/lock',   [LaboratoryController::class, 'lock'])->name('laboratories.lock');
        Route::post('laboratories/{laboratory}/unlock', [LaboratoryController::class, 'unlock'])->name('laboratories.unlock');
    });
    // ── TapChangerBrands ──
    // Bloque generado por make:module. Reordena o ajusta permisos según tu dominio.

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('tap_changer_brands/trash',                  [TapChangerBrandController::class, 'trash'])->name('tap_changer_brands.trash');
        Route::post('tap_changer_brands/bulk_restore',          [TapChangerBrandController::class, 'bulkRestore'])->name('tap_changer_brands.bulk_restore');
        Route::post('tap_changer_brands/{slug}/restore',        [TapChangerBrandController::class, 'restore'])->name('tap_changer_brands.restore');
        Route::get('tap_changer_brands/{slug}/restore',         fn () => redirect()->route('business_management.tap_changer_brands.trash'));
        Route::delete('tap_changer_brands/{slug}/force_delete', [TapChangerBrandController::class, 'forceDelete'])->name('tap_changer_brands.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:tap_changer_brands.view')->group(function () {
        Route::middleware(['throttle:5,1', 'permission:tap_changer_brands.export', 'plan_feature:export_excel'])
            ->post('tap_changer_brands/export_excel', [TapChangerBrandController::class, 'exportExcel'])->name('tap_changer_brands.export_excel');
        Route::middleware(['throttle:5,1', 'permission:tap_changer_brands.export', 'plan_feature:export_pdf'])
            ->post('tap_changer_brands/export_pdf',   [TapChangerBrandController::class, 'exportPdf'])->name('tap_changer_brands.export_pdf');
        Route::middleware(['throttle:5,1', 'permission:tap_changer_brands.export', 'plan_feature:export_word'])
            ->post('tap_changer_brands/export_word',  [TapChangerBrandController::class, 'exportWord'])->name('tap_changer_brands.export_word');
        Route::middleware(['throttle:5,1', 'permission:tap_changer_brands.export'])
            ->post('tap_changer_brands/export_csv',   [TapChangerBrandController::class, 'exportCsv'])->name('tap_changer_brands.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:tap_changer_brands.create', 'permission:tap_changer_brands.import', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('tap_changer_brands/import',          [TapChangerBrandController::class, 'import'])->name('tap_changer_brands.import');
        Route::get('tap_changer_brands/import_template',  [TapChangerBrandController::class, 'importTemplate'])->name('tap_changer_brands.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:tap_changer_brands.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('tap_changer_brands/bulk_delete',     [TapChangerBrandController::class, 'bulkDelete'])->name('tap_changer_brands.bulk_delete');
        Route::post('tap_changer_brands/bulk_set_active', [TapChangerBrandController::class, 'bulkSetActive'])->name('tap_changer_brands.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:tap_changer_brands.delete')->group(function () {
        Route::post('tap_changer_brands/undo_last_delete', [TapChangerBrandController::class, 'undoLastDelete'])->name('tap_changer_brands.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:tap_changer_brands.edit')->group(function () {
        Route::get('tap_changer_brands/edit_all',         [TapChangerBrandController::class, 'editAll'])->name('tap_changer_brands.edit_all');
        Route::post('tap_changer_brands/edit_all/update', [TapChangerBrandController::class, 'editAllUpdate'])->name('tap_changer_brands.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:tap_changer_brands.create')->group(function () {
        Route::get('tap_changer_brands/create', [TapChangerBrandController::class, 'create'])->name('tap_changer_brands.create');
        Route::post('tap_changer_brands',       [TapChangerBrandController::class, 'store'])->name('tap_changer_brands.store');
        // Alta rápida JSON desde otros módulos (ej. alta rapida de tap_changer_brand desde el form de trafos).
        Route::post('tap_changer_brands/quick_store', [TapChangerBrandController::class, 'quickStore'])->name('tap_changer_brands.quick_store');
        Route::post('tap_changer_brands/{tap_changer_brand}/duplicate', [TapChangerBrandController::class, 'duplicate'])->name('tap_changer_brands.duplicate');
    });

    Route::middleware('permission:tap_changer_brands.view')->group(function () {
        Route::get('tap_changer_brands',                [TapChangerBrandController::class, 'index'])->name('tap_changer_brands.index');
        Route::get('tap_changer_brands/{tap_changer_brand}',  [TapChangerBrandController::class, 'show'])->name('tap_changer_brands.show');
    });
    Route::middleware('permission:tap_changer_brands.edit')->group(function () {
        Route::get('tap_changer_brands/{tap_changer_brand}/edit', [TapChangerBrandController::class, 'edit'])->name('tap_changer_brands.edit');
        Route::put('tap_changer_brands/{tap_changer_brand}',      [TapChangerBrandController::class, 'update'])->name('tap_changer_brands.update');
    });
    Route::middleware('permission:tap_changer_brands.delete')->group(function () {
        Route::get('tap_changer_brands/{tap_changer_brand}/delete',        [TapChangerBrandController::class, 'delete'])->name('tap_changer_brands.delete');
        Route::delete('tap_changer_brands/{tap_changer_brand}/deleteSave', [TapChangerBrandController::class, 'deleteSave'])->name('tap_changer_brands.deleteSave');
    });
    // Bloquear/desbloquear (Lockable) — solo super|admin.
    Route::middleware('role:super|admin')->group(function () {
        Route::post('tap_changer_brands/{tap_changer_brand}/lock',   [TapChangerBrandController::class, 'lock'])->name('tap_changer_brands.lock');
        Route::post('tap_changer_brands/{tap_changer_brand}/unlock', [TapChangerBrandController::class, 'unlock'])->name('tap_changer_brands.unlock');
    });
    // ── TapChangerModels ──
    // Bloque generado por make:module. Reordena o ajusta permisos según tu dominio.

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('tap_changer_models/trash',                  [TapChangerModelController::class, 'trash'])->name('tap_changer_models.trash');
        Route::post('tap_changer_models/bulk_restore',          [TapChangerModelController::class, 'bulkRestore'])->name('tap_changer_models.bulk_restore');
        Route::post('tap_changer_models/{slug}/restore',        [TapChangerModelController::class, 'restore'])->name('tap_changer_models.restore');
        Route::get('tap_changer_models/{slug}/restore',         fn () => redirect()->route('business_management.tap_changer_models.trash'));
        Route::delete('tap_changer_models/{slug}/force_delete', [TapChangerModelController::class, 'forceDelete'])->name('tap_changer_models.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:tap_changer_models.view')->group(function () {
        Route::middleware(['throttle:5,1', 'permission:tap_changer_models.export', 'plan_feature:export_excel'])
            ->post('tap_changer_models/export_excel', [TapChangerModelController::class, 'exportExcel'])->name('tap_changer_models.export_excel');
        Route::middleware(['throttle:5,1', 'permission:tap_changer_models.export', 'plan_feature:export_pdf'])
            ->post('tap_changer_models/export_pdf',   [TapChangerModelController::class, 'exportPdf'])->name('tap_changer_models.export_pdf');
        Route::middleware(['throttle:5,1', 'permission:tap_changer_models.export', 'plan_feature:export_word'])
            ->post('tap_changer_models/export_word',  [TapChangerModelController::class, 'exportWord'])->name('tap_changer_models.export_word');
        Route::middleware(['throttle:5,1', 'permission:tap_changer_models.export'])
            ->post('tap_changer_models/export_csv',   [TapChangerModelController::class, 'exportCsv'])->name('tap_changer_models.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:tap_changer_models.create', 'permission:tap_changer_models.import', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('tap_changer_models/import',          [TapChangerModelController::class, 'import'])->name('tap_changer_models.import');
        Route::get('tap_changer_models/import_template',  [TapChangerModelController::class, 'importTemplate'])->name('tap_changer_models.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:tap_changer_models.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('tap_changer_models/bulk_delete',     [TapChangerModelController::class, 'bulkDelete'])->name('tap_changer_models.bulk_delete');
        Route::post('tap_changer_models/bulk_set_active', [TapChangerModelController::class, 'bulkSetActive'])->name('tap_changer_models.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:tap_changer_models.delete')->group(function () {
        Route::post('tap_changer_models/undo_last_delete', [TapChangerModelController::class, 'undoLastDelete'])->name('tap_changer_models.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:tap_changer_models.edit')->group(function () {
        Route::get('tap_changer_models/edit_all',         [TapChangerModelController::class, 'editAll'])->name('tap_changer_models.edit_all');
        Route::post('tap_changer_models/edit_all/update', [TapChangerModelController::class, 'editAllUpdate'])->name('tap_changer_models.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:tap_changer_models.create')->group(function () {
        Route::get('tap_changer_models/create', [TapChangerModelController::class, 'create'])->name('tap_changer_models.create');
        Route::post('tap_changer_models',       [TapChangerModelController::class, 'store'])->name('tap_changer_models.store');
        // Alta rápida JSON desde otros módulos (ej. alta rapida de tap_changer_model desde el form de trafos).
        Route::post('tap_changer_models/quick_store', [TapChangerModelController::class, 'quickStore'])->name('tap_changer_models.quick_store');
        Route::post('tap_changer_models/{tap_changer_model}/duplicate', [TapChangerModelController::class, 'duplicate'])->name('tap_changer_models.duplicate');
    });

    Route::middleware('permission:tap_changer_models.view')->group(function () {
        Route::get('tap_changer_models',                [TapChangerModelController::class, 'index'])->name('tap_changer_models.index');
        Route::get('tap_changer_models/{tap_changer_model}',  [TapChangerModelController::class, 'show'])->name('tap_changer_models.show');
    });
    Route::middleware('permission:tap_changer_models.edit')->group(function () {
        Route::get('tap_changer_models/{tap_changer_model}/edit', [TapChangerModelController::class, 'edit'])->name('tap_changer_models.edit');
        Route::put('tap_changer_models/{tap_changer_model}',      [TapChangerModelController::class, 'update'])->name('tap_changer_models.update');
    });
    Route::middleware('permission:tap_changer_models.delete')->group(function () {
        Route::get('tap_changer_models/{tap_changer_model}/delete',        [TapChangerModelController::class, 'delete'])->name('tap_changer_models.delete');
        Route::delete('tap_changer_models/{tap_changer_model}/deleteSave', [TapChangerModelController::class, 'deleteSave'])->name('tap_changer_models.deleteSave');
    });
    // Bloquear/desbloquear (Lockable) — solo super|admin.
    Route::middleware('role:super|admin')->group(function () {
        Route::post('tap_changer_models/{tap_changer_model}/lock',   [TapChangerModelController::class, 'lock'])->name('tap_changer_models.lock');
        Route::post('tap_changer_models/{tap_changer_model}/unlock', [TapChangerModelController::class, 'unlock'])->name('tap_changer_models.unlock');
    });
    // ── TapChangerTechnologies ──
    // Bloque generado por make:module. Reordena o ajusta permisos según tu dominio.

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('tap_changer_technologies/trash',                  [TapChangerTechnologyController::class, 'trash'])->name('tap_changer_technologies.trash');
        Route::post('tap_changer_technologies/bulk_restore',          [TapChangerTechnologyController::class, 'bulkRestore'])->name('tap_changer_technologies.bulk_restore');
        Route::post('tap_changer_technologies/{slug}/restore',        [TapChangerTechnologyController::class, 'restore'])->name('tap_changer_technologies.restore');
        Route::get('tap_changer_technologies/{slug}/restore',         fn () => redirect()->route('business_management.tap_changer_technologies.trash'));
        Route::delete('tap_changer_technologies/{slug}/force_delete', [TapChangerTechnologyController::class, 'forceDelete'])->name('tap_changer_technologies.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:tap_changer_technologies.view')->group(function () {
        Route::middleware(['throttle:5,1', 'permission:tap_changer_technologies.export', 'plan_feature:export_excel'])
            ->post('tap_changer_technologies/export_excel', [TapChangerTechnologyController::class, 'exportExcel'])->name('tap_changer_technologies.export_excel');
        Route::middleware(['throttle:5,1', 'permission:tap_changer_technologies.export', 'plan_feature:export_pdf'])
            ->post('tap_changer_technologies/export_pdf',   [TapChangerTechnologyController::class, 'exportPdf'])->name('tap_changer_technologies.export_pdf');
        Route::middleware(['throttle:5,1', 'permission:tap_changer_technologies.export', 'plan_feature:export_word'])
            ->post('tap_changer_technologies/export_word',  [TapChangerTechnologyController::class, 'exportWord'])->name('tap_changer_technologies.export_word');
        Route::middleware(['throttle:5,1', 'permission:tap_changer_technologies.export'])
            ->post('tap_changer_technologies/export_csv',   [TapChangerTechnologyController::class, 'exportCsv'])->name('tap_changer_technologies.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:tap_changer_technologies.create', 'permission:tap_changer_technologies.import', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('tap_changer_technologies/import',          [TapChangerTechnologyController::class, 'import'])->name('tap_changer_technologies.import');
        Route::get('tap_changer_technologies/import_template',  [TapChangerTechnologyController::class, 'importTemplate'])->name('tap_changer_technologies.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:tap_changer_technologies.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('tap_changer_technologies/bulk_delete',     [TapChangerTechnologyController::class, 'bulkDelete'])->name('tap_changer_technologies.bulk_delete');
        Route::post('tap_changer_technologies/bulk_set_active', [TapChangerTechnologyController::class, 'bulkSetActive'])->name('tap_changer_technologies.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:tap_changer_technologies.delete')->group(function () {
        Route::post('tap_changer_technologies/undo_last_delete', [TapChangerTechnologyController::class, 'undoLastDelete'])->name('tap_changer_technologies.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:tap_changer_technologies.edit')->group(function () {
        Route::get('tap_changer_technologies/edit_all',         [TapChangerTechnologyController::class, 'editAll'])->name('tap_changer_technologies.edit_all');
        Route::post('tap_changer_technologies/edit_all/update', [TapChangerTechnologyController::class, 'editAllUpdate'])->name('tap_changer_technologies.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:tap_changer_technologies.create')->group(function () {
        Route::get('tap_changer_technologies/create', [TapChangerTechnologyController::class, 'create'])->name('tap_changer_technologies.create');
        Route::post('tap_changer_technologies',       [TapChangerTechnologyController::class, 'store'])->name('tap_changer_technologies.store');
        // Alta rápida JSON desde otros módulos (ej. alta rapida de tap_changer_technology desde el form de trafos).
        Route::post('tap_changer_technologies/quick_store', [TapChangerTechnologyController::class, 'quickStore'])->name('tap_changer_technologies.quick_store');
        Route::post('tap_changer_technologies/{tap_changer_technology}/duplicate', [TapChangerTechnologyController::class, 'duplicate'])->name('tap_changer_technologies.duplicate');
    });

    Route::middleware('permission:tap_changer_technologies.view')->group(function () {
        Route::get('tap_changer_technologies',                [TapChangerTechnologyController::class, 'index'])->name('tap_changer_technologies.index');
        Route::get('tap_changer_technologies/{tap_changer_technology}',  [TapChangerTechnologyController::class, 'show'])->name('tap_changer_technologies.show');
    });
    Route::middleware('permission:tap_changer_technologies.edit')->group(function () {
        Route::get('tap_changer_technologies/{tap_changer_technology}/edit', [TapChangerTechnologyController::class, 'edit'])->name('tap_changer_technologies.edit');
        Route::put('tap_changer_technologies/{tap_changer_technology}',      [TapChangerTechnologyController::class, 'update'])->name('tap_changer_technologies.update');
    });
    Route::middleware('permission:tap_changer_technologies.delete')->group(function () {
        Route::get('tap_changer_technologies/{tap_changer_technology}/delete',        [TapChangerTechnologyController::class, 'delete'])->name('tap_changer_technologies.delete');
        Route::delete('tap_changer_technologies/{tap_changer_technology}/deleteSave', [TapChangerTechnologyController::class, 'deleteSave'])->name('tap_changer_technologies.deleteSave');
    });
    // Bloquear/desbloquear (Lockable) — solo super|admin.
    Route::middleware('role:super|admin')->group(function () {
        Route::post('tap_changer_technologies/{tap_changer_technology}/lock',   [TapChangerTechnologyController::class, 'lock'])->name('tap_changer_technologies.lock');
        Route::post('tap_changer_technologies/{tap_changer_technology}/unlock', [TapChangerTechnologyController::class, 'unlock'])->name('tap_changer_technologies.unlock');
    });
    // ── TapChangerTypes ── (conmutador: catálogo interno del motor, SOLO super)
    // Todo el módulo va dentro de un grupo role:super: el admin del workspace no
    // lo ve en el sidebar ni puede navegar por URL directa.
    Route::middleware('role:super')->group(function () {

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('tap_changer_types/trash',                  [TapChangerTypeController::class, 'trash'])->name('tap_changer_types.trash');
        Route::post('tap_changer_types/bulk_restore',          [TapChangerTypeController::class, 'bulkRestore'])->name('tap_changer_types.bulk_restore');
        Route::post('tap_changer_types/{slug}/restore',        [TapChangerTypeController::class, 'restore'])->name('tap_changer_types.restore');
        Route::get('tap_changer_types/{slug}/restore',         fn () => redirect()->route('business_management.tap_changer_types.trash'));
        Route::delete('tap_changer_types/{slug}/force_delete', [TapChangerTypeController::class, 'forceDelete'])->name('tap_changer_types.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:tap_changer_types.view')->group(function () {
        Route::middleware(['throttle:5,1', 'plan_feature:export_excel'])
            ->post('tap_changer_types/export_excel', [TapChangerTypeController::class, 'exportExcel'])->name('tap_changer_types.export_excel');
        Route::middleware(['throttle:5,1', 'plan_feature:export_pdf'])
            ->post('tap_changer_types/export_pdf',   [TapChangerTypeController::class, 'exportPdf'])->name('tap_changer_types.export_pdf');
        Route::middleware(['throttle:5,1', 'plan_feature:export_word'])
            ->post('tap_changer_types/export_word',  [TapChangerTypeController::class, 'exportWord'])->name('tap_changer_types.export_word');
        Route::middleware('throttle:5,1')
            ->post('tap_changer_types/export_csv',   [TapChangerTypeController::class, 'exportCsv'])->name('tap_changer_types.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:tap_changer_types.create', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('tap_changer_types/import',          [TapChangerTypeController::class, 'import'])->name('tap_changer_types.import');
        Route::get('tap_changer_types/import_template',  [TapChangerTypeController::class, 'importTemplate'])->name('tap_changer_types.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:tap_changer_types.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('tap_changer_types/bulk_delete',     [TapChangerTypeController::class, 'bulkDelete'])->name('tap_changer_types.bulk_delete');
        Route::post('tap_changer_types/bulk_set_active', [TapChangerTypeController::class, 'bulkSetActive'])->name('tap_changer_types.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:tap_changer_types.delete')->group(function () {
        Route::post('tap_changer_types/undo_last_delete', [TapChangerTypeController::class, 'undoLastDelete'])->name('tap_changer_types.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:tap_changer_types.edit')->group(function () {
        Route::get('tap_changer_types/edit_all',         [TapChangerTypeController::class, 'editAll'])->name('tap_changer_types.edit_all');
        Route::post('tap_changer_types/edit_all/update', [TapChangerTypeController::class, 'editAllUpdate'])->name('tap_changer_types.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:tap_changer_types.create')->group(function () {
        Route::get('tap_changer_types/create', [TapChangerTypeController::class, 'create'])->name('tap_changer_types.create');
        Route::post('tap_changer_types',       [TapChangerTypeController::class, 'store'])->name('tap_changer_types.store');
        Route::post('tap_changer_types/{tapChangerType}/duplicate', [TapChangerTypeController::class, 'duplicate'])->name('tap_changer_types.duplicate');
    });

    Route::middleware('permission:tap_changer_types.view')->group(function () {
        Route::get('tap_changer_types',                [TapChangerTypeController::class, 'index'])->name('tap_changer_types.index');
        Route::get('tap_changer_types/{tapChangerType}',  [TapChangerTypeController::class, 'show'])->name('tap_changer_types.show');
    });
    Route::middleware('permission:tap_changer_types.edit')->group(function () {
        Route::get('tap_changer_types/{tapChangerType}/edit', [TapChangerTypeController::class, 'edit'])->name('tap_changer_types.edit');
        Route::put('tap_changer_types/{tapChangerType}',      [TapChangerTypeController::class, 'update'])->name('tap_changer_types.update');
    });
    Route::middleware('permission:tap_changer_types.delete')->group(function () {
        Route::get('tap_changer_types/{tapChangerType}/delete',        [TapChangerTypeController::class, 'delete'])->name('tap_changer_types.delete');
        Route::delete('tap_changer_types/{tapChangerType}/deleteSave', [TapChangerTypeController::class, 'deleteSave'])->name('tap_changer_types.deleteSave');
    });
    }); // fin TapChangerTypes (role:super)

    // ── TransformerTypes ── (tipo de trafo: catálogo interno del motor, SOLO super)
    // Todo el módulo va dentro de un grupo role:super: el admin del workspace no
    // lo ve en el sidebar ni puede navegar por URL directa.
    Route::middleware('role:super')->group(function () {

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('transformer_types/trash',                  [TransformerTypeController::class, 'trash'])->name('transformer_types.trash');
        Route::post('transformer_types/bulk_restore',          [TransformerTypeController::class, 'bulkRestore'])->name('transformer_types.bulk_restore');
        Route::post('transformer_types/{slug}/restore',        [TransformerTypeController::class, 'restore'])->name('transformer_types.restore');
        Route::get('transformer_types/{slug}/restore',         fn () => redirect()->route('business_management.transformer_types.trash'));
        Route::delete('transformer_types/{slug}/force_delete', [TransformerTypeController::class, 'forceDelete'])->name('transformer_types.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:transformer_types.view')->group(function () {
        Route::middleware(['throttle:5,1', 'plan_feature:export_excel'])
            ->post('transformer_types/export_excel', [TransformerTypeController::class, 'exportExcel'])->name('transformer_types.export_excel');
        Route::middleware(['throttle:5,1', 'plan_feature:export_pdf'])
            ->post('transformer_types/export_pdf',   [TransformerTypeController::class, 'exportPdf'])->name('transformer_types.export_pdf');
        Route::middleware(['throttle:5,1', 'plan_feature:export_word'])
            ->post('transformer_types/export_word',  [TransformerTypeController::class, 'exportWord'])->name('transformer_types.export_word');
        Route::middleware('throttle:5,1')
            ->post('transformer_types/export_csv',   [TransformerTypeController::class, 'exportCsv'])->name('transformer_types.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:transformer_types.create', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('transformer_types/import',          [TransformerTypeController::class, 'import'])->name('transformer_types.import');
        Route::get('transformer_types/import_template',  [TransformerTypeController::class, 'importTemplate'])->name('transformer_types.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:transformer_types.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('transformer_types/bulk_delete',     [TransformerTypeController::class, 'bulkDelete'])->name('transformer_types.bulk_delete');
        Route::post('transformer_types/bulk_set_active', [TransformerTypeController::class, 'bulkSetActive'])->name('transformer_types.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:transformer_types.delete')->group(function () {
        Route::post('transformer_types/undo_last_delete', [TransformerTypeController::class, 'undoLastDelete'])->name('transformer_types.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:transformer_types.edit')->group(function () {
        Route::get('transformer_types/edit_all',         [TransformerTypeController::class, 'editAll'])->name('transformer_types.edit_all');
        Route::post('transformer_types/edit_all/update', [TransformerTypeController::class, 'editAllUpdate'])->name('transformer_types.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:transformer_types.create')->group(function () {
        Route::get('transformer_types/create', [TransformerTypeController::class, 'create'])->name('transformer_types.create');
        Route::post('transformer_types',       [TransformerTypeController::class, 'store'])->name('transformer_types.store');
        Route::post('transformer_types/{transformerType}/duplicate', [TransformerTypeController::class, 'duplicate'])->name('transformer_types.duplicate');
    });

    Route::middleware('permission:transformer_types.view')->group(function () {
        Route::get('transformer_types',                [TransformerTypeController::class, 'index'])->name('transformer_types.index');
        Route::get('transformer_types/{transformerType}',  [TransformerTypeController::class, 'show'])->name('transformer_types.show');
    });
    Route::middleware('permission:transformer_types.edit')->group(function () {
        Route::get('transformer_types/{transformerType}/edit', [TransformerTypeController::class, 'edit'])->name('transformer_types.edit');
        Route::put('transformer_types/{transformerType}',      [TransformerTypeController::class, 'update'])->name('transformer_types.update');
    });
    Route::middleware('permission:transformer_types.delete')->group(function () {
        Route::get('transformer_types/{transformerType}/delete',        [TransformerTypeController::class, 'delete'])->name('transformer_types.delete');
        Route::delete('transformer_types/{transformerType}/deleteSave', [TransformerTypeController::class, 'deleteSave'])->name('transformer_types.deleteSave');
    });
    }); // fin TransformerTypes (role:super)
});
