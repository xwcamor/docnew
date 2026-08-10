<?php

namespace Tests\Feature\FieldWork;

use App\Models\Company;
use App\Models\FormAnswer;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Person;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanPerson;
use App\Models\WorkType;
use App\Services\FieldWork\FormSubmissionService;
use App\Services\FieldWork\FormTemplateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El contrato entre la pantalla de llenado y el motor de formatos.
 *
 * Los cuatro tipos compuestos —matriz de riesgo (AST/PTF), EPP por trabajador,
 * IHM por herramienta y banco de preguntas (PTF)— no se guardan como texto:
 * tienen forma, y FormSubmissionService::validarValor() la exige. Esta prueba
 * manda EXACTAMENTE lo que emiten los componentes de
 * resources/js/Components/FormFields, para que un cambio en la interfaz que se
 * salga del contrato se vea aqui y no en obra.
 *
 * Lo que se comprueba:
 *   1. el valor de cada tipo pasa la validacion y cae en `value_json`;
 *   2. los tipos por fila (matriz, EPP, IHM) usan `row_index`, uno por fila;
 *   3. volver a guardar actualiza la fila, no la duplica;
 *   4. quitar una fila la borra de verdad, no deja una fila vacia;
 *   5. con todo respondido, la entrega se puede confirmar;
 *   6. y un campo obligatorio que se vacie del todo impide cerrar el formato.
 */
