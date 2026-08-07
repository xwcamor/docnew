<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();

            // tenant_id NULLABLE — super (creator de la plataforma) no
            // pertenece a ningún tenant. Workers y admins sí lo tienen.
            $table->foreignId('tenant_id')->nullable()->constrained();

            $table->foreignId('country_id')->constrained();
            $table->foreignId('locale_id')->constrained();

            // TZ del usuario (override del tenant). Si null, hereda del tenant
            // o del country del propio user (en ese orden).
            $table->string('timezone', 64)->nullable();

            $table->string('email')->unique();
            $table->string('google_id')->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('name');
            $table->string('photo')->nullable();

            // Firma manuscrita (imagen privada) + consentimiento de auto-firma
            // como aprobador de informes.
            $table->string('signature', 255)->nullable();
            $table->boolean('auto_sign_reports')->default(false);

            // Aceptación REGISTRADA de Términos y Privacidad (LPDP: el
            // consentimiento debe ser demostrable). Versión aceptada + cuándo;
            // si la versión vigente (setting legal.terms_version) cambia, el
            // front vuelve a pedir aceptación.
            $table->string('terms_accepted_version', 20)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();

            // Onboarding tours completados/skipeados por usuario.
            // Shape: { "regions": "2026-05-07T10:00:00Z", "languages": null, ... }
            $table->json('module_tours')->nullable();

            $table->rememberToken();
            $table->timestamp('email_verified_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();

            // Performance indexes — listado + trash + filtros activos.
            // Users ya tiene email/slug/google_id UNIQUE y FK indexes implicitos.
            $table->index(['tenant_id', 'is_active', 'created_at'], 'idx_users_tenant_active_created');
            $table->index('created_at', 'idx_users_created_at');
            $table->index('updated_at', 'idx_users_updated_at');
            $table->index('deleted_at', 'idx_users_deleted_at');
            $table->index('is_active',  'idx_users_is_active');
        });

        // FK circular tenants.system_user_id → users.id.
        // SQLite no soporta ALTER TABLE ADD FK cuando la tabla tiene partial
        // unique indexes con expressions (tenants_name_unique_active) — al
        // recrear pierde la expression. Skip en SQLite (testing); integridad
        // real se garantiza en Postgres (producción).
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('tenants', function (Blueprint $table) {
                $table->foreign('system_user_id')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropForeign(['system_user_id']);
            });
        }

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
