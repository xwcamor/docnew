<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Manda a la papelera dos ensayos fisicoquímicos sin ningún parámetro medido.
 *
 * Son registros del sistema viejo que quedaron abiertos y nunca se completaron:
 * rigidez y factor de potencia sin dato, y acidez, tensión interfacial y agua en
 * cero exacto. El usuario los revisó en la ficha del trafo 750005-01 y confirmó
 * que están vacíos — una tensión interfacial de 0 mN/m no existe físicamente.
 *
 *   #2567  trafo 750005-01  2005-09-29
 *   #2562  trafo 750005-01  2006-11-10
 *
 * Es borrado LÓGICO, no físico: si mañana aparece el informe de laboratorio con
 * los valores, se restauran desde la papelera. No se pierde nada.
 *
 * (Una tercera fila igual, #329 del trafo 81-21797, ya estaba borrada desde
 * 2022 — no se toca.)
 *
 * Idempotente: solo actúa sobre las que siguen activas.
 */
return new class extends Migration
{
    private const VACIAS = [2567, 2562];

    public function up(): void
    {
        DB::table('fiquis')
            ->whereIn('id', self::VACIAS)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }

    public function down(): void
    {
        DB::table('fiquis')->whereIn('id', self::VACIAS)->update(['deleted_at' => null]);
    }
};
