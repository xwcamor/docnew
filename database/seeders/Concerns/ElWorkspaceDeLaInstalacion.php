<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * De quién son los catálogos que se siembran al instalar.
 *
 * Los catálogos con `BelongsToTenantOrGlobal` admiten dos dueños: un workspace
 * concreto, o nadie —`tenant_id` nulo— que en ese trato significa «de la
 * plataforma, lo ve todo el mundo». Los cargos y los roles aprobadores se
 * sembraban sin dueño, y no porque se hubiera decidido: `PositionsSeeder`
 * corría ANTES que `TenantsSeeder`, así que cuando se escribían todavía no
 * existía ninguna empresa a la que asignárselos. Los roles aprobadores, peor:
 * los inserta una migración, y una migración nunca puede saberlo.
 *
 * El resultado en pantalla es lo que se ve como «Workspace: Plataforma» en
 * veintiún cargos y dos roles que sólo son de una empresa. Y no es sólo una
 * etiqueta: lo global lo ven TODOS los workspaces y sólo el super lo puede
 * editar, así que el admin de la empresa que los usa a diario no puede tocar
 * ni uno.
 *
 * La regla, y su límite: si la instalación tiene **una sola** empresa, los
 * catálogos sembrados son suyos. Con varias no se adivina —cualquier reparto
 * sería inventado— y se quedan globales, que es el comportamiento de antes.
 */
trait ElWorkspaceDeLaInstalacion
{
    /**
     * El id del único workspace, o null si hay cero o más de uno.
     *
     * Se pregunta con `DB::table()` y no con el modelo: los modelos con ámbito
     * de workspace filtran por el usuario en sesión, y en un seeder no hay
     * ninguno.
     */
    protected function workspaceDeLaInstalacion(): ?int
    {
        $ids = DB::table('tenants')->orderBy('id')->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
