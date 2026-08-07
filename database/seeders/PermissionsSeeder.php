<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * @deprecated  Replaced by RolesAndPermissionsSeeder.
 *
 * The old PermissionsSeeder used the action list ['view', 'create', 'edit',
 * 'delete', 'export', 'edit_all'] which is missing 'show' and is inconsistent
 * with the current canonical action set in RolesAndPermissionsSeeder
 * (['view','show','create','edit','delete','export']).
 *
 * Kept as an empty stub so old references don't break.
 */
class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn('PermissionsSeeder is deprecated. Use RolesAndPermissionsSeeder.');
    }
}
