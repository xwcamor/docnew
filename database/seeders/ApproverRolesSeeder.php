<?php

namespace Database\Seeders;

use App\Models\ApproverRole;
use Database\Seeders\Concerns\ElWorkspaceDeLaInstalacion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Los roles con los que se arma un flujo de aprobacion.
 *
 * Los crea la migracion que convirtio el flujo en catalogo
 * (`2026_08_08_120000_make_approval_flow_configurable`), y una migracion NO
 * puede saber de quien son: corre antes de que exista ninguna empresa. Por eso
 * los dos que quedan —Supervisor y Supervisor HSE, despues de que
 * `2026_08_10_170000` sacara al representante de la cuadrilla del flujo—
 * salian con «Workspace: Plataforma» siendo de una sola empresa.
 *
 * Y no era solo la etiqueta: un rol global lo ven TODOS los workspaces y solo
 * el super lo puede editar, asi que el admin de la empresa que arma sus flujos
 * con esos dos roles no podia renombrar ni reordenar ninguno.
 *
 * Este sembrador corre DESPUES de TenantsSeeder, que es cuando ya se sabe la
 * respuesta, y hace dos cosas: crea los que falten y reclama para la empresa
 * los que quedaron sin dueno. Nunca toca uno que ya tenga workspace.
 */
class ApproverRolesSeeder extends Seeder
{
    use ElWorkspaceDeLaInstalacion;

    /**
     * Los dos que sostienen el flujo por defecto.
     *
     * `worker` NO esta, y es a proposito: el representante de la cuadrilla no
     * es una aprobacion —firma como trabajador— y se saco del catalogo. Volver
     * a sembrarlo aqui lo resucitaria en cada instalacion.
     */
    private const INICIALES = [
        ['code' => 'supervisor',     'name_es' => 'Supervisor',     'name_en' => 'Supervisor',     'sort_order' => 1],
        ['code' => 'hse_supervisor', 'name_es' => 'Supervisor HSE', 'name_en' => 'HSE supervisor', 'sort_order' => 2],
    ];

    public function run(): void
    {
        $workspace = $this->workspaceDeLaInstalacion();
        $nuevos = 0;
        $adoptados = 0;

        foreach (self::INICIALES as $inicial) {
            // El de la empresa manda sobre el global. El indice unico es por
            // `(lower(code), coalesce(tenant_id, 0))`, asi que los dos pueden
            // coexistir; si existen, adoptar el global chocaria contra el otro.
            // Con el propio ya puesto no hay nada que hacer.
            $propio = $workspace === null
                ? null
                : ApproverRole::withTrashed()
                    ->where('code', $inicial['code'])->where('tenant_id', $workspace)->first();

            if ($propio) {
                continue;
            }

            // Y si no, el que dejo la migracion: no tiene workspace, asi que
            // filtrando por uno no se encontraria y se crearia un duplicado.
            $rol = ApproverRole::withTrashed()->where('code', $inicial['code'])->first();

            if (! $rol) {
                ApproverRole::create($inicial + [
                    'slug'       => Str::random(22),
                    'tenant_id'  => $workspace,
                    'is_active'  => true,
                    'created_by' => 1,
                ]);
                $nuevos++;

                continue;
            }

            if ($rol->tenant_id === null && $workspace !== null) {
                $rol->forceFill(['tenant_id' => $workspace])->saveQuietly();
                $adoptados++;
            }
        }

        $de = $workspace === null
            ? 'sin workspace (la instalación tiene cero o varias empresas)'
            : "del workspace #{$workspace}";

        $this->command?->info("Roles aprobadores {$de}: {$nuevos} nuevos, {$adoptados} reasignados.");
    }
}
