<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plans — tabla de planes editables desde UI (en lugar de config/features.php).
 *
 * Permite que super cambie pricing/límites sin redeploy:
 *   - max_users por plan
 *   - max_records_per_module por plan
 *   - features bool (api_access, export_pdf, branding, etc.) como JSON
 *   - precio mensual/anual (futuro)
 *
 * `slug` es el identificador estable usado en `tenants.plan` y
 * `subscriptions.plan` (ej. "free", "solo", "pro", "enterprise").
 *
 * `features` es un JSON con keys booleanas, igual shape que config/features.php
 * tenía. Ejemplo:
 *   { "api_access": true, "export_pdf": true, "branding": false, ... }
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();   // free, solo, pro, enterprise
            $table->string('name', 100);             // "Free", "Solo", "Pro", "Enterprise"
            $table->string('tagline', 200)->nullable(); // "Para probar el sistema"
            $table->string('icon', 60)->nullable();     // AntD icon name (ej. CrownOutlined)
            $table->string('color', 30)->nullable();    // paleta AntD (default/blue/green/gold/...)
            $table->integer('sort_order')->default(0);  // 1=free, 2=solo, 3=pro, 4=ent

            // Límites numéricos. -1 = ilimitado (mapea a PHP_INT_MAX en runtime).
            $table->integer('max_users')->default(1);
            $table->integer('max_records_per_module')->default(100);
            $table->integer('export_rate_limit')->default(1);

            // Nivel de soporte (community | email | priority). Atributo
            // descriptivo del plan, no un gate tecnico. Se muestra en el modal
            // "Ver planes" y en el detalle del plan.
            $table->string('support_level', 20)->default('community');

            // Features bool — JSON map { 'api_access': true, 'export_pdf': false, ... }
            $table->json('features')->nullable();

            // Pricing (informativo por ahora, billing real es manual).
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->decimal('price_yearly', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);  // false = oculto del modal "Ver planes"

            // Audit + soft-delete (patrón Regions). Un plan no se elimina hard
            // hasta que super lo confirme en force-delete con motivo.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
