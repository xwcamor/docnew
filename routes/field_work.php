<?php

use App\Http\Controllers\FieldWork\FormSubmissionController;
use App\Http\Controllers\FieldWork\SignatureController;
use Illuminate\Support\Facades\Route;

/*
 * Trabajo en obra: llenar los formatos del plan del dia y firmarlos.
 *
 * Todo pasa por permiso. La firma exige `form_submissions.sign`; autorizar una
 * firma sin reconocimiento o resolver la bandeja exige `signature_events.review`.
 */
Route::name('field_work.')->prefix('field_work')->group(function () {
    // Formatos del plan
    Route::middleware('permission:form_submissions.view')->group(function () {
        Route::get('work_plans/{work_plan}/forms', [FormSubmissionController::class, 'index'])
            ->name('forms.index');
        Route::get('work_plans/{work_plan}/forms/{form_template}', [FormSubmissionController::class, 'open'])
            ->name('forms.open');
    });

    Route::middleware('permission:form_submissions.edit')->group(function () {
        Route::post('submissions/{form_submission}/answers', [FormSubmissionController::class, 'answer'])
            ->name('forms.answer');
        Route::post('submissions/{form_submission}/attachments', [FormSubmissionController::class, 'attach'])
            ->name('forms.attach');
        Route::post('submissions/{form_submission}/confirm', [FormSubmissionController::class, 'confirm'])
            ->name('forms.confirm');
    });

    // Firma
    Route::middleware('permission:form_submissions.sign')->group(function () {
        Route::get('work_plans/{work_plan}/sign', [SignatureController::class, 'show'])
            ->name('signatures.show');
        Route::get('people/{person}/descriptors', [SignatureController::class, 'descriptors'])
            ->name('signatures.descriptors')
            ->middleware('throttle:30,1');
        Route::post('signatures', [SignatureController::class, 'store'])
            ->name('signatures.store')
            ->middleware('throttle:60,1');
    });

    // Bandeja de revision
    Route::middleware('permission:signature_events.review')->group(function () {
        Route::get('signatures/review', [SignatureController::class, 'review'])
            ->name('signatures.review');
        Route::post('signatures/{signature_event}/resolve', [SignatureController::class, 'resolve'])
            ->name('signatures.resolve');
        Route::get('evidence/{evidence_file}', [SignatureController::class, 'evidence'])
            ->name('signatures.evidence');
    });
});
