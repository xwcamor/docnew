<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * diagnostic_datasets — datos de diagnóstico editables que antes vivían SOLO en
 * archivos JSON (fiquis_rules, ieee_c57104_limits, …). Se siembran desde esos
 * archivos y el motor los lee de aquí (con el archivo como fallback). Así se
 * editan desde el sistema (UI), no tocando archivos, y cada cambio queda auditado.
 *
 * `data` guarda el JSON completo (estructura idéntica al archivo). El editor de
 * la UI solo modifica los VALORES (umbrales), no la estructura.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('diagnostic_datasets', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();   // 'fiquis_rules', 'ieee_c57104_limits'
            $table->string('label')->nullable();
            $table->longText('data');               // JSON completo
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_datasets');
    }
};
