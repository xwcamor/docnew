<?php

use App\Http\Controllers\Tools\DuvalCalculatorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tools (solo-super)
|--------------------------------------------------------------------------
| Herramientas de cálculo/diagnóstico independientes de un trafo. Solo super.
| Hoy: calculador Duval (triángulos + pentágonos) con live calculation.
*/
Route::middleware('role:super')->prefix('tools')->name('tools.')->group(function () {
    Route::get('duval',           [DuvalCalculatorController::class, 'index'])->name('duval.index');
    Route::get('duval/calculate', [DuvalCalculatorController::class, 'calculate'])->name('duval.calculate');
});
