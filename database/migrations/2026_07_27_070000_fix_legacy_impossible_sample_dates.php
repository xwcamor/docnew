<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige las fechas de muestra imposibles que llegaron del sistema viejo.
 *
 * Son errores de tipeo del año en el origen: se perdió el prefijo "20". Al
 * migrarse tal cual quedaron muestras fechadas en el año 6 o 218, que arrastran
 * las tendencias siglos atrás y distorsionan el cálculo de período del bloque
 * IEEE (mide meses entre muestras).
 *
 * Se corrigen SOLO las que tienen lectura inequívoca, contrastadas contra las
 * demás muestras del mismo transformador:
 *
 *   #3798 trafo 89.754   0006-04-22 → 2006-04-22  (resto arranca en 2012)
 *   #3797 trafo 89.753   0006-04-22 → 2006-04-22  (mismo error, trafo hermano)
 *   #2164 trafo 162217T1 0218-02-06 → 2018-02-06  (encaja antes de 2018-07-12)
 *   fiqui #1713 trafo 162218T1 0202-02-28 → 2020-02-28
 *
 * El fiqui #1713 estuvo un tiempo sin resolver porque 0202 podía leerse 2020 o
 * 2002. Lo desempató la CROMATOGRAFÍA del mismo transformador, que tiene una
 * muestra el 2020-02-28: el laboratorio corrió las dos pruebas sobre la misma
 * toma. No es una lectura de dígitos, es un registro que existe.
 *
 * Idempotente: solo actúa si la fecha sigue siendo la incorrecta.
 */
return new class extends Migration
{
    /** [tabla, id de la muestra, fecha errónea, fecha correcta] */
    private const CORRECCIONES = [
        ['chromatographicals', 3798, '0006-04-22', '2006-04-22'],
        ['chromatographicals', 3797, '0006-04-22', '2006-04-22'],
        ['chromatographicals', 2164, '0218-02-06', '2018-02-06'],
        ['fiquis',             1713, '0202-02-28', '2020-02-28'],
    ];

    public function up(): void
    {
        foreach (self::CORRECCIONES as [$tabla, $id, $mala, $buena]) {
            DB::table($tabla)
                ->where('id', $id)
                ->whereDate('sample_date', $mala)
                ->update(['sample_date' => $buena . ' 00:00:00']);
        }
    }

    /** Reversible: devuelve la fecha original por si hiciera falta auditar. */
    public function down(): void
    {
        foreach (self::CORRECCIONES as [$tabla, $id, $mala, $buena]) {
            DB::table($tabla)
                ->where('id', $id)
                ->whereDate('sample_date', $buena)
                ->update(['sample_date' => $mala . ' 00:00:00']);
        }
    }
};
