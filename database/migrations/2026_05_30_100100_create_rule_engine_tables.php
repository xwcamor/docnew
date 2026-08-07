<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El corazón del motor de diagnóstico: las reglas como DATOS, no como código.
 *
 * Esto reemplaza los ~180 métodos repetidos del sistema viejo. La idea:
 *
 *   variables       : qué se mide (H2, CH4, ratios…). Filas, no columnas fijas.
 *   rule_sets       : un "cuadro" por combinación (cromas + mineral + potencia + norma)
 *   rules           : cada regla dentro de un cuadro (su score y peso)
 *   rule_conditions : las condiciones de cada regla (variable, operador, valor)
 *
 * El ajuste sobre > y < : en vez de columnas rígidas desde/hasta, cada condición
 * guarda OPERADOR + VALOR. Así se cubre todo: rangos, mayores, menores, igualdades.
 *   - "H2 <= 150"          -> 1 condición (op '<=', valor 150)
 *   - "150 < H2 <= 200"    -> 2 condiciones (op '>' 150 ; op '<=' 200)
 *   - "R1 > 1.0" (Rogers)  -> 1 condición (op '>', valor 1.0)
 */
return new class extends Migration {
    public function up(): void
    {
        // ---- Variables: qué se mide (ejes y magnitudes) ----
        Schema::create('variables', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();      // h2, ch4, c2h4, ratio_ch4_h2…
            $table->string('name', 120);               // "Hidrógeno (H2)"
            $table->string('unit', 30)->nullable();    // ppm, %, etc.
            $table->string('kind', 30)->default('measure'); // measure | ratio | attribute
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // ---- Conjuntos de reglas (el "cuadro" agrupado) ----
        // Un rule_set = todas las reglas que aplican para una combinación dada.
        // Ej: cromas + mineral + potencia, bajo IEC 60599.
        Schema::create('rule_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->constrained('tests')->cascadeOnDelete();
            $table->foreignId('oil_type_id')->nullable()
                ->constrained('oil_types')->nullOnDelete();
            $table->foreignId('transformer_type_id')->nullable()
                ->constrained('transformer_types')->nullOnDelete();
            $table->foreignId('standard_id')->nullable()
                ->constrained('standards')->nullOnDelete();

            // Peso total para promediar el score (18 mineral, 17 vegetales…).
            // Si es null, el motor lo calcula sumando los pesos de las reglas.
            $table->decimal('total_weight', 8, 2)->nullable();

            $table->string('label', 150)->nullable();  // "Cromas · Mineral · Potencia"
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['test_id', 'oil_type_id', 'transformer_type_id'], 'rule_sets_combo_idx');
        });

        // ---- Reglas: cada escalón/regla dentro de un cuadro ----
        // Una regla produce un `score` (y aporta su `weight` al promedio) cuando
        // TODAS sus condiciones se cumplen. `priority` define el orden de evaluación
        // (la primera que cumple gana, para los escalones).
        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_set_id')->constrained('rule_sets')->cascadeOnDelete();

            // Variable principal de la regla (el gas/parámetro que puntúa).
            $table->foreignId('variable_id')->nullable()
                ->constrained('variables')->nullOnDelete();

            $table->decimal('score', 8, 2)->default(0);   // puntaje si se cumple
            $table->decimal('weight', 8, 2)->default(1);  // peso del parámetro (Wi)
            $table->integer('priority')->default(0);      // orden de evaluación

            // Resultado textual opcional (para diagnósticos tipo Rogers/IEC).
            $table->string('result_label', 150)->nullable(); // "Arco de alta energía"
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['rule_set_id', 'priority'], 'rules_set_priority_idx');
        });

        // ---- Condiciones de cada regla (variable + operador + valor) ----
        // Varias condiciones de la misma regla se evalúan con AND.
        // Aquí es donde viven los >, <, >=, <=, =  (el ajuste que pediste).
        Schema::create('rule_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('rules')->cascadeOnDelete();
            $table->foreignId('variable_id')->constrained('variables')->cascadeOnDelete();

            // Operadores soportados: '<', '<=', '>', '>=', '=', '!='
            $table->string('operator', 4);
            $table->decimal('value', 14, 4);

            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('rule_id', 'rule_conditions_rule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_conditions');
        Schema::dropIfExists('rules');
        Schema::dropIfExists('rule_sets');
        Schema::dropIfExists('variables');
    }
};
