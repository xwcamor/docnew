<?php

use App\Http\Controllers\Communication\InboxController;
use App\Http\Controllers\Communication\MessageController;
use Illuminate\Support\Facades\Route;

Route::prefix('communication')->name('communication.')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Inbox — todos los users autenticados
    |--------------------------------------------------------------------------
    | El user solo ve mensajes donde es recipient (resuelto en message_recipients).
    */
    Route::get('inbox',                [InboxController::class, 'index'])->name('inbox.index');
    // Polling JSON del bell (downloads + notifs + mensajes). Antes de {slug}.
    Route::get('inbox/poll',           [InboxController::class, 'poll'])->name('inbox.poll');
    Route::post('inbox/mark-all-read', [InboxController::class, 'markAllRead'])->name('inbox.mark_all_read');
    Route::get('inbox/{slug}',         [InboxController::class, 'show'])->name('inbox.show');
    Route::post('inbox/{slug}/reply',  [InboxController::class, 'reply'])->name('inbox.reply');

    /*
    |--------------------------------------------------------------------------
    | Messages CRUD — super only
    |--------------------------------------------------------------------------
    | Creacion, edicion, publicacion y baja de mensajes. Solo super.
    */
    Route::middleware('role:super')->group(function () {
        Route::get('messages',                       [MessageController::class, 'index'])->name('messages.index');
        Route::get('messages/create',                [MessageController::class, 'create'])->name('messages.create');
        Route::post('messages',                      [MessageController::class, 'store'])->name('messages.store');
        Route::get('messages/{slug}/edit',           [MessageController::class, 'edit'])->name('messages.edit');
        Route::put('messages/{slug}',                [MessageController::class, 'update'])->name('messages.update');
        Route::get('messages/{slug}/delete',         [MessageController::class, 'delete'])->name('messages.delete');
        Route::delete('messages/{slug}/deleteSave',  [MessageController::class, 'deleteSave'])->name('messages.deleteSave');
        // Show queda al final para que las rutas mas especificas matcheen primero.
        Route::get('messages/{slug}',                [MessageController::class, 'show'])->name('messages.show');
    });
});
