<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Position;
use Database\Seeders\Concerns\ElWorkspaceDeLaInstalacion;
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
 *
 * Los cargos son DEL WORKSPACE, no de la plataforma. Antes salian los veintiuno
 * con «Workspace: Plataforma» y no por una decision: este sembrador corria
 * ANTES que TenantsSeeder, asi que cuando escribia no habia todavia ninguna
 * empresa a la que asignarselos. Y lo global solo lo edita el super, con lo
 * cual el admin de la empresa que los usa a diario no podia tocar ni uno.
 */
class PositionsSeeder extends Seeder
{
    use ElWorkspaceDeLaInstalacion;

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

        $workspace = $this->workspaceDeLaInstalacion();
        $nuevos = 0;
        $adoptados = 0;

        foreach ($cargos as $code) {
            $fila = Position::firstOrCreate(
                ['country_id' => $peru->id, 'code' => $code],
                [
                    'slug' => Str::random(22),
                    'tenant_id' => $workspace,
                    'is_active' => true,
                    'created_by' => 1,
                ],
            );

            $nuevos += (int) $fila->wasRecentlyCreated;

            // Y se reclaman los que ya estaban sin dueno de una instalacion
            // anterior. Solo esos: un cargo que ya es de una empresa no se
            // mueve, y con varias empresas no se adivina de quien es nada.
            if (! $fila->wasRecentlyCreated && $fila->tenant_id === null && $workspace !== null) {
                $fila->forceFill(['tenant_id' => $workspace])->saveQuietly();
                $adoptados++;
            }
        }

        $total = count($cargos);
        $de = $workspace === null
            ? 'sin workspace (la instalación tiene cero o varias empresas)'
            : "del workspace #{$workspace}";

        $this->command?->info("Cargos sembrados para Perú {$de}: {$nuevos} nuevos de {$total}, {$adoptados} reasignados.");
    }
}
