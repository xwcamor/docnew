<?php

namespace Tests\Feature\BusinessManagement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El unico permiso que el admin de un workspace NO recibe por defecto.
 *
 * `RolesAndPermissionsSeeder` le sincroniza al admin `Permission::all()`, asi
 * que cualquier permiso nuevo le llega solo. `people.view_media` es la
 * excepcion y va escrita a mano con un `reject()`, que es justo el tipo de
 * linea que alguien borra sin darse cuenta al añadir el permiso siguiente.
 *
 * Que sea una excepcion no significa que este cerrado: un admin que de verdad
 * lo necesite lo tiene concediendoselo. Esta cerrado POR DEFECTO, que no es lo
 * mismo.
 */
class AdminNoVeLaFotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_sembrado_no_le_da_al_admin_el_permiso_de_la_foto(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->first();

        $this->assertNotNull($admin, 'No se sembro el rol admin.');
        $this->assertFalse(
            $admin->hasPermissionTo('people.view_media'),
            'El admin del workspace se llevo el permiso de ver la foto y la firma guardadas.',
        );
    }

    /** Y el super si lo tiene: es suyo. */
    public function test_el_super_si_lo_tiene(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $super = Role::where('name', 'super')->where('guard_name', 'web')->first();

        $this->assertNotNull($super);
        $this->assertTrue($super->hasPermissionTo('people.view_media'));
    }

    /**
     * Pero el DNI completo si lo ve el admin, y eso no cambia.
     *
     * Son dos cosas distintas y conviene que la prueba lo diga: el admin
     * necesita el documento para su gente, la foto guardada no.
     */
    public function test_el_admin_si_sigue_viendo_el_documento_completo(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->first();

        $this->assertTrue($admin->hasPermissionTo('people.view_private_info'));
    }
}
