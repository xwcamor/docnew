<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOC APP — que formatos exige UN plan concreto.
 *
 * El valor por defecto lo sigue poniendo el tipo de trabajo
 * (`work_type_form_templates`), y eso no cambia: es lo que evita que un
 * supervisor con prisa se olvide del AST. Esta tabla guarda solo la DIFERENCIA
 * respecto de ese valor por defecto, no la lista completa:
 *
 *   - `is_included = false` → el tipo de trabajo lo exige pero en este plan no
 *     aplica (una charla de 20 minutos no necesita el inventario de herramienta).
 *   - `is_included = true`  → formato extra, solo para este plan.
 *
 * Un plan sin filas aquí se comporta exactamente como antes. Guardar la lista
 * completa habría congelado el plan: cambiar el estándar de un tipo de trabajo
 * ya no alcanzaría a los planes abiertos, que es justo lo contrario de lo que
 * se busca cuando se corrige un estándar de seguridad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_plan_form_templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('work_plan_id')->constrained('work_plans')->cascadeOnDelete();
            $table->foreignId('form_template_id')->constrained('form_templates')->restrictOnDelete();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_included')->default(true)
                ->comment('false = excluido del plan aunque el tipo de trabajo lo exija');
            $table->timestamps();
            $table->unique(['work_plan_id', 'form_template_id'], 'work_plan_form_templates_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_plan_form_templates');
    }
};
