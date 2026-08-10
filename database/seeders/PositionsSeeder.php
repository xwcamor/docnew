<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Position;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Los cargos que se ven en obra.
 *
 * El sistema v1 traia cuatro en total —Tecnico y Supervisor para Peru,
 * Mecanico y Electrico para Brasil— y los 372 trabajadores migrados se
 * repartian entre esos dos de Peru. En una subestacion electrica eso no
 * distingue nada: el que aprueba un permiso de trabajo, el que hace la
 * maniobra y el que vigila la zanja son tres personas distintas y el plan las
 * imprime a las tres con la misma etiqueta.
 *
 * Esta lista es el punto de partida, no la verdad: el catalogo tiene su
 * pantalla y se edita. Lo que aporta es que nadie tenga que teclear catorce
 * filas antes de dar de alta al primer trabajador.
 *
 * `is_signature_approver` marca a los que pueden aprobar un plan. Hoy no
 * filtra nada —esta pendiente de decidir— pero se siembra ya con el criterio
 * correcto para que el dia que se use no haya que repasar la tabla entera.
 */
class PositionsSeeder extends Seeder
{
    public function run(): void
    {
        $peru = Country::where('iso_code', 'PE')->first();

        if (! $peru) {
            $this->command?->warn('PositionsSeeder: no hay Perú, no se siembra nada.');

            return;
        }

        $cargos = [
            // Los dos que ya venian de la v1, para que la migracion los reuse
            // en vez de duplicarlos.
            ['Técnico', false],
            ['Supervisor', true],

            // Oficio.
            ['Técnico electricista', false],
            ['Electricista', false],
            ['Mecánico', false],
            ['Soldador', false],
            ['Operario', false],
            ['Ayudante', false],
            ['Operador de equipo', false],
            ['Conductor', false],

            // Mando y control en obra.
            ['Capataz', false],
            ['Supervisor de obra', true],
            ['Supervisor HSE', true],
            ['Prevencionista', true],
            ['Ingeniero de campo', true],
            ['Ingeniero residente', true],
            ['Jefe de mantenimiento', true],

            // Apoyo.
            ['Almacenero', false],
            ['Vigía', false],
            ['Topógrafo', false],
        ];

        $nuevos = 0;

        foreach ($cargos as [$code, $apruebaFirmas]) {
            $fila = Position::firstOrCreate(
                ['country_id' => $peru->id, 'code' => $code],
                [
                    'slug' => Str::random(22),
                    'is_signature_approver' => $apruebaFirmas,
                    'is_active' => true,
                    'created_by' => 1,
                ],
            );

            $nuevos += (int) $fila->wasRecentlyCreated;
        }

        $this->command?->info("Cargos sembrados para Perú: {$nuevos} nuevos de " . count($cargos) . '.');
    }
}
