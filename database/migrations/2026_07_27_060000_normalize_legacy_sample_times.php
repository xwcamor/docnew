<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las muestras migradas del sistema viejo quedaron a las 05:00.
 *
 * No es una hora de laboratorio: la app guarda en UTC y Perú es UTC−5, así que
 * la medianoche local del sistema viejo se convirtió en 05:00 UTC. Es el mismo
 * "sin hora" que las que ya están en 00:00, solo que desplazado.
 *
 * Se normalizan a 00:00 SOLO las que están exactamente en 05:00. Las que tienen
 * una hora real cargada por alguien (20:25, 18:00, 10:00…) NO se tocan: esas sí
 * son un dato del ensayo.
 *
 * Idempotente: correrla de nuevo no encuentra nada que cambiar.
 */
return new class extends Migration
{
    /** Tablas de ensayos con fecha de muestra. */
    private const TABLAS = ['chromatographicals', 'fiquis', 'furanos', 'fpots'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($tabla)) {
                continue;
            }

            DB::table($tabla)
                ->whereRaw($this->soloHora($tabla) . " = '05:00'")
                ->update(['sample_date' => DB::raw($this->aMedianoche())]);
        }
    }

    /**
     * No se revierte: volver a poner 05:00 reintroduciría el artefacto de la
     * migración, y no hay forma de distinguir cuáles habían sido normalizadas.
     */
    public function down(): void
    {
    }

    private function soloHora(string $tabla): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "to_char(sample_date, 'HH24:MI')"
            : "strftime('%H:%M', sample_date)";
    }

    private function aMedianoche(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "date_trunc('day', sample_date)"
            : "date(sample_date)";
    }
};
