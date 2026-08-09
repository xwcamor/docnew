<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * «Editar todo» ya no revienta con un valor repetido.
 *
 * La pantalla detecta repetidos dentro de la pagina que se ve, y ahi se acaba.
 * Poner en una fila el valor de otra que esta en la pagina siguiente llegaba
 * hasta el indice unico de Postgres: 500 en la cara del usuario y **el lote
 * entero perdido**, que pueden ser doscientas filas de trabajo.
 *
 * Estaba abierto en nueve modulos a la vez, porque el molde del generador no
 * lleva la comprobacion. Esta prueba recorre los nueve por la misma puerta:
 * dos filas, se le pone a la segunda el valor de la primera, y tiene que salir
 * un error **en su campo**, no una excepcion.
 *
 * Ojo con lo que NO comprueba: que la validacion diga lo MISMO que el indice.
 * Eso no se puede afirmar desde SQLite —donde corren las pruebas— porque los
 * indices parciales con expresion son de Postgres y aqui ni existen. Lo que
 * fija esta prueba es que la peticion se rechaza limpia; que el criterio
 * coincide con el del motor esta escrito en el trait y comprobado a mano
 * contra un Postgres de verdad.
 */
class EditAllUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Sudamérica', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 1, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }

    /**
     * Los siete que comparten forma: una columna, un valor repetido.
     *
     * Los otros dos van aparte porque no la comparten: Perfiles, cuyo índice no
     * filtra por `deleted_at`, y Reglas de aprobación, donde el índice es
     * compuesto y el valor sólo es único dentro de su flujo.
     *
     * @return array<string, array{0:string, 1:string, 2:string, 3:array<string,mixed>}>
     */
    public static function catalogos(): array
    {
        return [
            // ruta                                 tabla             campo       columnas extra de cada fila
            'países'    => ['system_management.countries.edit_all.update', 'countries', 'name', ['region_id' => 1, 'iso_code' => null, 'currency' => 'PEN', 'timezone' => 'America/Lima', 'default_locale_id' => 1]],
            'regiones'  => ['system_management.regions.edit_all.update', 'regions', 'name', []],
            'idiomas'   => ['system_management.languages.edit_all.update', 'languages', 'name', ['iso_code' => null]],
            'locales'   => ['system_management.locales.edit_all.update', 'locales', 'name', ['code' => null, 'language_id' => 1]],
            'workspaces' => ['system_management.tenants.edit_all.update', 'tenants', 'name', []],
            'módulos'   => ['system_management.system_modules.edit_all.update', 'system_modules', 'name', ['permission_key' => null]],
            'automatizaciones' => ['automation_management.automations.edit_all.update', 'automations', 'name', ['action_type' => 'email', 'trigger_type' => 'schedule']],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     *
     * @dataProvider catalogos
     */
    public function test_repetir_un_valor_avisa_en_su_campo_en_vez_de_reventar(
        string $ruta,
        string $tabla,
        string $campo,
        array $extra,
    ): void {
        $primera = $this->fila($tabla, $campo, 'Alfa', $extra, 1);
        $segunda = $this->fila($tabla, $campo, 'Beta', $extra, 2);

        $this->actingAs($this->super());

        // La segunda pasa a llamarse como la primera, que NO viaja en el lote:
        // es el caso que la pantalla no puede detectar.
        $this->post(route($ruta), ['changes' => [['id' => $segunda, $campo => 'Alfa']]])
            ->assertSessionHasErrors("changes.0.{$campo}");

        $this->assertSame('Beta', DB::table($tabla)->where('id', $segunda)->value($campo),
            'la fila no debería haberse renombrado');
    }

    /**
     * @param  array<string, mixed>  $extra
     *
     * @dataProvider catalogos
     */
    public function test_repetir_un_valor_dentro_del_mismo_lote_tambien_avisa(
        string $ruta,
        string $tabla,
        string $campo,
        array $extra,
    ): void {
        $primera = $this->fila($tabla, $campo, 'Alfa', $extra, 1);
        $segunda = $this->fila($tabla, $campo, 'Beta', $extra, 2);

        $this->actingAs($this->super());

        $this->post(route($ruta), ['changes' => [
            ['id' => $primera, $campo => 'Gamma'],
            ['id' => $segunda, $campo => 'Gamma'],
        ]])->assertSessionHasErrors("changes.1.{$campo}");

        $this->assertSame('Alfa', DB::table($tabla)->where('id', $primera)->value($campo),
            'ninguna de las dos debería haberse guardado');
    }

    /** Y lo que no está repetido se guarda: la guarda no puede bloquear lo legítimo. */
    public function test_un_valor_libre_si_se_guarda(): void
    {
        $id = $this->fila('regions', 'name', 'Beta', [], 2);

        $this->actingAs($this->super());

        $this->post(route('system_management.regions.edit_all.update'), [
            'changes' => [['id' => $id, 'name' => 'Centroamérica']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Centroamérica', DB::table('regions')->where('id', $id)->value('name'));
    }

    /**
     * Renombrar una fila a lo que ya se llamaba no es un choque.
     *
     * Es lo que pasa cada vez que alguien toca el interruptor de «activo» de
     * una fila: el nombre viaja igual, sin cambios. Una guarda mal escrita
     * convierte eso en «ya existe» y deja la pantalla inservible.
     */
    public function test_dejar_el_mismo_valor_no_es_un_choque(): void
    {
        $id = $this->fila('regions', 'name', 'Alfa', [], 1);

        $this->actingAs($this->super());

        $this->post(route('system_management.regions.edit_all.update'), [
            'changes' => [['id' => $id, 'name' => 'Alfa', 'is_active' => false]],
        ])->assertSessionHasNoErrors();
    }

    /** Perfiles: su índice no filtra por `deleted_at`, así que la papelera reserva el nombre. */
    public function test_un_perfil_en_la_papelera_sigue_reservando_su_nombre(): void
    {
        DB::table('roles')->insert([
            'id' => 90, 'slug' => Str::random(22), 'tenant_id' => 1, 'name' => 'Jubilado',
            'guard_name' => 'web', 'description' => 'retirado', 'is_active' => false, 'deleted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('roles')->insert([
            'id' => 91, 'slug' => Str::random(22), 'tenant_id' => 1, 'name' => 'Vigente',
            'guard_name' => 'web', 'description' => 'vigente', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->super());

        $this->post(route('user_management.roles.edit_all.update'), [
            'changes' => [['id' => 91, 'name' => 'Jubilado']],
        ])->assertSessionHasErrors('changes.0.name');
    }

    /** Reglas de aprobación: el nivel es único DENTRO de su flujo, no en la tabla. */
    public function test_dos_firmas_del_mismo_flujo_no_comparten_nivel(): void
    {
        foreach ([[80, 1], [81, 2]] as [$id, $nivel]) {
            DB::table('approval_rules')->insert([
                'id' => $id, 'slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 1,
                'approver_role' => 'hse', 'priority_level' => $nivel, 'is_required' => true,
                'is_active' => true, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Otro flujo (otro rol) con el nivel 1: ése NO choca.
        DB::table('approval_rules')->insert([
            'id' => 82, 'slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 1,
            'approver_role' => 'supervisor', 'priority_level' => 1, 'is_required' => true,
            'is_active' => true, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->super());

        $this->post(route('business_management.approval_rules.edit_all.update'), [
            'changes' => [['id' => 81, 'priority_level' => 1]],
        ])->assertSessionHasErrors('changes.0.priority_level');

        $this->assertSame(2, (int) DB::table('approval_rules')->where('id', 81)->value('priority_level'));

        // El de otro flujo se mueve al 2 sin problema: no comparten índice.
        $this->post(route('business_management.approval_rules.edit_all.update'), [
            'changes' => [['id' => 82, 'priority_level' => 2]],
        ])->assertSessionHasNoErrors();
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $extra */
    private function fila(string $tabla, string $campo, string $valor, array $extra, int $n): int
    {
        $datos = [$campo => $valor, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];

        // `automations` es el único de la lista que no lleva slug.
        if (\Illuminate\Support\Facades\Schema::hasColumn($tabla, 'slug')) {
            $datos['slug'] = Str::random(22);
        }

        // Las columnas obligatorias que cada tabla tenga, con un valor propio
        // por fila para no chocar por OTRO índice y confundir el resultado.
        foreach ($extra as $col => $por) {
            $datos[$col] = $por ?? match ($col) {
                'iso_code'       => $n === 1 ? 'AA' : 'BB',
                'code'           => $n === 1 ? 'aa_AA' : 'bb_BB',
                'permission_key' => 'clave_' . $n,
                default          => $por,
            };
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn($tabla, 'tenant_id')) {
            $datos['tenant_id'] = 1;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn($tabla, 'created_by')) {
            $datos['created_by'] = 1;
        }

        return (int) DB::table($tabla)->insertGetId($datos);
    }

    private function super(): User
    {
        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 's']);

        foreach (['countries', 'regions', 'languages', 'locales', 'tenants', 'system_modules', 'roles', 'approval_rules', 'automations'] as $m) {
            Permission::firstOrCreate(['name' => "{$m}.edit", 'guard_name' => 'web']);
        }

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('super');

        return $u;
    }
}
