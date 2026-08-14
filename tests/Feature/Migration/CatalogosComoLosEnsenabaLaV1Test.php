<?php

namespace Tests\Feature\Migration;

use App\Models\FormField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los catalogos migrados son los que el formulario de la v1 ENSEÑABA, no los
 * que su tabla guardaba.
 *
 * EL FALLO QUE CIERRA, contado por quien lo vio: «las preguntas que tienes en
 * el Pare y Tome 5 estan mal, ni siquiera son esas» — con el PDF de su v1 en la
 * mano. Y su PDF tenia razon: el migrador filtraba solo `is_deleted`, y el
 * formulario de alla filtraba ademas `is_active`
 * (`f2_documents_controller.rb`: `not_deleted.is_active`). Cada pregunta que un
 * administrador EDITO en la v1 dejaba su version vieja desactivada en la tabla,
 * y este comando las barria todas: el PTF migrado enseñaba preguntas que el
 * formulario viejo no enseñaba desde hacia años.
 */
class CatalogosComoLosEnsenabaLaV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        foreach (['super', 'admin'] as $rol) {
            Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web'], ['slug' => Str::random(22), 'description' => $rol]);
        }

        LegacyDatabaseFixture::levantar();
        LegacyDatabaseFixture::sembrar();
    }

    /** Lo desactivado no entra: el formulario de la v1 tampoco lo enseñaba. */
    public function test_una_pregunta_desactivada_no_se_migra(): void
    {
        $this->artisan('docufiz:migrate-formats')->assertSuccessful();

        $preguntas = $this->preguntasDelPtf();

        $this->assertContains('Tienes permiso?', $preguntas);
        $this->assertNotContains('Pregunta vieja desactivada', $preguntas,
            'el formulario de la v1 filtraba is_active: el migrador tiene que hacer lo mismo');
    }

    /**
     * Y lo que las corridas ANTERIORES barrieron, se cura al volver a correr.
     *
     * La limpieza es quirurgica: retira exactamente el texto que la base vieja
     * marca como desactivado o borrado, y lo que el cliente añadio desde el
     * editor nuevo —que la base vieja no conoce— sobrevive intacto.
     */
    public function test_el_catalogo_ya_contaminado_se_cura_sin_tocar_lo_del_cliente(): void
    {
        $this->artisan('docufiz:migrate-formats')->assertSuccessful();

        // El estado que dejo la version con el fallo: la desactivada dentro,
        // mas una pregunta que el cliente escribio en el editor nuevo.
        $campo = $this->campoDelPtf();
        $config = $campo->config;
        $config['questions'] = [...$config['questions'], 'Pregunta vieja desactivada', 'Pregunta propia del cliente'];
        $campo->update(['config' => $config]);

        $this->artisan('docufiz:migrate-formats')->assertSuccessful();

        $preguntas = $this->preguntasDelPtf();

        $this->assertNotContains('Pregunta vieja desactivada', $preguntas,
            'lo que la v1 tenia desactivado se retira del catalogo migrado');
        $this->assertContains('Pregunta propia del cliente', $preguntas,
            'lo que el cliente añadio en el editor nuevo no esta en la base vieja y no se toca');
        $this->assertContains('Tienes permiso?', $preguntas);
    }

    /**
     * Los bloques del PTF salen de LA RELACION de la base vieja, no del repo.
     *
     * Lo vio el dueño del producto: «la tabla tenia la columna
     * ptf_question_title_id, ahi se agrupaban las preguntas». El migrador leia
     * la tabla plana por id y perdia la agrupacion — y el orden: una pregunta
     * añadida tarde a un bloque temprano salia al final de la lista.
     */
    public function test_los_bloques_del_ptf_vienen_de_la_relacion_de_la_base_vieja(): void
    {
        $this->artisan('docufiz:migrate-formats')->assertSuccessful();

        $config = $this->campoDelPtf()->config;

        // Dos bloques, los de la relacion. El titulo conocido trae el icono
        // del repositorio; el bloque propio del cliente, no — su icono se sube
        // desde el editor.
        $this->assertCount(2, $config['groups']);
        $this->assertSame('1. ¡DETÉNTE y piensa antes de actuar!', $config['groups'][0]['name']['es']);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $config['groups'][0]['image']);
        $this->assertSame('Bloque propio del cliente', $config['groups'][1]['name']['es']);
        $this->assertNull($config['groups'][1]['image']);

        // Cada bloque con SUS preguntas, y la desactivada en ninguno.
        $this->assertSame(['Tienes permiso?'], $config['groups'][0]['items']);
        $this->assertSame(['Conoces la emergencia?'], $config['groups'][1]['items']);

        // Y la lista plana en el orden del papel: por bloque, no por id. La
        // pregunta con id 1 es del bloque 2 y va DESPUES.
        $this->assertSame(['Tienes permiso?', 'Conoces la emergencia?'], $this->preguntasDelPtf());
    }

    /** El PTF migrado antes de que existieran los bloques los gana al re-correr. */
    public function test_el_ptf_ya_migrado_sin_bloques_los_gana(): void
    {
        $this->artisan('docufiz:migrate-formats')->assertSuccessful();

        // El estado que dejo la version vieja del comando: sin bloques.
        $campo = $this->campoDelPtf();
        $config = $campo->config;
        unset($config['groups']);
        $campo->update(['config' => $config]);

        $this->artisan('docufiz:migrate-formats')->assertSuccessful();

        $this->assertCount(2, $this->campoDelPtf()->config['groups']);
    }

    /** Unos bloques que alguien ya armo —quien sea— no se pisan. */
    public function test_unos_bloques_ya_puestos_no_se_pisan(): void
    {
        $this->artisan('docufiz:migrate-formats')->assertSuccessful();

        $campo = $this->campoDelPtf();
        $config = $campo->config;
        $config['groups'] = [['name' => 'Mi bloque', 'items' => ['Tienes permiso?']]];
        $campo->update(['config' => $config]);

        $this->artisan('docufiz:migrate-formats')->assertSuccessful();

        $this->assertSame('Mi bloque', $this->campoDelPtf()->config['groups'][0]['name']);
    }

    // ── Decorado ────────────────────────────────────────────────────────────

    private function campoDelPtf(): FormField
    {
        return FormField::whereHas('section.formTemplate', fn ($q) => $q->where('code', 'PTF'))
            ->where('field_type', 'question_bank')
            ->firstOrFail();
    }

    /** @return array<int, string> */
    private function preguntasDelPtf(): array
    {
        return \App\Support\Catalogo::valores($this->campoDelPtf()->config['questions'] ?? []);
    }
}
