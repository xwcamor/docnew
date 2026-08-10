<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateLegacyDataCommand;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Migrar dos veces adopta el cargo que ya existe, en vez de duplicarlo.
 *
 * `Position::$fillable` no incluia `legacy_id`, asi que el `update()` del paso
 * de catalogos lo descartaba en silencio —Eloquent no se queja— y el cargo
 * migrado se quedaba **sin marca de origen**. Consecuencias, las dos malas:
 *
 *  - El backfill del candado busca por `legacy_id`, no lo encontraba, y el
 *    cargo se quedaba desbloqueado. Renombrarlo cambia de golpe lo que dicen
 *    los 3.712 planes que lo citan, cerrados y firmados incluidos.
 *  - La siguiente pasada tampoco lo reconocia por `legacy_id`. Se salva por el
 *    respaldo de buscar por codigo; si no, habria creado un duplicado.
 *
 * Esto no se puede comprobar contra el MySQL de la v1 —no se llega a el desde
 * aqui, y no deberia hacer falta para saber si el codigo esta bien—, asi que la
 * conexion `legacy` se apunta a una SQLite en memoria con la forma que tiene la
 * tabla en el origen. Lo que se fija es el comportamiento del comando, que es
 * lo que estaba roto.
 */
class MigracionAdoptaCatalogosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Sudamérica', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 1, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        $this->prepararV1();
    }

    /**
     * El caso que estaba roto: el cargo ya está migrado y le falta la marca.
     *
     * Es exactamente el estado en el que quedó la base tras la primera pasada
     * con el `fillable` incompleto. La segunda pasada tiene que adoptarlo: misma
     * fila, con `legacy_id` y con candado.
     */
    public function test_un_cargo_ya_migrado_sin_marca_se_adopta_y_se_bloquea(): void
    {
        $cargo = Position::create([
            'slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 1, 'created_by' => 1,
            'code' => 'Electricista', 'is_active' => true,
        ]);

        $this->assertNull($cargo->legacy_id, 'punto de partida: sin marca de origen');
        $this->assertNull($cargo->locked_at, 'punto de partida: sin candado');

        $this->correrCatalogoDeCargos();

        $cargo->refresh();

        $this->assertSame(1, Position::count(), 'no debe duplicar: adopta la fila que ya estaba');
        $this->assertSame(7, (int) $cargo->legacy_id, 'sin esto el backfill del candado nunca lo encuentra');
        $this->assertNotNull($cargo->locked_at, 'un catálogo migrado nace bloqueado');
        $this->assertSame('super', $cargo->lock_scope, 'bloquea el sistema, no una persona');
    }

    // Aqui habia un test de que la adopcion se traia `is_signature_approver`
    // del cargo de la v1. La columna ya no existe —un cargo dice que hace
    // alguien, no si aprueba un plan— asi que no queda nada que comprobar y el
    // test se fue con ella. Lo que si sigue comprobandose es que la fila se
    // adopta en vez de duplicarse, que era el fallo de verdad.

    /**
     * Volver a migrar NO le devuelve el candado a quien se lo quitó a propósito.
     *
     * Es la mitad que se olvida: si el candado se repusiera en cada pasada, el
     * que corrige un cargo mal escrito se lo encontraría bloqueado otra vez y no
     * sabría por qué.
     */
    public function test_migrar_otra_vez_no_vuelve_a_bloquear_lo_que_alguien_desbloqueo(): void
    {
        $this->correrCatalogoDeCargos();

        $cargo = Position::first();
        $cargo->forceFill(['locked_at' => null, 'locked_by' => null, 'lock_scope' => null])->saveQuietly();

        $this->correrCatalogoDeCargos();

        $this->assertNull($cargo->fresh()->locked_at, 'el desbloqueo hecho a mano se respeta');
    }

    /** Un cargo que no estaba se crea, con su marca y su candado. */
    public function test_un_cargo_nuevo_se_crea_marcado_y_bloqueado(): void
    {
        $this->correrCatalogoDeCargos();

        $cargo = Position::first();

        $this->assertNotNull($cargo, 'el cargo de la v1 tiene que aparecer');
        $this->assertSame(7, (int) $cargo->legacy_id);
        $this->assertNotNull($cargo->locked_at);
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    /** La tabla `positions` de la v1, con la forma que tiene allí. */
    private function prepararV1(): void
    {
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        $v1 = DB::connection('legacy');

        $v1->statement('CREATE TABLE positions (
            id INTEGER PRIMARY KEY, country_id INTEGER, name_es TEXT,
            is_signature_approver INTEGER, is_active INTEGER
        )');

        // country_id 1 es Peru tambien en la v1 (PAIS_LEGACY).
        $v1->table('positions')->insert([
            ['id' => 7, 'country_id' => 1, 'name_es' => 'Electricista', 'is_signature_approver' => 1, 'is_active' => 1],
            // De otro pais: el paso solo trae el pais de los planes, asi que
            // este no debe aparecer. Si aparece, el filtro se rompio.
            ['id' => 8, 'country_id' => 2, 'name_es' => 'Soldador', 'is_signature_approver' => 0, 'is_active' => 1],
        ]);
    }

    /**
     * Llama al paso de cargos sin arrastrar el resto de la migración.
     *
     * `catalogoCargos()` es protegido y vive dentro de `migrarPersonas()`, que
     * necesita las otras seis tablas de la v1. Aquí interesa sólo el catálogo,
     * así que se le entra por reflexión — no es bonito, pero la alternativa es
     * montar medio origen para comprobar una columna del `fillable`.
     */
    private function correrCatalogoDeCargos(): void
    {
        $comando = app(MigrateLegacyDataCommand::class);

        foreach (['tenantId' => 1, 'countryId' => 1] as $prop => $valor) {
            $p = new \ReflectionProperty($comando, $prop);
            $p->setAccessible(true);
            $p->setValue($comando, $valor);
        }

        $m = new \ReflectionMethod($comando, 'catalogoCargos');
        $m->setAccessible(true);
        $m->invoke($comando, DB::connection('legacy'));
    }
}
