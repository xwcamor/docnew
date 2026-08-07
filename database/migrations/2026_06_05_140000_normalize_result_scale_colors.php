<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normaliza los colores del semáforo a 5 niveles distinguibles.
 *
 * Antes los 5 niveles (Muy Bueno..Muy Malo) usaban solo 3 colores: Muy Bueno y
 * Bueno = green; Malo y Muy Malo = red. Costaba distinguirlos. Ahora cada nivel
 * tiene su color (por rating 4..0; la escala del HI no tiene rating, va por
 * etiqueta):
 *   4 Muy Bueno → green · 3 Bueno → lime · 2 Medio → yellow · 1 Malo → orange · 0 Muy Malo → red
 *
 * Los colores viven en datos (result_scales.color); el frontend mapea token→hex.
 */
return new class extends Migration
{
    public function up(): void
    {
        $byRating = [4 => 'green', 3 => 'lime', 2 => 'yellow', 1 => 'orange', 0 => 'red'];
        foreach ($byRating as $rating => $color) {
            DB::table('result_scales')->where('hi_rating', $rating)->update(['color' => $color]);
        }

        // Escala del Índice de Salud (hi_rating NULL): se mapea por etiqueta.
        $byLabel = ['Muy Bueno' => 'green', 'Bueno' => 'lime', 'Medio' => 'yellow', 'Malo' => 'orange', 'Muy Malo' => 'red'];
        foreach ($byLabel as $label => $color) {
            DB::table('result_scales')->whereNull('hi_rating')->where('condition_label', $label)->update(['color' => $color]);
        }
    }

    public function down(): void
    {
        // Vuelve al esquema de 3 colores (lime→green, orange→red).
        DB::table('result_scales')->where('color', 'lime')->update(['color' => 'green']);
        DB::table('result_scales')->where('color', 'orange')->update(['color' => 'red']);
    }
};
