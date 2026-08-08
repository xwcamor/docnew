<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los tipos de documento, que estaban escritos a mano dentro del codigo.
 *
 * `StorePersonRequest` traia `Rule::in(['DNI', 'CE', 'PASAPORTE'])`, asi que
 * dar de alta a alguien con PTP —el permiso temporal de permanencia, que en
 * Peru llevan miles de venezolanos— pasaba por tocar PHP y desplegar.
 *
 * En la v1 esta tabla no existe: lo unico que hay es `settings.num_doc_minimum`,
 * UN numero por pais (sembrado en 7 para los siete). Ni tipos ni longitudes.
 * Esto no reproduce nada de alla; lo arregla.
 *
 * Por pais, porque el documento lo es: el DNI peruano tiene 8 digitos y el
 * chileno no se llama asi. `min_length`/`max_length` son **ayuda de
 * validacion**, no la condicion para buscar: el buscador de la cuadrilla se
 * apoya en la coincidencia exacta del numero, no en su longitud, porque el
 * volcado tiene dos peruanos con DNI de 7 caracteres y una regla de longitud
 * los dejaria fuera para siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_types')) {
            return;
        }

        Schema::create('document_types', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 22)->unique();
            $t->foreignId('country_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();

            // `code` es lo que se guarda en `people.doc_type` y lo que se lee en
            // la ficha: DNI, CE, PTP. `name` es el nombre largo, para el
            // desplegable y el PDF.
            $t->string('code', 20);
            $t->string('name', 120)->nullable();

            $t->unsignedSmallInteger('min_length')->nullable();
            $t->unsignedSmallInteger('max_length')->nullable();

            $t->boolean('is_active')->default(true);

            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('deleted_by')->nullable();
            $t->text('deleted_description')->nullable();

            // Candado, como el resto de catalogos de obra.
            $t->timestamp('locked_at')->nullable()->index();
            $t->unsignedBigInteger('locked_by')->nullable();
            $t->string('lock_scope', 10)->nullable();

            $t->timestamps();
            $t->softDeletes();

            // El mismo codigo no se repite dentro de un pais. Los borrados no
            // cuentan: liberan el codigo, como en los demas catalogos.
            $t->unique(['country_id', 'code', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
