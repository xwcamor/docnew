<?php

use Illuminate\Support\Facades\Route;

// Legal Management
Route::prefix('legal_management')->name('legal_management.')->group(function () {
    Route::view('terms', 'legal_management.terms.index')->name('terms');
    Route::view('privacy', 'legal_management.privacy.index')->name('privacy');
});