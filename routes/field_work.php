<?php

use App\Http\Controllers\FieldWork\FormSubmissionController;
use App\Http\Controllers\FieldWork\SignatureController;
use Illuminate\Support\Facades\Route;

/*
 * Trabajo en obra: llenar los formatos del plan del dia y firmarlos.
 *
 * Todo pasa por permiso. La firma exige `form_submissions.sign`; autorizar una
 * firma sin reconocimiento o ver las evidencias exige `signature_events.review`.
 * Ya no hay bandeja de revision: la firma sin reconocimiento vale desde que se
 * toma —el PDF imprime su metodo, que es la verdad completa— y anularla es una
 * decision excepcional que quedo SOLO SUPER, desde el album de las firmas.
 */
Route::name('field_work.')->prefix('field_work')->group(function () {
    // Formatos del plan
    Route::middleware('permission:form_submissions.view')->group(function () {
        Route::get('work_plans/{work_plan}/forms', [FormSubmissionController::class, 'index'])
            ->name('forms.index');
        Route::get('work_plans/{work_plan}/forms/{form_template}', [FormSubmissionController::class, 'open'])
            ->name('forms.open');

        // Ver un adjunto. Va con el permiso de VER y no con el de exportar: es
        // mirar lo que se acaba de subir en la propia pantalla de llenado, no
        // sacar el documento del sistema. Los ficheros viven en el disco
        // `local`, fuera de `public/`, asi que esta es la unica puerta.
        Route::get('submissions/{form_submission}/attachments/{attachment}', [FormSubmissionController::class, 'attachment'])
            ->name('forms.attachment');
    });

    // El PDF firmado saca el documento del sistema, asi que pide el mismo
    // permiso que el resto de exports.
    //
    // Y ADEMAS `people.view_private_info`: el PDF lleva dentro las firmas y los
    // DNI completos de toda la cuadrilla. Sin esto, el enmascarado de la
    // pantalla no servia de nada — quien quisiera los documentos solo tenia que
    // descargarse el PDF. Es el documento legal y va entero; lo que se controla
    // es quien se lo lleva.
    //
    // Ojo con el efecto: el supervisor de obra y el auditor HSE tienen el
    // permiso de exportar pero NO el de datos privados, asi que dejan de poder
    // descargarlo. Si en un workspace hace falta, se le concede al perfil.
    // Se enlaza por slug, que es el identificador que sale impreso en el pie.
    Route::middleware(['permission:form_submissions.export', 'permission:people.view_private_info'])->group(function () {
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
        // Quitar el que se subio por error. Mismo permiso que subirlo, y el
        // guardia de «en lo confirmado no se escribe» lo pone el servicio.
        Route::delete('submissions/{form_submission}/attachments/{attachment}', [FormSubmissionController::class, 'detach'])
            ->name('forms.detach');
        Route::post('submissions/{form_submission}/confirm', [FormSubmissionController::class, 'confirm'])
            ->name('forms.confirm');

        // Volver a abrir un formato ya confirmado, para corregirlo.
        //
        // Va con el mismo permiso que llenarlo, y a proposito: el que se
        // equivoca al teclear es el que esta llenando el formato, y si tuviera
        // que buscar a un supervisor para arreglar una fecha acabaria llamando
        // por radio en vez de corregirlo. Lo que de verdad protege el documento
        // no es este permiso, es el plan: una vez cerrado —firmado por quien
        // autoriza— el servicio ya no deja reabrir nada.
        Route::post('submissions/{form_submission}/reopen', [FormSubmissionController::class, 'reopen'])
            ->name('forms.reopen');
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

    // El album de las firmas: plan, trabajador y la foto de ese momento.
    //
    // SOLO SUPER, por rol y no por permiso: es una pantalla que enseña fotos
    // de personas y el dueño del producto la quiso cerrada al maximo. La foto
    // se sirve por la ruta de evidencia de abajo, que al super se le abre por
    // el `Gate::before`.
    Route::middleware('role:super')->group(function () {
        Route::get('signature_photos', [SignatureController::class, 'photos'])
            ->name('signatures.photos');

        // Anular una firma. Es lo que quedo de la antigua bandeja de revision:
        // la firma sin reconocimiento ya vale sola —el metodo impreso en el PDF
        // cuenta la verdad— y lo unico que hace falta poder hacer es tumbar una
        // que se sabe falsa. Eso es una decision excepcional y va con el mismo
        // candado que el album desde el que se toma.
        Route::post('signatures/{signature_event}/resolve', [SignatureController::class, 'resolve'])
            ->name('signatures.resolve');
    });

    // Evidencias y enrolamiento: el permiso mas sensible del sistema, porque
    // es quien puede mirar las fotos de las caras.
    Route::middleware('permission:signature_events.review')->group(function () {
        Route::post('people/{person}/unenroll', [SignatureController::class, 'unenroll'])
            ->name('signatures.unenroll');
        Route::get('evidence/{evidence_file}', [SignatureController::class, 'evidence'])
            ->name('signatures.evidence');
    });
});
