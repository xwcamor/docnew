<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\Person;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Que las cabeceras del listado de personas ordenen de verdad.
 *
 * El aviso llego tal cual: «no todas las columnas se pueden ordenar». Y era
 * cierto de tres maneras distintas, que es por lo que esta prueba las recorre
 * todas en vez de mirar una:
 *
 *  - columnas que ni siquiera pedian el orden (rostro, firmas),
 *  - columnas que lo pedian y el servidor no sabia resolver, asi que la lista
 *    volvia exactamente igual y parecia que el clic no hacia nada,
 *  - y la del workspace, que mandaba su `dataIndex` entero —un camino
 *    `['tenant','name']`— y llegaba al servidor como un array que no encajaba
 *    con ninguna clave.
 *
 * Las tres se ven igual desde fuera: pulsas y no pasa nada. Por eso cada caso
 * comprueba el ORDEN DE LAS FILAS en los dos sentidos, no que la peticion
 * devuelva 200.
 *
 * Los tres protagonistas —Alvarez, Beltran y Castro— llevan valores cruzados a
 * proposito: por cada columna salen en un orden distinto, asi que una prueba en
 * verde no puede serlo por casualidad de estar ya ordenados por otra cosa.
 */
class OrdenDelListadoDePersonasTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'people';
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->persona('Alvarez', 'Zoe', 'DNI', '30000003');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Peru ya lo pone la clase base con el id 1. Hacen falta dos paises mas
        // para que el orden alfabetico por pais tenga algo que decir.
        DB::table('countries')->insertOrIgnore([
            ['id' => 2, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Argentina', 'iso_code' => 'AR', 'currency' => 'ARS', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Chile',     'iso_code' => 'CL', 'currency' => 'CLP', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    // ── El escenario ────────────────────────────────────────────────────────

    private function persona(string $apellido, string $nombre, string $tipoDoc, string $numDoc, array $extra = []): Person
    {
        // `created_at` no es fillable —y con razon— pero la prueba del orden por
        // fecha de alta necesita fechas distintas y no tres del mismo segundo.
        $alta = $extra['created_at'] ?? null;
        unset($extra['created_at']);

        $p = Person::create(array_merge([
            'slug'       => Str::random(22),
            'tenant_id'  => 1,
            'created_by' => 1,
            'country_id' => 1,
            'doc_type'   => $tipoDoc,
            'num_doc'    => $numDoc,
            'name'       => $nombre,
            'lastname'   => $apellido,
            'is_active'  => true,
        ], $extra));

        if ($alta !== null) {
            $p->forceFill(['created_at' => $alta])->saveQuietly();
        }

        return $p;
    }

    private function empresa(string $nombre): Company
    {
        return Company::firstOrCreate(
            ['name' => $nombre],
            [
                'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1,
                'num_doc' => (string) random_int(20100000000, 20199999999),
                'complete_name' => $nombre . ' Servicios Generales S.A.C.', 'is_active' => true,
            ],
        );
    }

    private function cargo(string $code): Position
    {
        return Position::firstOrCreate(
            ['code' => $code],
            ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1, 'is_active' => true],
        );
    }

    private function vincular(Person $p, string $empresa, string $cargo): void
    {
        DB::table('person_company_links')->insert([
            'person_id' => $p->id, 'company_id' => $this->empresa($empresa)->id,
            'position_id' => $this->cargo($cargo)->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rostros(Person $p, int $cuantos): void
    {
        for ($i = 0; $i < $cuantos; $i++) {
            DB::table('person_biometrics')->insert([
                'person_id' => $p->id, 'face_descriptor' => json_encode([0.1, 0.2]),
                'enrolled_at' => now(), 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function firmas(Person $p, int $cuantas): void
    {
        for ($i = 0; $i < $cuantas; $i++) {
            DB::table('person_signatures')->insert([
                'person_id' => $p->id, 'file_path' => "firmas/{$p->id}-{$i}.png",
                'sha256' => hash('sha256', "{$p->id}-{$i}"), 'source' => 'captured',
                'valid_from' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Tres personas cuyos datos NO van todos en el mismo sentido.
     *
     * Por apellido salen A, B, C; por documento B, C, A; por pais C, A, B; por
     * empresa C, A, B; por cargo C, B, A; por numero de empresas B, A, C; por
     * rostros B, A, C; por firmas A, C, B; y por fecha de alta B, C, A. Ninguna
     * columna comparte permutacion con la de al lado.
     */
    private function elEscenario(): void
    {
        $a = $this->persona('Alvarez', 'Zoe',   'DNI', '30000003', ['country_id' => 3, 'created_at' => '2026-03-01 10:00:00']);
        $b = $this->persona('Beltran', 'Ana',   'CE',  '10000001', ['country_id' => 1, 'created_at' => '2026-01-01 10:00:00', 'is_active' => false]);
        $c = $this->persona('Castro',  'Bruno', 'DNI', '20000002', ['country_id' => 2, 'created_at' => '2026-02-01 10:00:00']);

        $this->vincular($a, 'Globex', 'Técnico');
        $this->vincular($a, 'Umbra',  'Técnico');
        $this->vincular($b, 'Zenith', 'Supervisor');
        $this->vincular($c, 'Acme',   'Mecánico');
        $this->vincular($c, 'Delta',  'Mecánico');
        $this->vincular($c, 'Nimbus', 'Mecánico');

        $this->rostros($a, 1);
        $this->rostros($c, 2);

        $this->firmas($b, 2);
        $this->firmas($c, 1);
    }

    /** Los apellidos en el orden en que los devuelve el listado. */
    private function apellidos(string $sort, string $direction, array $extra = []): array
    {
        $res = $this->get(route('business_management.people.index', array_merge([
            'sort' => $sort, 'direction' => $direction,
        ], $extra)));

        $res->assertOk();

        $orden = [];
        $res->assertInertia(function (Assert $p) use (&$orden) {
            $orden = collect($p->toArray()['props']['people']['data'])->pluck('lastname')->values()->all();
        });

        return $orden;
    }

    // ── Cada columna ordenable, en los dos sentidos ─────────────────────────

    #[DataProvider('columnasOrdenables')]
    public function test_cada_columna_ordenable_cambia_el_orden_de_las_filas(string $sort, array $ascendente): void
    {
        $this->elEscenario();
        $this->actingAs($this->admin());

        $this->assertSame($ascendente, $this->apellidos($sort, 'asc'), "El orden ascendente por «{$sort}» no es el esperado");
        $this->assertSame(array_reverse($ascendente), $this->apellidos($sort, 'desc'), "El orden descendente por «{$sort}» no es el esperado");
    }

    /**
     * Clave de orden → apellidos en ascendente. El descendente es el reverso,
     * que es lo que se comprueba arriba: si una columna devolviera lo mismo en
     * los dos sentidos —el sintoma de que el servidor ignora ese `sort`— la
     * prueba falla sola.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function columnasOrdenables(): array
    {
        return [
            // La celda pone «APELLIDO, Nombre» y el orden lee igual.
            'persona (apellido)'   => ['lastname',            ['Alvarez', 'Beltran', 'Castro']],
            // Tipo primero, numero despues: CE va antes que DNI, y dentro de
            // los DNI manda el numero.
            'documento'            => ['document',            ['Beltran', 'Castro', 'Alvarez']],
            // Alfabetico por NOMBRE del pais, no por `country_id`.
            'pais'                 => ['country',             ['Castro', 'Alvarez', 'Beltran']],
            // Alfabetico por nombre de empresa: Acme, Globex, Zenith.
            'empresa'              => ['company',             ['Castro', 'Alvarez', 'Beltran']],
            // Alfabetico por cargo: Mecánico, Supervisor, Técnico.
            'cargo'                => ['position',            ['Castro', 'Beltran', 'Alvarez']],
            // Conteos: 1, 2 y 3 empresas.
            'numero de empresas'   => ['companies_count',     ['Beltran', 'Alvarez', 'Castro']],
            'numero de empresas (alias del withCount)' => ['company_links_count', ['Beltran', 'Alvarez', 'Castro']],
            // Rostro es una pastilla si/no, pero ordena por rostros vigentes.
            'rostro'               => ['biometric',           ['Beltran', 'Alvarez', 'Castro']],
            'firmas'               => ['signatures_count',    ['Alvarez', 'Castro', 'Beltran']],
            'fecha de alta'        => ['created_at',          ['Beltran', 'Castro', 'Alvarez']],
        ];
    }

    /**
     * El estado va aparte porque solo tiene dos valores y hay empate: lo que se
     * comprueba es la secuencia de banderas, no los apellidos.
     */
    public function test_el_estado_ordena_inactivos_y_activos(): void
    {
        $this->elEscenario();
        $this->actingAs($this->admin());

        $this->assertSame(['Beltran'], array_slice($this->apellidos('is_active', 'asc'), 0, 1));
        $this->assertSame(['Beltran'], array_slice($this->apellidos('is_active', 'desc'), -1));
    }

    /**
     * La cabecera del workspace mandaba `['tenant','name']` y el servidor
     * recibia un array; ahora manda `tenant` y ordena por el nombre del
     * workspace. Solo la ve el super, y solo desde la consola (sin workspace
     * propio) ve personas de mas de uno.
     */
    public function test_ordena_por_workspace(): void
    {
        DB::table('tenants')->insertOrIgnore([
            ['id' => 2, 'slug' => Str::random(22), 'name' => 'Zulu Workspace', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'slug' => Str::random(22), 'name' => 'Alfa Workspace', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->persona('Zeta',  'Uno', 'DNI', '40000004', ['tenant_id' => 2]);
        $this->persona('Alpha', 'Dos', 'DNI', '50000005', ['tenant_id' => 3]);

        $this->actingAs($this->superDeLaConsola());

        // Alfa Workspace antes que Zulu Workspace → Alpha, luego Zeta.
        $this->assertSame(['Alpha', 'Zeta'], $this->apellidos('tenant', 'asc'));
        $this->assertSame(['Zeta', 'Alpha'], $this->apellidos('tenant', 'desc'));
    }

    /** El super sin workspace propio: el unico que ve personas de varios. */
    private function superDeLaConsola(): User
    {
        $rol = Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        $rol->syncPermissions(Permission::all());

        $u = User::factory()->create(['tenant_id' => null, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);

        return $u;
    }

    // ── Lo que NO se ordena, y lo que llega torcido ─────────────────────────

    /**
     * Un `sort` que no existe no puede dejar sin listado a nadie.
     *
     * No es un caso de laboratorio: una vista guardada de hace meses puede
     * citar una columna que ya se retiro, y el parametro viaja en la URL, o sea
     * que llega lo que sea. Se cae al orden de siempre —lo ultimo dado de alta
     * arriba— y la pantalla abre.
     */
    public function test_un_sort_inventado_no_revienta_y_cae_al_orden_por_defecto(): void
    {
        $this->elEscenario();
        $this->actingAs($this->admin());

        $porDefecto = $this->apellidos('id', 'desc');

        $this->assertSame($porDefecto, $this->apellidos('columna_que_no_existe', 'desc'));
        // «actions» no es un dato, son botones: sigue sin ser un orden valido.
        $this->assertSame($porDefecto, $this->apellidos('actions', 'desc'));
    }

    /**
     * Y tampoco puede colarse SQL por ahi: `sort` sale de la barra de
     * direcciones y acababa concatenado dentro del ORDER BY.
     */
    public function test_un_sort_con_sql_dentro_no_se_ejecuta(): void
    {
        $this->elEscenario();
        $this->actingAs($this->admin());

        $this->assertSame(
            $this->apellidos('id', 'desc'),
            $this->apellidos('id) --', 'desc'),
        );
        // Y la direccion tampoco: solo valen asc y desc.
        $this->assertSame(
            $this->apellidos('lastname', 'desc'),
            $this->apellidos('lastname', 'asc; drop table people'),
        );
        $this->assertDatabaseCount('people', 3);
    }

    /**
     * Los favoritos mandan sobre cualquier orden.
     *
     * Es la regla que no se puede romper al arreglar lo demas: quien se marca
     * una persona con la estrella la quiere arriba aunque despues ordene por
     * documento o por empresa. `orderByFavoriteFirst` se aplica ANTES que el
     * `sort`, y por eso gana.
     */
    public function test_los_favoritos_siguen_mandando_sobre_el_orden_elegido(): void
    {
        $this->elEscenario();
        $usuario = $this->admin();
        $this->actingAs($usuario);

        // Castro es el ultimo por apellido ascendente; con la estrella puesta
        // tiene que salir el primero igualmente.
        DB::table('user_favorites')->insert([
            'user_id' => $usuario->id,
            'favoritable_id' => Person::where('lastname', 'Castro')->value('id'),
            'favoritable_type' => Person::class,
            'created_at' => now(),
        ]);

        $this->assertSame(['Castro', 'Alvarez', 'Beltran'], $this->apellidos('lastname', 'asc'));
        $this->assertSame(['Castro', 'Alvarez', 'Beltran'], $this->apellidos('document', 'desc'));
    }

    /**
     * La estrella y las acciones NO son ordenables, y eso tambien se fija aqui:
     * una cabecera clicable que no hace nada es peor que una que no lo es, y la
     * misma lista de `sorter` alimenta el desplegable de orden de las vistas
     * guardadas.
     *
     * Los roles SI, desde que se pidieron: una persona lleva varios a la vez,
     * asi que se ordena por el primero alfabeticamente —ver la prueba de
     * abajo— y con eso la lista queda agrupada por rol.
     */
    public function test_las_columnas_sin_orden_no_estan_en_la_lista_blanca(): void
    {
        foreach (['favorite', 'is_favorite', 'actions'] as $clave) {
            $this->assertNotContains($clave, Person::ordenesDelListado(), "«{$clave}» no deberia ser ordenable");
            $this->assertSame('id', Person::ordenValidoDelListado($clave));
        }
    }

    /**
     * Y al reves: todo lo que la tabla marca como ordenable tiene que saber
     * resolverlo el servidor. La lista de aqui es la de `columns.js`; si alguien
     * añade una cabecera con `sorter` y se olvida del backend, esto lo caza.
     */
    public function test_todas_las_cabeceras_ordenables_las_entiende_el_servidor(): void
    {
        $cabeceras = ['lastname', 'document', 'company_links_count', 'biometric',
                      'is_active', 'tenant', 'company', 'position', 'country',
                      'signatures_count', 'roles'];

        foreach ($cabeceras as $clave) {
            $this->assertContains($clave, Person::ordenesDelListado(), "La cabecera «{$clave}» no la sabe ordenar el servidor");
        }
    }

    /**
     * Ordenar por rol agrupa por rol, que es para lo que se pulsa.
     *
     * Se ordena por el PRIMERO alfabeticamente y por el nombre del CATALOGO, no
     * por `person_roles.role`: por el codigo, «hse_supervisor» va antes que
     * «supervisor» y la lista sale en un orden que no es el que se lee.
     */
    public function test_ordena_por_el_primer_rol_como_se_lee(): void
    {
        Person::query()->forceDelete();

        // El idioma se fija: la columna que se ordena es la del idioma en curso
        // —`name_es` o `name_en`— y en las pruebas el de partida es el ingles.
        // Sin esto, la prueba escrita con las etiquetas castellanas comprobaba
        // un orden que no era el que se pedia.
        app()->setLocale('es');

        // `updateOrCreate` y no `firstOrCreate`: «supervisor» y «hse_supervisor»
        // vienen sembrados, y con `firstOrCreate` los nombres de aqui no se
        // aplicaban — la prueba ordenaba por unas etiquetas y esperaba otras.
        foreach ([['supervisor', 'Supervisor', 1], ['hse_supervisor', 'Supervisor HSE', 2],
                  ['jefe_izaje', 'Jefe de Izaje', 3]] as [$code, $nombre, $orden]) {
            \App\Models\ApproverRole::withoutGlobalScopes()->updateOrCreate(
                ['code' => $code],
                ['slug' => \Illuminate\Support\Str::random(22), 'name_es' => $nombre, 'name_en' => $nombre,
                 'sort_order' => $orden, 'is_active' => true, 'created_by' => 1],
            );
        }

        $conRol = function (string $apellido, string $doc, array $roles) {
            $persona = $this->persona($apellido, 'Ana', 'DNI', $doc);
            foreach ($roles as $r) {
                \App\Models\PersonRole::create(['person_id' => $persona->id, 'role' => $r, 'is_active' => true]);
            }

            return $persona;
        };

        // «Jefe de Izaje» va antes que «Supervisor» alfabeticamente, aunque su
        // codigo empiece por j y su `sort_order` sea el ultimo.
        $conRol('Zapata', '45000001', ['supervisor']);
        $conRol('Alvarez', '45000002', ['jefe_izaje']);
        // Con dos roles manda el primero alfabeticamente: «Supervisor HSE» va
        // detras de «Supervisor», asi que esta persona ordena por «Supervisor».
        $conRol('Medina', '45000003', ['hse_supervisor']);

        $this->actingAs($this->admin());

        $this->assertSame(['Alvarez', 'Zapata', 'Medina'], $this->apellidos('roles', 'asc'));
        $this->assertSame(['Medina', 'Zapata', 'Alvarez'], $this->apellidos('roles', 'desc'));
    }
}
