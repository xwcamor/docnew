<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre del motor de cromatografía:
 *
 *   result_scales      : el semáforo (rango de score -> condición + color). Editable.
 *   transformers       : el transformador. Pertenece a un customer (de tu saas-base).
 *   chromatographicals : una muestra de cromatografía (los gases medidos).
 *
 * `transformers.customer_id` apunta a la tabla `customers` que YA existe en tu
 * saas-base — así heredan tenant, permisos, etc. No se reinventa nada.
 */
return new class extends Migration {
    public function up(): void
    {
        // ---- Escala de resultado (semáforo), editable y por norma ----
        Schema::create('result_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->nullable()
                ->constrained('tests')->cascadeOnDelete();
            $table->foreignId('standard_id')->nullable()
                ->constrained('standards')->nullOnDelete();

            // Rango de score [from, to). El motor busca dónde cae el resultado.
            $table->decimal('score_from', 8, 2)->nullable(); // null = -infinito
            $table->decimal('score_to', 8, 2)->nullable();   // null = +infinito

            $table->string('condition_label', 60); // "Muy Bueno", "Bueno"…
            $table->string('color', 20);           // green | yellow | red
            $table->decimal('hi_rating', 4, 1)->nullable(); // 4,3,2,1,0 para el HI
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['test_id', 'sort_order'], 'result_scales_test_idx');
        });

        // ---- Transformadores ----
        // (Los catálogos que referencia — customer_substations, brands,
        // tap/connection/preservation — se crean antes, a propósito.)
        Schema::create('transformers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->string('serial', 100)->nullable()->index();  // num_serie
            $table->string('tag', 100)->nullable();              // num_tag / TR01

            // Relación con el cliente + subestación de su jerarquía.
            $table->foreignId('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();
            $table->foreignId('customer_substation_id')->nullable()
                ->constrained('customer_substations')->nullOnDelete();

            // Atributos que son EJES de diagnóstico (referencian catálogos).
            $table->foreignId('oil_type_id')->nullable()
                ->constrained('oil_types')->nullOnDelete();
            $table->foreignId('transformer_type_id')->nullable()
                ->constrained('transformer_types')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()
                ->constrained('brands')->nullOnDelete();
            $table->foreignId('tap_changer_type_id')->nullable()
                ->constrained('tap_changer_types')->nullOnDelete();
            $table->foreignId('connection_type_id')->nullable()
                ->constrained('connection_types')->nullOnDelete();
            $table->foreignId('transformer_preservation_id')->nullable()
                ->constrained('transformer_preservations')->nullOnDelete();

            // Datos físicos usados por las reglas.
            $table->decimal('voltage_kv', 10, 2)->nullable();  // num_vol
            $table->decimal('power_mva', 10, 2)->nullable();   // num_pot
            $table->integer('manufacture_year')->nullable();   // age
            $table->string('paper_type', 20)->nullable();      // tipo de papel
            $table->string('phases', 10)->nullable();          // nº de fases
            $table->date('oil_treated_at')->nullable();        // último tratamiento de aceite

            // Caché del último diagnóstico (se recalcula; no es la fuente de verdad).
            // El estado es `health_rating` 0-4 (neutro de idioma); la palabra se
            // traduce en la UI (diagnostics.cond_*). `health_trend` = tendencia.
            $table->decimal('health_index', 6, 2)->nullable();
            $table->unsignedTinyInteger('health_rating')->nullable()->index();
            $table->string('health_trend', 12)->nullable()->index();

            $table->foreignId('tenant_id')->nullable()->index()
                ->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'tag'], 'transformers_customer_tag_idx');
        });

        // ---- Muestras de cromatografía ----
        // Cada fila es una toma de muestra con sus 9 gases (en ppm).
        Schema::create('chromatographicals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transformer_id')->constrained('transformers')->cascadeOnDelete();
            $table->dateTime('sample_date');  // date_rehearsal (fecha + hora del muestreo)

            // Los 9 gases medidos (ppm). Nullable porque una muestra puede no traer todos.
            $table->decimal('h2', 12, 2)->nullable();   // hidrógeno
            $table->decimal('o2', 12, 2)->nullable();   // oxígeno
            $table->decimal('n2', 12, 2)->nullable();   // nitrógeno
            $table->decimal('ch4', 12, 2)->nullable();  // metano
            $table->decimal('co', 12, 2)->nullable();   // monóxido de carbono
            $table->decimal('co2', 12, 2)->nullable();  // dióxido de carbono
            $table->decimal('c2h4', 12, 2)->nullable(); // etileno
            $table->decimal('c2h6', 12, 2)->nullable(); // etano
            $table->decimal('c2h2', 12, 2)->nullable(); // acetileno

            // Caché del diagnóstico de esta muestra.
            $table->decimal('dgaf_score', 8, 4)->nullable();
            $table->string('dgaf_condition', 60)->nullable();

            $table->foreignId('tenant_id')->nullable()->index()
                ->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['transformer_id', 'sample_date'], 'chromato_transformer_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chromatographicals');
        Schema::dropIfExists('transformers');
        Schema::dropIfExists('result_scales');
    }
};
