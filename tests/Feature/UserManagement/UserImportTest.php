<?php

namespace Tests\Feature\UserManagement;

use App\Imports\AuthManagement\Users\UsersImport;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Regresión del import de Usuarios. Cubre el CREATE end-to-end, que antes no
 * tenía cobertura y escondía 3 bugs apilados:
 *   1. roleCache sin guard_name → syncRoles() reventaba (GuardDoesNotMatch).
 *   2. country_id/locale_id (NOT NULL) nunca seteados → insert fallaba.
 *   3. role_name='user' (el ejemplo del template) erraba en vez de crear un
 *      usuario básico sin rol.
 */
class UserImportTest extends UserTestCase
{
    public function test_import_creates_basic_admin_and_rejects_invalid_role(): void
    {
        $this->actingAsTenantAdmin(1); // dispatcher con country_id=1, locale_id=1

        $import = new UsersImport(mode: 'create_only', dryRun: false);
        $import->collection(new Collection([
            collect(['name' => 'Basic User', 'email' => 'basic@ex.com', 'password' => 'TempPass!23', 'role_name' => 'user',  'is_active' => '1']),
            collect(['name' => 'Admin User', 'email' => 'admin2@ex.com', 'password' => 'TempPass!23', 'role_name' => 'admin', 'is_active' => '1']),
            collect(['name' => 'Bad Role',   'email' => 'bad@ex.com',   'password' => 'TempPass!23', 'role_name' => 'noexiste', 'is_active' => '1']),
        ]));

        // 2 creados (básico + admin), 1 rechazado (rol inválido).
        $this->assertSame(2, $import->created);
        $this->assertCount(1, $import->errors);

        // Bug #2: country_id/locale_id heredados del dispatcher (NOT NULL).
        $this->assertDatabaseHas('users', ['email' => 'basic@ex.com', 'country_id' => 1, 'locale_id' => 1, 'tenant_id' => 1]);

        // Bug #3: 'user' => usuario básico SIN rol (no error).
        $basic = User::where('email', 'basic@ex.com')->first();
        $this->assertNotNull($basic);
        $this->assertTrue($basic->roles->isEmpty());

        // Bug #1: el rol admin se asigna (guard_name presente en el caché).
        $admin = User::where('email', 'admin2@ex.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('admin'));

        // El rol inexistente sí debe rechazarse.
        $this->assertDatabaseMissing('users', ['email' => 'bad@ex.com']);
    }
}
