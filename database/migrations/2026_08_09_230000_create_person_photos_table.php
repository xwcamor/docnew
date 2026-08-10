<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto de referencia de cada persona: la que sirve para reconocerla.
 *
 * En el sistema anterior `workers.photo` guardaba la buena, la que el
 * administrador subia a mano cuando la que se capturaba en obra salia
 * irreconocible —a contraluz, con casco, en movimiento—. Al portar el modulo se
 * trajeron las firmas (`person_signatures`) y esa foto se quedo atras, asi que
 * hoy solo existen las capturas de cada firma, que son justo las malas.
 *
 * Misma forma que `person_signatures` y por la misma razon: **versionada**. Al
 * reemplazar una foto la anterior se cierra con `valid_to` en vez de
 * sobrescribirse, porque un plan firmado el año pasado tiene que poder seguir
 * enseñando la cara con la que se le identifico entonces.
 *
 * `source` dice de donde salio, y eso cambia lo que significa:
 *   - `uploaded`  la subio el administrador. Es la buena.
 *   - `captured`  es la primera que se le tomo al firmar, guardada
 *                 automaticamente porque no tenia ninguna. Mejor que nada.
 *   - `migrated`  viene de `workers.photo` del sistema anterior.
 *
 * No lleva `tenant_id`: cuelga de la persona, que ya es de un workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('sha256', 64)->index();
            $table->string('source', 20)->comment('uploaded, captured, migrated');
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->timestamps();
            $table->index(['person_id', 'valid_to'], 'idx_person_photos_current');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_photos');
    }
};
