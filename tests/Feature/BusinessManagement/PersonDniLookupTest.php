<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\DocumentType;
use App\Services\Peru\ConsultaDni;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * La consulta del DNI a RENIEC, que la v1 tenia y al portar el modulo se perdio.
 *
 * No es un adorno: el nombre de cada trabajador se tecleaba, y al repasar la
 * base maestra aparecio lo que eso produce en cinco años —el mismo DNI escrito
 * de dos formas, un numero de celular en el campo del documento y un
 * «11111111» inventado para salir del paso—. Un nombre que viene de RENIEC no
 * se escribe mal.
 *
 * Lo que mas importa aqui no es el acierto sino el fallo: sin token, con la API
 * caida o con un DNI que no existe, esto NO puede bloquear el alta. En obra se
 * da de alta a la cuadrilla a las seis de la mañana y no se espera a que una
 * API de terceros vuelva.
 */
class PersonDniLookupTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'people';
    }

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'people.view_private_info', 'guard_name' => 'web']);

        DocumentType::firstOrCreate(
            ['country_id' => 1, 'code' => 'DNI'],
            ['slug' => Str::random(22), 'name' => 'Documento Nacional de Identidad',
             'min_length' => 8, 'max_length' => 8, 'is_active' => true, 'created_by' => 1],
        );

        config(['services.apis_net_pe.token' => 'token-de-prueba']);
        config(['services.apis_net_pe.url' => 'https://api.apis.net.pe']);
        Cache::flush();
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return \App\Models\Person::create($this->base() + [
            'name' => 'Ana', 'lastname' => 'Quispe', 'doc_type' => 'DNI', 'num_doc' => '45871236',
        ]);
    }

    private function respuestaDe(array $cuerpo, int $estado = 200): void
    {
        Http::fake(['api.apis.net.pe/*' => Http::response($cuerpo, $estado)]);
    }

    public function test_devuelve_el_nombre_de_reniec(): void
    {
        $this->respuestaDe([
            'numeroDocumento' => '43673535',
            'nombres'         => 'EDISON YOSIMAR',
            'apellidoPaterno' => 'ROSALES',
            'apellidoMaterno' => 'CAPCHA',
        ]);

        $r = $this->actingAs($this->admin())
            ->getJson(route('business_management.people.lookup_dni', ['dni' => '43673535']));

        $r->assertOk()
            ->assertJson([
                'estado'    => 'encontrado',
                'nombres'   => 'EDISON YOSIMAR',
                'apellidos' => 'ROSALES CAPCHA',
            ]);
    }

    /** Con el plan basico solo llega el nombre completo: PATERNO MATERNO NOMBRES. */
    public function test_parte_el_nombre_completo_cuando_no_vienen_los_campos_sueltos(): void
    {
        $this->respuestaDe(['nombreCompleto' => 'ROSALES CAPCHA EDISON YOSIMAR']);

        $this->actingAs($this->admin())
            ->getJson(route('business_management.people.lookup_dni', ['dni' => '43673535']))
            ->assertJson([
                'estado'    => 'encontrado',
                'nombres'   => 'EDISON YOSIMAR',
                'apellidos' => 'ROSALES CAPCHA',
            ]);
    }

    public function test_un_dni_que_no_existe_no_es_un_error(): void
    {
        $this->respuestaDe([], 404);

        $this->actingAs($this->admin())
            ->getJson(route('business_management.people.lookup_dni', ['dni' => '11111111']))
            ->assertOk()
            ->assertJson(['estado' => 'no_encontrado']);
    }

    /** Lo mas importante: la API caida no puede tumbar el alta. */
    public function test_si_la_api_no_contesta_la_pantalla_sigue_pudiendo_escribir(): void
    {
        Http::fake(['api.apis.net.pe/*' => fn () => throw new \RuntimeException('timeout')]);

        $this->actingAs($this->admin())
            ->getJson(route('business_management.people.lookup_dni', ['dni' => '43673535']))
            ->assertOk()
            ->assertJson(['estado' => 'error']);
    }

    /**
     * Sin token no se le enseña nada al usuario: no ha hecho nada mal y no lo
     * puede arreglar. La pantalla distingue este estado y se queda callada.
     */
    public function test_sin_token_configurado_avisa_pero_no_falla(): void
    {
        config(['services.apis_net_pe.token' => null]);
        Http::fake();

        $this->actingAs($this->admin())
            ->getJson(route('business_management.people.lookup_dni', ['dni' => '43673535']))
            ->assertOk()
            ->assertJson(['estado' => 'sin_configurar']);

        Http::assertNothingSent();
    }

    /** Un numero que no es un DNI ni se manda: cada llamada gasta credito. */
    public function test_un_numero_que_no_es_dni_no_llega_a_salir(): void
    {
        Http::fake();

        $this->actingAs($this->admin())
            ->getJson(route('business_management.people.lookup_dni', ['dni' => '946733493']))
            ->assertOk()
            ->assertJson(['estado' => 'no_encontrado']);

        Http::assertNothingSent();
    }

    /** Un nombre no cambia: la segunda consulta del mismo DNI sale de la cache. */
    public function test_el_acierto_se_guarda_y_no_se_vuelve_a_preguntar(): void
    {
        $this->respuestaDe([
            'nombres' => 'EDISON YOSIMAR', 'apellidoPaterno' => 'ROSALES', 'apellidoMaterno' => 'CAPCHA',
        ]);

        $servicio = app(ConsultaDni::class);
        $servicio->buscar('43673535');
        $servicio->buscar('43673535');

        Http::assertSentCount(1);
    }

    /** El fallo NO se guarda: el que no aparece hoy puede aparecer mañana. */
    public function test_el_fallo_no_se_queda_en_cache(): void
    {
        $this->respuestaDe([], 404);

        $servicio = app(ConsultaDni::class);
        $servicio->buscar('43673535');
        $servicio->buscar('43673535');

        Http::assertSentCount(2);
    }

    /** Quien solo mira no abre la consulta: cada llamada gasta credito. */
    public function test_un_usuario_de_solo_lectura_no_puede_consultar(): void
    {
        Http::fake();

        $this->actingAs($this->soloLectura())
            ->getJson(route('business_management.people.lookup_dni', ['dni' => '43673535']))
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
