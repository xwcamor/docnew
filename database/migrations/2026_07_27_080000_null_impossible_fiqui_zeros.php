<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los ceros de rigidez y factor de potencia son "no medido", no una medición.
 *
 * La migración del sistema viejo guardó como 0 lo que en realidad era un ensayo
 * sin ese parámetro (en `fiquis` no hay NINGÚN NULL en rigidez: 0 de 7476). El
 * motor hacía lo correcto con un dato incorrecto: veía 0, lo comparaba contra el
 * mínimo de la norma y marcaba fuera de norma, bajando el índice de salud de
 * transformadores sanos.
 *
 * Físicamente una rigidez de 0 kV no existe (sería un aceite en cortocircuito) y
 * un factor de potencia de exactamente 0.000 % no es medible. Un valor ausente
 * NO penaliza, que es el comportamiento correcto.
 *
 * Alcance deliberado: SOLO los cuatro campos con doble método (las dos rigideces
 * y el factor a dos temperaturas). Los ceros de acidez, agua y tensión
 * interfacial NO se tocan: ahí el ensayo siempre midió y el cero puede ser real.
 *
 * Idempotente: si ya son NULL, no hay nada que cambiar.
 */
return new class extends Migration
{
    /** Campos donde el cero significa "no medido". */
    private const CAMPOS = ['rig', 'rig877', 'pot', 'pot100'];

    public function up(): void
    {
        foreach (self::CAMPOS as $campo) {
            DB::table('fiquis')->where($campo, 0)->update([$campo => null]);
        }
    }

    /**
     * No se revierte: volver a poner 0 reintroduciría el falso "fuera de norma",
     * y no habría forma de distinguir los que ya eran NULL de origen.
     */
    public function down(): void
    {
    }
};
