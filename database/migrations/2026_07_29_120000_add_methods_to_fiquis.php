<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Condiciones de ensayo informadas por el laboratorio, tal como llegaron.
 *
 * Cierra hacia adelante un pendiente anotado en CLAUDE.md: los umbrales de
 * rigidez (`rig`) vienen del sistema Ruby SIN registro de la separación de
 * electrodos, y están rotulados 2.0 mm por deducción. D1816 admite 1 mm o 2 mm
 * y los kV de un gap y del otro no son comparables, así que el rótulo es una
 * suposición sobre datos históricos que ya no se puede verificar.
 *
 * A partir de la integración con el laboratorio el dato llega explícito por
 * muestra (`methods.rig.gap_mm`, `methods.pot.temp_c`, la norma aplicada) y se
 * guarda acá crudo. NO se usa para diagnosticar: el ruteo a la columna que
 * corresponde (rig/rig877, pot/pot100) ya lo hizo la ingesta. Esto es la
 * constancia de con qué método se midió, para poder auditar el día que se
 * revise el rótulo de los umbrales.
 *
 * Aditivo y nullable: las muestras existentes y las que se cargan por la web
 * quedan en null y nada cambia.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('fiquis', function (Blueprint $table) {
            $table->json('methods')->nullable()->after('pot100');
        });
    }

    public function down(): void
    {
        Schema::table('fiquis', function (Blueprint $table) {
            $table->dropColumn('methods');
        });
    }
};
