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

    // El PDF firmado saca el documento del sistema, asi que pide el mismo
    // permiso que el resto de exports: lo tienen el supervisor de obra y el
    // auditor HSE, no el usuario de campo que solo llena el formato.
    // Se enlaza por slug, que es el identificador que sale impreso en el pie.
    Route::middleware('permission:form_submissions.export')->group(function () {
        Route::get('submissions/{form_submission}/pdf', [FormSubmissionController::class, 'pdf'])
            ->name('forms.pdf');

        // Y el expediente entero del plan: el `plan_exports_controller` de la
        // v1. Mismo permiso, porque saca los mismos documentos del sistema —
        // los cuatro de golpe en vez de uno.
        Route::get('plans/{work_plan}/zip', [FormSubmissionController::class, 'zip'])
            ->name('forms.zip');
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
        // Enrolamiento: hace falta antes de la primera firma.
        Route::post('people/{person}/enroll', [SignatureController::class, 'enroll'])
            ->name('signatures.enroll')
            ->middleware('throttle:10,1');

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
        Route::post('people/{person}/unenroll', [SignatureController::class, 'unenroll'])
            ->name('signatures.unenroll');
        Route::get('evidence/{evidence_file}', [SignatureController::class, 'evidence'])
            ->name('signatures.evidence');
    });
});
