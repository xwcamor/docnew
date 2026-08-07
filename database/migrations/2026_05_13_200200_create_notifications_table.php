<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notifications — tabla estándar de Laravel para el channel `database`.
 *
 * Se usa con el trait Notifiable en User (ya incluido por defecto en
 * Authenticatable). Permite notificaciones in-app vía
 * `$user->notify(new SomeNotification())`.
 *
 * El módulo Automations dispara estas notificaciones desde
 * InAppNotificationAction. En el futuro podemos agregar email, push, etc.
 * como canales adicionales sin tocar el storage.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('notifications')) return;

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