class CompositeFieldAnswersTest extends TestCase
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

    public function test_la_matriz_de_riesgo_se_guarda_una_fila_por_peligro(): void
    {
        [$entrega, $plantilla] = $this->entregaAst();

        app(FormSubmissionService::class)->responder($entrega, [
            ['code' => 'matriz_de_riesgo', 'row' => 0, 'value' => $this->filaRiesgo('c2', 'p3', 6, 'alto')],
            ['code' => 'matriz_de_riesgo', 'row' => 1, 'value' => $this->filaRiesgo('c5', 'p4', 20, 'bajo')],
        ]);

        $filas = $this->respuestas($entrega, $plantilla, 'matriz_de_riesgo');

        $this->assertCount(2, $filas);
        $this->assertSame([0, 1], $filas->pluck('row_index')->all());

        // La forma completa cae en value_json, no en value_text.
        $this->assertNull($filas[0]->value_text);
        $this->assertSame('Excavacion manual', $filas[0]->value_json['actividad']);
        $this->assertSame('c2', $filas[0]->value_json['severidad']);
        $this->assertSame('p3', $filas[0]->value_json['probabilidad']);
        $this->assertSame('Contusiones y fracturas', $filas[0]->value_json['riesgo']);
        $this->assertSame(6, $filas[0]->value_json['valor_riesgo']);
        $this->assertSame('alto', $filas[0]->value_json['nivel']);
        $this->assertSame('bajo', $filas[1]->value_json['nivel']);
    }

    public function test_volver_a_guardar_actualiza_la_fila_y_quitarla_la_borra(): void
    {
        [$entrega, $plantilla] = $this->entregaAst();
        $servicio = app(FormSubmissionService::class);

        $servicio->responder($entrega, [
            ['code' => 'matriz_de_riesgo', 'row' => 0, 'value' => $this->filaRiesgo('c2', 'p3', 6, 'alto')],
            ['code' => 'matriz_de_riesgo', 'row' => 1, 'value' => $this->filaRiesgo('c5', 'p4', 20, 'bajo')],
        ]);

        // El usuario quita la segunda fila: la pantalla reenvia lo que queda y
        // manda el hueco en null. Eso borra la fila, no la deja vacia.
        $servicio->responder($entrega, [
            ['code' => 'matriz_de_riesgo', 'row' => 0, 'value' => $this->filaRiesgo('c1', 'p1', 1, 'alto')],
            ['code' => 'matriz_de_riesgo', 'row' => 1, 'value' => null],
        ]);

        $filas = $this->respuestas($entrega, $plantilla, 'matriz_de_riesgo');

        $this->assertCount(1, $filas, 'la fila quitada tiene que desaparecer, no quedar en blanco');
        $this->assertSame('c1', $filas[0]->value_json['severidad']);
    }

    /**
     * El caso que se colaba: un campo obligatorio de varias filas al que se le
     * quitan todas. Antes quedaba una fila vacia, `faltantes()` la contaba como
     * respondida y el formato se cerraba sin matriz de riesgo.
     */
    public function test_vaciar_un_campo_obligatorio_impide_cerrar_el_formato(): void
    {
        [$entrega] = $this->entregaAst();
        $servicio = app(FormSubmissionService::class);

        $servicio->responder($entrega, [
            ['code' => 'matriz_de_riesgo', 'row' => 0, 'value' => $this->filaRiesgo('c2', 'p3', 6, 'alto')],
        ]);
        $this->assertSame([], $servicio->faltantes($entrega));

        $servicio->responder($entrega, [
            ['code' => 'matriz_de_riesgo', 'row' => 0, 'value' => null],
        ]);

        // `faltantes()` nombra el campo por su ETIQUETA, no por su codigo: la
        // lista sale en un aviso delante de quien rellena el formato en obra.
        $this->assertContains('Matriz de riesgo', $servicio->faltantes($entrega));

        // `DomainException` y no `InvalidArgumentException`: no es un fallo de
        // programacion, es el sistema diciendo que todavia no. La diferencia se
        // ve en pantalla — ver las pruebas del final.
        $this->expectException(\DomainException::class);
        $servicio->confirmar($entrega);
    }

    /** Una fila vacia guardada por una version anterior tampoco cuenta. */
    public function test_una_fila_vacia_heredada_no_cuenta_como_respondida(): void
    {
        [$entrega, $plantilla] = $this->entregaAst();
        $servicio = app(FormSubmissionService::class);

        $campo = $plantilla->fields()->where('code', 'matriz_de_riesgo')->sole();

        // Lo que dejaba el guardado anterior: la fila existe, sin contenido.
        FormAnswer::create([
            'form_submission_id' => $entrega->id,
            'form_field_id'      => $campo->id,
            'row_index'          => 0,
        ]);

        $this->assertContains('Matriz de riesgo', $servicio->faltantes($entrega));
    }

    public function test_el_epp_guarda_una_fila_por_trabajador_con_su_correccion(): void
    {
        [$entrega, $plantilla, $personas] = $this->entregaEpp();

        app(FormSubmissionService::class)->responder($entrega, [
            ['code' => 'epp_por_trabajador', 'row' => 0, 'value' => [
                'person_slug' => $personas[0]->slug,
                'person_name' => 'Ana Quispe',
                'person_doc'  => '40000001',
                'items' => [
                    ['item' => 'Casco', 'answer' => 'Conforme'],
                    ['item' => 'Guantes', 'answer' => 'Conforme'],
                ],
                'conforme' => true,
            ]],
            ['code' => 'epp_por_trabajador', 'row' => 1, 'value' => [
                'person_slug' => $personas[1]->slug,
                'person_name' => 'Luis Mamani',
                'person_doc'  => '40000002',
                'items' => [
                    ['item' => 'Casco', 'answer' => 'No conforme'],
                    ['item' => 'Guantes', 'answer' => 'No aplica'],
                ],
                'conforme' => false,
                // Los campos de `config.extra`, que solo se llenan si algo salio mal.
                'correction_measure'      => 'Se entrega casco nuevo antes de iniciar',
                'deadline_date'           => '2026-08-08',
                'correction_verification' => 'Verificado por el supervisor',
            ]],
        ]);

        $filas = $this->respuestas($entrega, $plantilla, 'epp_por_trabajador');

        $this->assertCount(2, $filas);
        $this->assertTrue($filas[0]->value_json['conforme']);
        $this->assertSame('Conforme', $filas[0]->value_json['items'][0]['answer']);
        $this->assertFalse($filas[1]->value_json['conforme']);
        $this->assertSame('2026-08-08', $filas[1]->value_json['deadline_date']);
    }

    public function test_el_ihm_guarda_una_fila_por_herramienta(): void
    {
        [$entrega, $plantilla] = $this->entregaIhm();

        app(FormSubmissionService::class)->responder($entrega, [
            ['code' => 'inspeccion_de_herramientas', 'row' => 0, 'value' => [
                'tool' => 'Martillo',
                'items' => [
                    ['item' => 'Condiciones generales de las herramientas.', 'answer' => 'Cumple'],
                    ['item' => 'Empalmes y conexiones.', 'answer' => 'No aplica'],
                ],
                'conforme' => true,
            ]],
            ['code' => 'inspeccion_de_herramientas', 'row' => 1, 'value' => [
                'tool' => 'Escalera',
                'items' => [
                    ['item' => 'Condiciones generales de las herramientas.', 'answer' => 'No cumple'],
                    ['item' => 'Empalmes y conexiones.', 'answer' => 'Cumple'],
                ],
                'conforme' => false,
            ]],
        ]);

        $filas = $this->respuestas($entrega, $plantilla, 'inspeccion_de_herramientas');

        $this->assertCount(2, $filas);
        $this->assertSame('Martillo', $filas[0]->value_json['tool']);
        $this->assertSame('No cumple', $filas[1]->value_json['items'][0]['answer']);
        $this->assertFalse($filas[1]->value_json['conforme']);
    }

    public function test_el_banco_de_preguntas_se_guarda_como_una_sola_lista(): void
    {
        [$entrega, $plantilla] = $this->entregaPtf();

        app(FormSubmissionService::class)->responder($entrega, [
            ['code' => 'preguntas', 'value' => [
                ['question' => '¿DETENTE y piensa antes de actuar?', 'answer' => 'Si'],
                ['question' => '¿El area esta señalizada?', 'answer' => 'No'],
                ['question' => '¿Se requiere permiso de altura?', 'answer' => 'No aplica'],
            ]],
        ]);

        $filas = $this->respuestas($entrega, $plantilla, 'preguntas');

        // Una sola respuesta, en la fila 0, con la lista completa dentro.
        $this->assertCount(1, $filas);
        $this->assertSame(0, $filas[0]->row_index);
        $this->assertCount(3, $filas[0]->value_json);
        $this->assertSame('Si', $filas[0]->value_json[0]['answer']);
        $this->assertSame('No aplica', $filas[0]->value_json[2]['answer']);
    }

    public function test_con_los_compuestos_respondidos_la_entrega_se_confirma(): void
    {
        [$entrega] = $this->entregaAst();
        $servicio = app(FormSubmissionService::class);

        $this->assertSame(['Matriz de riesgo'], $servicio->faltantes($entrega));

        $servicio->responder($entrega, [
            ['code' => 'matriz_de_riesgo', 'row' => 0, 'value' => $this->filaRiesgo('c2', 'p3', 6, 'alto')],
        ]);

        $this->assertSame([], $servicio->faltantes($entrega));
        $this->assertSame('confirmed', $servicio->confirmar($entrega)->status);
    }

    public function test_un_compuesto_sin_su_forma_no_se_guarda(): void
    {
        [$entrega] = $this->entregaAst();

        $this->expectException(\InvalidArgumentException::class);

        // Sin severidad ni probabilidad no hay matriz de riesgo que valga.
        app(FormSubmissionService::class)->responder($entrega, [
            ['code' => 'matriz_de_riesgo', 'row' => 0, 'value' => ['actividad' => 'Excavacion manual']],
        ]);
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    /** Una fila de la matriz tal y como la emite RiskMatrixField.vue. */
    protected function filaRiesgo(string $severidad, string $probabilidad, int $riesgo, string $nivel): array
    {
        return [
            'actividad'    => 'Excavacion manual',
            'peligro'      => 'Caida de personas al mismo nivel',
            // `riesgo` es la consecuencia, en texto, como en la v1: la fila va
            // actividad → peligro → riesgo → control. El numero de la matriz es
            // `valor_riesgo`.
            'riesgo'       => 'Contusiones y fracturas',
            'control'      => 'Delimitar y señalizar el area',
            'severidad'    => $severidad,
            'probabilidad' => $probabilidad,
            'valor_riesgo' => $riesgo,
            'nivel'        => $nivel,
        ];
    }

    /** @return array{0:FormSubmission,1:FormTemplate} */
    protected function entregaAst(): array
    {
        return $this->entrega('AST', [
            'code' => 'matriz_de_riesgo', 'field_type' => 'risk_matrix', 'is_required' => true,
            'config' => [
                'activities'    => ['Excavacion manual', 'Trabajo en altura'],
                'dangers'       => ['Caida de personas al mismo nivel', 'Contacto electrico'],
                'controls'      => ['Delimitar y señalizar el area', 'Bloqueo y etiquetado'],
                'severities'    => ['c1', 'c2', 'c3', 'c4', 'c5'],
                'probabilities' => ['p1', 'p2', 'p3', 'p4', 'p5'],
                'formula'       => 'severidad * probabilidad',
            ],
        ]);
    }

    /** @return array{0:FormSubmission,1:FormTemplate,2:array<int,Person>} */
    protected function entregaEpp(): array
    {
        [$entrega, $plantilla, $plan] = $this->entrega('EPP', [
            'code' => 'epp_por_trabajador', 'field_type' => 'person_checklist', 'is_required' => true,
            'config' => [
                'items'   => ['Casco', 'Guantes'],
                'answers' => ['Conforme', 'No conforme', 'No aplica'],
                'extra'   => ['correction_measure', 'deadline_date', 'correction_verification'],
            ],
        ], conCuadrilla: true);

        $personas = $plan->people()->with('person')->get()->map(fn ($p) => $p->person)->all();

        return [$entrega, $plantilla, $personas];
    }

    /** @return array{0:FormSubmission,1:FormTemplate} */
    protected function entregaIhm(): array
    {
        return $this->entrega('IHM', [
            'code' => 'inspeccion_de_herramientas', 'field_type' => 'tool_checklist', 'is_required' => true,
            'config' => [
                'tools'   => ['Martillo', 'Escalera'],
                'items'   => ['Condiciones generales de las herramientas.', 'Empalmes y conexiones.'],
                'answers' => ['No cumple', 'Cumple', 'No aplica'],
            ],
        ]);
    }

    /** @return array{0:FormSubmission,1:FormTemplate} */
    protected function entregaPtf(): array
    {
        return $this->entrega('PTF', [
            'code' => 'preguntas', 'field_type' => 'question_bank', 'is_required' => true,
            'config' => [
                'questions' => [
                    '¿DETENTE y piensa antes de actuar?',
                    '¿El area esta señalizada?',
                    '¿Se requiere permiso de altura?',
                ],
                'answers' => ['Si', 'No', 'No aplica'],
            ],
        ]);
    }

    /**
     * Plantilla publicada con un solo campo compuesto, su plan y la entrega
     * abierta. Se usa el constructor real para que la config pase por las
     * mismas comprobaciones que en produccion.
     *
     * @return array{0:FormSubmission,1:FormTemplate,2:WorkPlan}
     */
    protected function entrega(string $codigo, array $campo, bool $conCuadrilla = false): array
    {
        $usuario = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $constructor = app(FormTemplateBuilder::class);

        $plantilla = $constructor->crear([
            'country_id' => 1, 'tenant_id' => 1, 'created_by' => $usuario->id, 'code' => $codigo,
        ]);

        $constructor->agregarCampo($constructor->agregarSeccion($plantilla), $campo);
        $plantilla = $constructor->publicar($plantilla);

        $plan = $this->plan($usuario);

        if ($conCuadrilla) {
            foreach ([['Ana', 'Quispe', '40000001'], ['Luis', 'Mamani', '40000002']] as [$nombre, $apellido, $doc]) {
                $persona = Person::create([
                    'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => $usuario->id,
                    'doc_type' => 'DNI', 'num_doc' => $doc, 'name' => $nombre, 'lastname' => $apellido,
                ]);

                WorkPlanPerson::create([
                    'slug' => Str::random(22), 'work_plan_id' => $plan->id, 'person_id' => $persona->id,
                ]);
            }
        }

        $entrega = app(FormSubmissionService::class)->abrir($plan, $plantilla, $usuario->id);

        return [$entrega, $plantilla, $plan];
    }

    protected function plan(User $usuario): WorkPlan
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => $usuario->id];

        $empresa = Company::create($base + [
            'num_doc' => (string) random_int(20000000000, 20999999999),
            'name' => 'Contratista', 'complete_name' => 'Contratista SAC', 'is_active' => true,
        ]);

        $tipo = WorkType::create($base + ['code' => 'MTTO']);
        $lugar = WorkLocation::create($base + ['name' => 'Planta']);

        return WorkPlan::create($base + [
            'company_id'       => $empresa->id,
            'work_type_id'     => $tipo->id,
            'work_location_id' => $lugar->id,
            'user_id'          => $usuario->id,
            'code'             => 'OT-' . random_int(1000, 9999),
            'description'      => 'Mantenimiento programado',
            'date_start'       => today(),
        ]);
    }

    /** Las respuestas de un campo, ordenadas por fila. */
    protected function respuestas(FormSubmission $entrega, FormTemplate $plantilla, string $code)
    {
        $campo = $plantilla->fields()->where('code', $code)->sole();

        return FormAnswer::where('form_submission_id', $entrega->id)
            ->where('form_field_id', $campo->id)
            ->orderBy('row_index')
            ->get();
    }

    // ── Que confirmar sin lo obligatorio no reviente ─────────────────────────
    //
    // Lo que llego reportado: una pantalla de error 500 con la traza de PHP,
    // delante de alguien de pie en la obra con guantes y una tablet. El texto
    // era el correcto —«Faltan campos obligatorios: Matriz de riesgo»— pero
    // servido como si el programa se hubiera roto. No se habia roto: era el
    // sistema diciendo que todavia no, que es una cosa normal.

    /** Quien llena el formato en obra: solo necesita poder editarlo. */
    private function usuarioDeObra(): User
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permiso = Permission::firstOrCreate(['name' => 'form_submissions.edit', 'guard_name' => 'web']);
        $rol = Role::firstOrCreate(['name' => 'obra', 'guard_name' => 'web'], ['description' => 'Llena formatos']);
        $rol->syncPermissions([$permiso]);

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);

        return $u;
    }

    /** El servicio dice «todavia no», no «me he roto». */
    public function test_confirmar_sin_lo_obligatorio_es_una_regla_y_no_una_averia(): void
    {
        [$entrega] = $this->entregaAst();
        $servicio = app(FormSubmissionService::class);

        try {
            $servicio->confirmar($entrega);
            $this->fail('Confirmar sin la matriz de riesgo tenía que negarse.');
        } catch (\DomainException $e) {
            // El mensaje nombra el campo por su etiqueta y sale de
            // `resources/lang`, no de una cadena castellana dentro del servicio.
            $this->assertStringContainsString('Matriz de riesgo', $e->getMessage());
        }

        $this->assertSame('draft', $entrega->fresh()->status);
    }

    /**
     * Y por la puerta de verdad —la peticion HTTP— vuelve como aviso, no como
     * un 500. Es lo unico que vio quien lo reporto.
     */
    public function test_por_http_vuelve_como_aviso_y_no_como_error(): void
    {
        [$entrega] = $this->entregaAst();

        $this->actingAs($this->usuarioDeObra())
            ->from(route('field_work.forms.open', [$entrega->workPlan->slug, $entrega->formTemplate->slug]))
            ->post(route('field_work.forms.confirm', $entrega->slug))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('draft', $entrega->fresh()->status);
    }

    /** Con lo obligatorio puesto, cierra. */
    public function test_con_todo_lleno_si_cierra(): void
    {
        [$entrega] = $this->entregaAst();
        $servicio = app(FormSubmissionService::class);

        $servicio->responder($entrega, [
            ['code' => 'matriz_de_riesgo', 'row' => 0, 'value' => $this->filaRiesgo('c2', 'p3', 6, 'alto')],
        ]);

        $servicio->confirmar($entrega);

        $this->assertSame('confirmed', FormSubmission::find($entrega->id)->status);
    }

    /**
     * Guardar POR HTTP, que es por donde entra de verdad.
     *
     * Aqui estaba el agujero: todas las pruebas de arriba llaman al servicio a
     * pelo y el servicio siempre funciono. Lo que fallaba era el paso previo.
     *
     * `$request->validate()` no devuelve la peticion, devuelve SOLO las claves
     * que aparecen en las reglas — y `answers.*.value` no estaba declarada. Cada
     * respuesta llegaba al servicio con su codigo y su fila y sin el valor; el
     * servicio lo leia como null, lo tomaba por un hueco y lo descartaba. No se
     * podia guardar nada, en ningun formato, y la pantalla contestaba
     * «Respuestas guardadas».
     */
    public function test_guardar_por_http_guarda_el_valor_y_no_solo_el_codigo(): void
    {
        [$entrega] = $this->entregaAst();

        $this->actingAs($this->usuarioDeObra())
            ->from(route('field_work.forms.open', [$entrega->workPlan->slug, $entrega->formTemplate->slug]))
            ->post(route('field_work.forms.answer', $entrega->slug), [
                'answers' => [
                    ['code' => 'matriz_de_riesgo', 'row' => 0, 'value' => $this->filaRiesgo('c2', 'p3', 6, 'alto')],
                ],
            ])
            ->assertSessionHasNoErrors();

        $guardada = $entrega->answers()->first();

        $this->assertNotNull($guardada, 'La respuesta no llegó a la base: el valor se perdió por el camino.');
        $this->assertSame('c2', $guardada->value_json['severidad']);
        $this->assertSame('p3', $guardada->value_json['probabilidad']);
    }

    /** Y con eso, rellenar y confirmar por HTTP cierra el formato de verdad. */
    public function test_llenar_y_confirmar_por_http_cierra_el_formato(): void
    {
        [$entrega] = $this->entregaAst();
        $usuario = $this->usuarioDeObra();

        $this->actingAs($usuario)
            ->post(route('field_work.forms.answer', $entrega->slug), [
                'answers' => [
                    ['code' => 'matriz_de_riesgo', 'row' => 0, 'value' => $this->filaRiesgo('c1', 'p1', 1, 'alto')],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($usuario)
            ->post(route('field_work.forms.confirm', $entrega->slug))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $this->assertSame('confirmed', $entrega->fresh()->status);
    }

    /** Un texto suelto tambien: no es solo cosa de los tipos compuestos. */
    public function test_un_campo_de_texto_tambien_se_guarda_por_http(): void
    {
        [$entrega, $plantilla] = $this->entrega('OBS', [
            'code' => 'observaciones', 'field_type' => 'textarea', 'is_required' => false, 'config' => [],
        ]);

        $this->actingAs($this->usuarioDeObra())
            ->post(route('field_work.forms.answer', $entrega->slug), [
                'answers' => [['code' => 'observaciones', 'value' => 'Sin novedad en la faena']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Sin novedad en la faena', $entrega->answers()->first()?->value_text);
    }
}
