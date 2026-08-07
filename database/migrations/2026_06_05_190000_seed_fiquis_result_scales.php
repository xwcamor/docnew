<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Siembra el semáforo de fisicoquímico (fiquis) en result_scales para BD
 * existentes, de modo que sea editable en la UI de reglas. Idempotente: solo
 * inserta si la prueba fiquis aún no tiene bandas. Los valores son los mismos
 * defaults del fiquis_rules.json.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fiquisId = DB::table('tests')->where('code', 'fiquis')->value('id');
        if (!$fiquisId) {
            return;
        }
        $exists = DB::table('result_scales')->where('test_id', $fiquisId)->exists();
        if ($exists) {
            return;
        }

        $bands = [
            [null, 1.2, 'Muy Bueno', 'green',  4],
            [1.2,  1.5, 'Bueno',     'lime',   3],
            [1.5,  2.0, 'Medio',     'yellow', 2],
            [2.0,  3.0, 'Malo',      'orange', 1],
            [3.0,  null,'Muy Malo',  'red',    0],
        ];
        foreach ($bands as $i => [$from, $to, $label, $color, $rating]) {
            DB::table('result_scales')->insert([
                'test_id' => $fiquisId, 'standard_id' => null,
                'score_from' => $from, 'score_to' => $to,
                'condition_label' => $label, 'color' => $color, 'hi_rating' => $rating,
                'sort_order' => $i + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $fiquisId = DB::table('tests')->where('code', 'fiquis')->value('id');
        if ($fiquisId) {
            DB::table('result_scales')->where('test_id', $fiquisId)->delete();
        }
    }
};
