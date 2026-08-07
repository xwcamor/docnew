<?php

use App\Http\Controllers\BusinessManagement\ReportApprovalController;
use App\Http\Controllers\BusinessManagement\ReportRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Aprobaciones — bandeja de firmas pendientes del usuario (etapa 2 de firmas)
|--------------------------------------------------------------------------
| Sin permiso especial: cada usuario solo ve/actúa sobre SUS aprobaciones.
| El menú solo aparece para quien es firmante (tiene pendientes).
*/
Route::prefix('approvals')->name('approvals.')->group(function () {
    Route::get('/', [ReportApprovalController::class, 'index'])->name('index');
    Route::get('instance/{instance}/draft', [ReportApprovalController::class, 'draft'])->name('draft');
    Route::post('{approval}/approve', [ReportApprovalController::class, 'approve'])->name('approve')->middleware('throttle:20,1');
    Route::post('{approval}/reject',  [ReportApprovalController::class, 'reject'])->name('reject')->middleware('throttle:20,1');
});

/*
|--------------------------------------------------------------------------
| Mis solicitudes — lo que el usuario envió a aprobación (seguimiento)
|--------------------------------------------------------------------------
| Sin permiso especial: cada usuario solo ve/actúa sobre SUS solicitudes.
*/
Route::prefix('my-requests')->name('report_requests.')->group(function () {
    Route::get('/', [ReportRequestController::class, 'index'])->name('index');
    Route::get('instance/{instance}/download', [ReportRequestController::class, 'download'])->name('download');
    Route::post('{reportRequest}/resubmit', [ReportRequestController::class, 'resubmit'])->name('resubmit')->middleware('throttle:20,1');
});
