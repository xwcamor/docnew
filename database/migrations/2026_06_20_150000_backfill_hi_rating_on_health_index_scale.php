<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pobla hi_rating en las bandas del semáforo del Índice de Salud (test_id NULL),
 * que antes lo derivaban del TEXTO de la condición. Ahora el rating vive en la
 * banda → renombrar la condición ya no rompe el cálculo del health_rating.
 *
 * Usa el mapa canónico (mismo que el código viejo) por condition_label.
 */
return new class extends Migration {
    public function up(): void
    {
        $map = ['Muy Malo' => 0, 'Malo' => 1, 'Medio' => 2, 'Bueno' => 3, 'Muy Bueno' => 4];
        foreach ($map as $label => $rating) {
            DB::table('result_scales')
                ->whereNull('test_id')
                ->where('condition_label', $label)
                ->update(['hi_rating' => $rating]);
        }
    }

    public function down(): void
    {
        DB::table('result_scales')->whereNull('test_id')->update(['hi_rating' => null]);
    }
};
