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
 * Aqui se sembraba tambien `is_signature_approver`, marcando de antemano a los
 * cargos que «podian aprobar un plan». No filtraba nada y nunca llego a
 * filtrar: quien aprueba lo dicen los roles de la persona, no su cargo. La
 * columna se borro y la lista se quedo en lo unico que dice de verdad un cargo,
 * que es como se llama.
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
            'Técnico',
            'Supervisor',

            // Oficio.
            'Técnico electricista',
            'Electricista',
            'Mecánico',
            'Soldador',
            'Operario',
            'Ayudante',
            'Operador de equipo',
            'Conductor',

            // Mando y control en obra.
            'Capataz',
            'Supervisor de obra',
            'Supervisor HSE',
            'Prevencionista',
            'Ingeniero de campo',
            'Ingeniero residente',
            'Jefe de mantenimiento',

            // Apoyo.
            'Almacenero',
            'Vigía',
            'Topógrafo',
        ];

        $nuevos = 0;

        foreach ($cargos as $code) {
            $fila = Position::firstOrCreate(
                ['country_id' => $peru->id, 'code' => $code],
                [
                    'slug' => Str::random(22),
                    'is_active' => true,
                    'created_by' => 1,
                ],
            );

            $nuevos += (int) $fila->wasRecentlyCreated;
        }

        $this->command?->info("Cargos sembrados para Perú: {$nuevos} nuevos de " . count($cargos) . '.');
    }
}
