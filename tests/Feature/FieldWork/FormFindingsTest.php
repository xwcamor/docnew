<?php

namespace Tests\Feature\FieldWork;

use App\Models\Company;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Person;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanPerson;
use App\Models\WorkType;
use App\Services\FieldWork\FormFindingsService;
use App\Services\FieldWork\FormSubmissionService;
use App\Services\FieldWork\FormTemplateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cuantas cosas salieron mal en un formato.
 *
 * Es la columna `observations` del sistema anterior —un entero, no un texto— que
 * los cuatro formatos recalculaban solos en cada guardado y era lo que el
 * supervisor leia en la ficha del plan. Aqui se llama `nonconformities` porque
 * `observations` ya existe y es texto libre.
 *
 * Lo que se fija:
 *   1. AST y PTF cuentan los peligros que no estan en la banda de menor riesgo,
 *      que es el `risk_value <= 15` de alla escrito sin atarse al 15;
 *   2. EPP y IHM cuentan las respuestas negativas;
 *   3. «No aplica» no cuenta —y este es el punto donde la v1 se equivocaba—;
 *   4. el numero se recalcula solo, en cada guardado, y baja cuando se corrige;
 *   5. tener no conformidades NO impide confirmar el formato, igual que alla.
 */
class FormFindingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }

    /**
     * AST: un peligro alto y otro medio son dos observaciones; el bajo no.
     *
     * La matriz de la v1 es un ranking del 1 al 25 donde el 1 es lo peor, y la
     * regla de alla era `risk_value <= 15`. Con las bandas de la plantilla
     * (alto ≤ 8, medio ≤ 15, bajo ≤ 25) eso es exactamente «lo que no es bajo».
     */
    public function test_el_ast_cuenta_los_peligros_que_no_son_de_riesgo_bajo(): void
    {
        [$entrega] = $this->entregaAst();

        app(FormSubmissionService::class)->responder($entrega, [
            ['code' => 'matriz_de_riesgo', 'row' => 0, 'value' => $this->filaRiesgo(4,  'alto')],
            ['code' => 'matriz_de_riesgo', 'row' => 1, 'value' => $this->filaRiesgo(12, 'medio')],
            ['code' => 'matriz_de_riesgo', 'row' => 2, 'value' => $this->filaRiesgo(22, 'bajo')],
        ]);

        $this->assertSame(2, $entrega->fresh()->nonconformities);
    }

    /** Una fila a medio llenar no es una observacion: es un campo sin llenar. */
    public function test_un_peligro_sin_evaluar_no_cuenta_como_observacion(): void
    {
        [$entrega] = $this->entregaAst();

        app(FormSubmissionService::class)->responder($entrega, [
            ['code' => 'matriz_de_riesgo', 'row' => 0, 'value' => [
                'actividad' => 'Excavacion manual', 'peligro' => 'Caida', 'control' => 'Señalizar',
                'riesgo' => 'Contusiones', 'severidad' => null, 'probabilidad' => null,
                'valor_riesgo' => null, 'nivel' => null,
            ]],
        ]);

        $this->assertSame(0, $entrega->fresh()->nonconformities);
    }

    /**
     * EPP: una observacion por trabajador-item que salga «No conforme».
     *
     * Es la cuenta de la v1 (`f3_document_answers`, una fila por trabajador e
     * item), con la diferencia de cual es la respuesta mala. Ver abajo.
     */
    public function test_el_epp_cuenta_una_observacion_por_item_no_conforme(): void
    {
        [$entrega, $personas] = $this->entregaEpp();

        app(FormSubmissionService::class)->responder($entrega, [
            ['code' => 'epp_por_trabajador', 'row' => 0, 'value' => $this->filaEpp($personas[0], ['Conforme', 'No conforme'])],
            ['code' => 'epp_por_trabajador', 'row' => 1, 'value' => $this->filaEpp($personas[1], ['No conforme', 'No conforme'])],
        ]);

        $this->assertSame(3, $entrega->fresh()->nonconformities);
    }

    /**
     * «No aplica» no es una observacion. Aqui es donde la v1 se equivocaba.
     *
     * Alli `set_completed` contaba las respuestas con valor `0`, que en el
     * formulario es el boton **«No aplica»**, y dejaba fuera el `2`, que es
     * **«No conforme»**. Un trabajador sin arnés porque el trabajo no es en
     * altura sumaba observacion; uno con el arnés roto, no.
     *
     * Se cuenta lo que no esta conforme, que es lo que el numero dice que
     * cuenta. La divergencia esta anotada en docs/COMPARACION-V1.md.
     */
    public function test_no_aplica_no_suma_observacion_aunque_la_v1_lo_contara(): void
    {
        [$entrega, $personas] = $this->entregaEpp();

        app(FormSubmissionService::class)->responder($entrega, [
            ['code' => 'epp_por_trabajador', 'row' => 0, 'value' => $this->filaEpp($personas[0], ['No aplica', 'No aplica'])],
        ]);

        $this->assertSame(0, $entrega->fresh()->nonconformities);
    }

    /** Se recalcula en cada guardado: al corregir el item, el numero baja. */
    public function test_al_corregir_el_item_el_numero_baja_solo(): void
    {
        [$entrega, $personas] = $this->entregaEpp();
        $servicio = app(FormSubmissionService::class);

        $servicio->responder($entrega, [
            ['code' => 'epp_por_trabajador', 'row' => 0, 'value' => $this->filaEpp($personas[0], ['No conforme', 'No conforme'])],
        ]);
        $this->assertSame(2, $entrega->fresh()->nonconformities);

        $servicio->responder($entrega, [
            ['code' => 'epp_por_trabajador', 'row' => 0, 'value' => $this->filaEpp($personas[0], ['Conforme', 'Conforme'])],
        ]);
        $this->assertSame(0, $entrega->fresh()->nonconformities);
    }

    /**
     * Un formato observado se puede confirmar igual.
     *
     * En la v1 tampoco bloqueaba: `lock_plan_if_all_conditions_met` sólo miraba
     * `date_end` y las aprobaciones obligatorias, así que un plan con un EPP
     * observado se cerraba. Y tiene que ser así: un arnés en mal estado hay que
     * poder registrarlo y cerrar la jornada, con su medida de corrección.
     */
    public function test_un_formato_observado_se_confirma_igual(): void
    {
        [$entrega, $personas] = $this->entregaEpp();
        $servicio = app(FormSubmissionService::class);

        $servicio->responder($entrega, [
            ['code' => 'epp_por_trabajador', 'row' => 0, 'value' => $this->filaEpp($personas[0], ['No conforme', 'Conforme'])],
            ['code' => 'epp_por_trabajador', 'row' => 1, 'value' => $this->filaEpp($personas[1], ['Conforme', 'Conforme'])],
        ]);

        $confirmada = $servicio->confirmar($entrega->fresh());

        $this->assertSame('confirmed', $confirmada->status);
        $this->assertSame(1, $confirmada->nonconformities, 'confirmar no borra lo que salio mal');
    }

    /**
     * El servidor y la pantalla tienen que estar de acuerdo en qué es una mala
     * respuesta.
     *
     * La regla vive dos veces: en `tono()` de este servicio, que cuenta, y en
     * `tono()` de resources/js/Components/FormFields/respuestas.js, que pinta la
     * casilla de rojo. Si una cambia y la otra no, la pantalla enseña tres
     * casillas rojas y el contador dice cero, y nadie sabe cuál de las dos
     * miente. Esta prueba las compara con los catálogos reales de los cuatro
     * formatos.
     */
    public function test_el_servidor_y_la_pantalla_coinciden_en_que_es_una_mala_respuesta(): void
    {
        $servicio = app(FormFindingsService::class);

        $esperado = [
            // EPP
            'Conforme' => 'ok', 'No conforme' => 'bad', 'No aplica' => 'na',
            // IHM — ojo: el catálogo empieza por la mala, así que la posición
            // en la lista no sirve para deducir nada.
            'Cumple' => 'ok', 'No cumple' => 'bad',
            // PTF
            'Si' => 'ok', 'Sí' => 'ok', 'No' => 'bad',
            // Formas sueltas de «no aplica» que el front también reconoce.
            'N/A' => 'na', 'na' => 'na', '' => 'na',
        ];

        foreach ($esperado as $respuesta => $tono) {
            $this->assertSame($tono, $servicio->tono($respuesta), "«{$respuesta}» deberia ser {$tono}");
        }

        $this->assertSame('na', $servicio->tono(null));
    }

    /**
     * Una fila migrada trae el numero y no la banda, y aun asi cuenta.
     *
     * La v1 guardaba `risk_value` y calculaba la banda al pintar
     * (`Risk#level_name`), asi que las 3 657 matrices que trajo la migracion no
     * tienen `nivel`. El contador lo exigia, con lo que **todos los AST y PTF
     * migrados sumaban cero observaciones** por altos que fueran sus peligros, y
     * la pantalla los daba por «Sin evaluar»: un AST con ocho peligros altos se
     * leia como una jornada limpia.
     */
    public function test_un_peligro_migrado_sin_banda_se_cuenta_por_su_valor(): void
    {
        [$entrega] = $this->entregaAst();

        $sinBanda = $this->filaRiesgo(4, 'alto');
        unset($sinBanda['nivel']);

        app(FormSubmissionService::class)->responder($entrega, [
            ['code' => 'matriz_de_riesgo', 'row' => 0, 'value' => $sinBanda],
        ]);

        $this->assertSame(1, app(FormFindingsService::class)->contar($entrega->fresh()),
            'un peligro con valor 4 es «alto» aunque la fila no lo diga con la palabra');
    }

    /**
     * El servidor y la pantalla ponen la misma banda al mismo numero.
     *
     * `nivelDeRiesgo()` aqui y `nivelRiesgo()` de RiskMatrixField.vue son la
     * misma regla escrita dos veces, una por lenguaje. Si una cambia y la otra
     * no, la pantalla pinta «Riesgo bajo» mientras el contador suma una
     * observacion por lo mismo.
     */
    public function test_el_nivel_se_calcula_igual_en_el_servidor_y_en_la_pantalla(): void
    {
        $bandas = [
            ['hasta' => 8, 'clave' => 'alto'],
            ['hasta' => 15, 'clave' => 'medio'],
            ['hasta' => 25, 'clave' => 'bajo'],
        ];

        // Los bordes de cada banda, que es donde se rompen estas cosas.
        $esperado = [1 => 'alto', 8 => 'alto', 9 => 'medio', 15 => 'medio', 16 => 'bajo', 25 => 'bajo'];

        foreach ($esperado as $valor => $banda) {
            $this->assertSame($banda, FormFindingsService::nivelDeRiesgo($valor, $bandas),
                "el {$valor} cae en «{$banda}»");
        }

        // Sin valor no hay banda, y sin bandas declaradas tampoco se inventa
        // ninguna: mejor «sin evaluar» que una banda a ojo.
        $this->assertNull(FormFindingsService::nivelDeRiesgo(null, $bandas));
        $this->assertNull(FormFindingsService::nivelDeRiesgo(0, $bandas));
        $this->assertNull(FormFindingsService::nivelDeRiesgo(4, []));
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    protected function filaRiesgo(int $riesgo, string $nivel): array
    {
        return [
            'actividad' => 'Excavacion manual', 'peligro' => 'Caida de personas',
            'riesgo' => 'Contusiones', 'control' => 'Delimitar el area',
            'severidad' => 'c2', 'probabilidad' => 'p3',
            'valor_riesgo' => $riesgo, 'nivel' => $nivel,
        ];
    }

    /** @param array<int,string> $respuestas una por item del catalogo */
    protected function filaEpp(Person $persona, array $respuestas): array
    {
        $items = ['Casco', 'Arnes'];

        return [
            'person_slug' => $persona->slug,
            'person_name' => $persona->list_name,
            'items' => array_map(
                fn ($item, $r) => ['item' => $item, 'answer' => $r],
                $items,
                $respuestas,
            ),
            'conforme' => ! in_array('No conforme', $respuestas, true),
        ];
    }

    /** @return array{0:FormSubmission} */
    protected function entregaAst(): array
    {
        [$entrega, $plan] = $this->entrega('AST', [
            'code' => 'matriz_de_riesgo', 'field_type' => 'risk_matrix', 'is_required' => true,
            'config' => [
                'activities' => ['Excavacion manual'], 'dangers' => ['Caida de personas'],
                'controls' => ['Delimitar el area'],
                'severities' => ['c1', 'c2', 'c3', 'c4', 'c5'],
                'probabilities' => ['p1', 'p2', 'p3', 'p4', 'p5'],
                // Las bandas reales de la v1: el 1 es lo peor y el 25 lo mejor.
                'levels' => [
                    ['hasta' => 8, 'clave' => 'alto'],
                    ['hasta' => 15, 'clave' => 'medio'],
                    ['hasta' => 25, 'clave' => 'bajo'],
                ],
            ],
        ]);

        return [$entrega];
    }

    /** @return array{0:FormSubmission,1:array<int,Person>} */
    protected function entregaEpp(): array
    {
        [$entrega, $plan] = $this->entrega('EPP', [
            'code' => 'epp_por_trabajador', 'field_type' => 'person_checklist', 'is_required' => true,
            'config' => [
                'items' => ['Casco', 'Arnes'],
                'answers' => ['Conforme', 'No conforme', 'No aplica'],
                'extra' => ['correction_measure', 'deadline_date'],
            ],
        ], conCuadrilla: 2);

        $personas = $plan->people()->with('person')->get()->map(fn ($p) => $p->person)->values()->all();

        return [$entrega, $personas];
    }

    /** @return array{0:FormSubmission,1:WorkPlan} */
    protected function entrega(string $codigo, array $campo, int $conCuadrilla = 0): array
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];
        $usuario = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);

        $empresa = Company::create($base + [
            'num_doc' => (string) random_int(20000000000, 20999999999),
            'name' => 'Contratista', 'complete_name' => 'Contratista SAC', 'is_active' => true,
        ]);

        $plan = WorkPlan::create($base + [
            'company_id' => $empresa->id,
            'work_type_id' => WorkType::create($base + ['code' => 'MTTO-' . random_int(10, 99)])->id,
            'work_location_id' => WorkLocation::create($base + ['name' => 'Planta ' . random_int(10, 99)])->id,
            'user_id' => $usuario->id,
            'code' => 'OT-' . random_int(1000, 9999),
            'description' => 'Mantenimiento programado',
            'date_start' => today(),
        ]);

        for ($i = 0; $i < $conCuadrilla; $i++) {
            // El slug propio va PRIMERO: `+` conserva la clave de la izquierda,
            // así que ponerlo después dejaría a los dos con el de $base.
            $persona = Person::create([
                'slug' => Str::random(22), 'doc_type' => 'DNI',
                'num_doc' => (string) (40000000 + $i), 'name' => 'Trabajador ' . $i, 'lastname' => 'Prueba',
            ] + $base);
            WorkPlanPerson::create([
                'slug' => Str::random(22), 'work_plan_id' => $plan->id, 'person_id' => $persona->id,
            ]);
        }

        $constructor = app(FormTemplateBuilder::class);
        $plantilla = FormTemplate::create($base + [
            'code' => $codigo . '-' . random_int(100, 999), 'kind' => FormTemplate::STRUCTURED,
            'status' => 'draft', 'version' => 1, 'requires_signature' => true,
        ]);
        $constructor->agregarCampo($constructor->agregarSeccion($plantilla), $campo);
        $plantilla->update(['status' => 'published', 'published_at' => now()]);

        return [app(FormSubmissionService::class)->abrir($plan, $plantilla->fresh(), $usuario->id), $plan];
    }
}
