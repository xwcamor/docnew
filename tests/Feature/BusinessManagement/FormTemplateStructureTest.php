<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\FormField;
use App\Models\FormTemplate;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El editor de secciones y campos.
 *
 * El motor de formatos —`form_templates` → `form_sections` → `form_fields`—
 * estaba entero desde el primer dia y no habia ninguna pantalla para llenarlo:
 * el unico camino era `docufiz:migrate-formats`, o sea los cuatro formatos que
 * trajo la v1 y nada mas. Un documento creado desde la aplicacion nacia sin
 * campos, y uno con campos y sin ninguno no se puede publicar.
 *
 * Lo que se comprueba aqui es lo que un formato tiene que aguantar: que se le
 * añadan secciones y campos, que el orden que se ve en pantalla sea el que se
 * guarda, que no lo toque quien no puede — y sobre todo que un documento que ya
 * se firmo NO se pueda reestructurar por detras. Un formato firmado es la
 * evidencia que le enseñas a un inspector.
 */
class FormTemplateStructureTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'form_templates';
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->plantilla();
    }

    private function plantilla(string $nombre = 'AUD Formato', string $code = 'aud_formato', string $estado = 'draft'): FormTemplate
    {
        return FormTemplate::create($this->base() + [
            'name' => $nombre, 'code' => $code,
            'kind' => FormTemplate::STRUCTURED, 'status' => $estado, 'version' => 1,
        ]);
    }

    /** El cuerpo que manda la pantalla: el arbol entero, en el orden que se ve. */
    private function arbol(array $secciones): array
    {
        return ['sections' => $secciones];
    }

    private function campo(array $datos = []): array
    {
        return array_merge([
            'id' => null, 'code' => 'campo_uno', 'field_type' => 'text',
            'label_es' => null, 'label_en' => null,
            'is_required' => false, 'config' => [],
        ], $datos);
    }

    // ── Lo basico: que se pueda definir un formato ───────────────────────────

    /**
     * Añadir una seccion con un campo y que quede guardado. Es literalmente lo
     * que no se podia hacer, y sin esto el modulo entero no sirve.
     */
    public function test_se_añade_una_seccion_con_un_campo_y_queda_guardado(): void
    {
        $plantilla = $this->plantilla();

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => null, 'fields' => [
                    $this->campo(['code' => 'actividad', 'is_required' => true]),
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $plantilla->refresh();

        $this->assertSame(1, $plantilla->sections()->count());

        $campo = $plantilla->sections()->first()->fields()->first();
        $this->assertSame('actividad', $campo->code);
        $this->assertSame('text', $campo->field_type);
        $this->assertTrue($campo->is_required);
    }

    /**
     * El orden de la pantalla es el que se guarda en `position`, en las dos
     * tablas. Es lo unico que distingue un AST bien montado de una lista suelta
     * de preguntas, y se pierde en cuanto alguien renumera a mano.
     */
    public function test_el_orden_de_la_pantalla_es_el_que_se_guarda(): void
    {
        $plantilla = $this->plantilla();

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => null, 'fields' => [
                    $this->campo(['code' => 'primero']),
                    $this->campo(['code' => 'segundo']),
                ]],
                ['id' => null, 'fields' => [$this->campo(['code' => 'tercero'])]],
            ]));

        $plantilla->refresh();
        $secciones = $plantilla->sections()->get();

        $this->assertSame([1, 2], $secciones->pluck('position')->all());
        $this->assertSame(
            ['primero', 'segundo'],
            $secciones->first()->fields()->pluck('code')->all(),
        );

        // Y ahora al reves: la seccion de abajo sube y los dos campos se cruzan.
        $s1 = $secciones->first();
        $s2 = $secciones->last();
        $c1 = $s1->fields()->where('code', 'primero')->first();
        $c2 = $s1->fields()->where('code', 'segundo')->first();

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => $s2->id, 'fields' => [
                    $this->campo(['id' => $s2->fields()->first()->id, 'code' => 'tercero']),
                ]],
                ['id' => $s1->id, 'fields' => [
                    $this->campo(['id' => $c2->id, 'code' => 'segundo']),
                    $this->campo(['id' => $c1->id, 'code' => 'primero']),
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $s2->fresh()->position);
        $this->assertSame(2, $s1->fresh()->position);
        $this->assertSame(['segundo', 'primero'], $s1->fresh()->fields()->pluck('code')->all());
    }

    /**
     * Intercambiar los codigos de dos campos de la misma seccion.
     *
     * `form_fields_code_unique` es (seccion, codigo) y salta en el acto, no al
     * cerrar la transaccion: escribir el primero ya choca con el segundo. Es un
     * gesto normal de quien reordena y reventaba el guardado entero.
     */
    public function test_dos_campos_pueden_intercambiar_su_codigo(): void
    {
        $plantilla = $this->plantilla();
        $seccion = $plantilla->sections()->create(['position' => 1]);
        $a = $seccion->fields()->create(['code' => 'aaa', 'field_type' => 'text', 'position' => 1]);
        $b = $seccion->fields()->create(['code' => 'bbb', 'field_type' => 'text', 'position' => 2]);

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => $seccion->id, 'fields' => [
                    $this->campo(['id' => $a->id, 'code' => 'bbb']),
                    $this->campo(['id' => $b->id, 'code' => 'aaa']),
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('bbb', $a->fresh()->code);
        $this->assertSame('aaa', $b->fresh()->code);
    }

    /** Lo que no viene en el arbol se va: quitar un campo y quitar una seccion. */
    public function test_lo_que_se_quita_de_la_pantalla_se_borra(): void
    {
        $plantilla = $this->plantilla();
        $seccion = $plantilla->sections()->create(['position' => 1]);
        $queda = $seccion->fields()->create(['code' => 'queda', 'field_type' => 'text', 'position' => 1]);
        $sobra = $seccion->fields()->create(['code' => 'sobra', 'field_type' => 'text', 'position' => 2]);
        $seccionVacia = $plantilla->sections()->create(['position' => 2]);

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => $seccion->id, 'fields' => [$this->campo(['id' => $queda->id, 'code' => 'queda'])]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($queda->fresh());
        $this->assertNull(FormField::find($sobra->id));
        $this->assertSame(1, $plantilla->fresh()->sections()->count());
        $this->assertNull(\App\Models\FormSection::find($seccionVacia->id));
    }

    // ── La configuracion, que depende del tipo ───────────────────────────────

    /** Las opciones de un desplegable llegan a `config` y se guardan. */
    public function test_la_configuracion_del_tipo_se_guarda(): void
    {
        $plantilla = $this->plantilla();

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => null, 'fields' => [
                    $this->campo([
                        'code' => 'condicion', 'field_type' => 'select',
                        'label_es' => 'Condición del equipo',
                        'config' => ['options' => ['Bueno', 'Regular', 'Malo']],
                    ]),
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $campo = $plantilla->fresh()->sections()->first()->fields()->first();

        $this->assertSame(['Bueno', 'Regular', 'Malo'], $campo->config['options']);
    }

    /**
     * Un `select` sin opciones no se guarda.
     *
     * En la tablet sale como un desplegable vacio: el trabajador no puede
     * responder y no hay forma de que sepa por que. La lista de lo que exige
     * cada tipo la tiene `FormTemplateBuilder`, que es quien la conoce.
     */
    public function test_un_desplegable_sin_opciones_no_pasa(): void
    {
        $plantilla = $this->plantilla();

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => null, 'fields' => [
                    $this->campo(['code' => 'condicion', 'field_type' => 'select', 'config' => []]),
                ]],
            ]))
            ->assertSessionHasErrors('sections.0.fields.0.config');

        $this->assertSame(0, $plantilla->fresh()->sections()->count());
    }

    /** Un tipo que no existe tampoco: la lista buena es `FormField::TIPOS`. */
    public function test_un_tipo_inventado_no_pasa(): void
    {
        $plantilla = $this->plantilla();

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => null, 'fields' => [$this->campo(['field_type' => 'holograma'])]],
            ]))
            ->assertSessionHasErrors('sections.0.fields.0.field_type');
    }

    /** Y el codigo repetido dentro de la misma seccion: es la clave de la respuesta. */
    public function test_no_se_repite_un_codigo_dentro_de_la_seccion(): void
    {
        $plantilla = $this->plantilla();

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => null, 'fields' => [
                    $this->campo(['code' => 'repetido']),
                    $this->campo(['code' => 'repetido']),
                ]],
            ]))
            ->assertSessionHasErrors('sections.0.fields.1.code');
    }

    /**
     * La pantalla recibe la lista de tipos DEL SERVIDOR.
     *
     * Se estan portando formatos de la v1 y aparecen tipos nuevos en
     * `FormField::TIPOS`. Con la lista copiada a mano en el Vue, un tipo nuevo
     * existiria en la base y no en la pantalla, y nadie se enteraria.
     */
    public function test_la_pantalla_recibe_los_tipos_del_servidor(): void
    {
        $plantilla = $this->plantilla();

        $this->actingAs($this->admin())
            ->get(route('business_management.form_templates.structure', $plantilla->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('FormTemplates/Structure')
                ->where('fieldTypes', fn ($tipos) => collect($tipos)->pluck('value')->all() === FormField::TIPOS)
                // Y cada tipo dice que se le puede configurar: sin esto el
                // editor no sabria que a un `select` hay que pedirle opciones.
                ->where('fieldTypes', fn ($tipos) => collect($tipos)
                    ->firstWhere('value', 'select')['config'] !== []));
    }

    // ── Los nombres, que son columnas ────────────────────────────────────────

    /**
     * El titulo de la seccion y la etiqueta del campo se guardan en sus
     * columnas, en los dos idiomas.
     *
     * Antes de que existieran, una seccion era una tarjeta sin titulo y la
     * etiqueta salia de humanizar el codigo — legible de chiripa, porque los
     * codigos se escribieron en castellano, y nada en ingles. El editor las
     * escribe: si no, un formato editado desde la pantalla nace mudo.
     */
    public function test_los_nombres_de_seccion_y_campo_se_guardan_en_sus_columnas(): void
    {
        $plantilla = $this->plantilla();

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                [
                    'id' => null,
                    'name_es' => 'Trabajos a realizar',
                    'name_en' => 'Work to be done',
                    'fields' => [
                        $this->campo([
                            'code' => 'actividad',
                            'label_es' => 'Actividad realizada',
                            'label_en' => 'Task performed',
                        ]),
                    ],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $seccion = $plantilla->fresh()->sections()->first();
        $this->assertSame('Trabajos a realizar', $seccion->name_es);
        $this->assertSame('Work to be done', $seccion->name_en);

        $campo = $seccion->fields()->first();
        $this->assertSame('Actividad realizada', $campo->label_es);
        $this->assertSame('Task performed', $campo->label_en);

        // Y el accesor los lee segun el idioma en curso, que es lo que pinta la
        // pantalla de llenado.
        app()->setLocale('es');
        $this->assertSame('Actividad realizada', $campo->fresh()->label);
        app()->setLocale('en');
        $this->assertSame('Task performed', $campo->fresh()->label);
        app()->setLocale('es');
    }

    /** Un titulo en blanco es NULL, no una cadena vacia: «sin titulo» es valido. */
    public function test_una_seccion_sin_titulo_se_guarda_como_nula(): void
    {
        $plantilla = $this->plantilla();

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => null, 'name_es' => '   ', 'name_en' => '', 'fields' => [$this->campo()]],
            ]))
            ->assertSessionHasNoErrors();

        $seccion = $plantilla->fresh()->sections()->first();
        $this->assertNull($seccion->name_es);
        $this->assertNull($seccion->name_en);
        $this->assertSame('', $seccion->label);
    }

    /**
     * La etiqueta sale de `config` y entra en su columna.
     *
     * `config.label` fue el sitio donde vivio la etiqueta mientras el motor no
     * tenia columna. Un campo de aquella epoca no puede perderla al abrir y
     * guardar desde el editor: se rescata a `label_es` y se limpia el JSON, que
     * dos copias del mismo texto acaban divergiendo.
     */
    public function test_la_etiqueta_que_estaba_en_config_se_muda_a_su_columna(): void
    {
        $plantilla = $this->plantilla();
        $seccion = $plantilla->sections()->create(['position' => 1]);
        $viejo = $seccion->fields()->create([
            'code' => 'actividad', 'field_type' => 'text', 'position' => 1,
            'config' => ['label' => 'Actividad realizada'],
        ]);

        // La pantalla recibe la etiqueta ya rellena aunque la columna este vacia.
        $this->actingAs($this->admin())
            ->get(route('business_management.form_templates.structure', $plantilla->slug))
            ->assertInertia(fn ($page) => $page->where(
                'sections.0.fields.0.label_es', 'Actividad realizada',
            ));

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => $seccion->id, 'fields' => [
                    $this->campo(['id' => $viejo->id, 'code' => 'actividad', 'label_es' => 'Actividad realizada']),
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $viejo->refresh();
        $this->assertSame('Actividad realizada', $viejo->label_es);
        $this->assertArrayNotHasKey('label', $viejo->config ?? []);
    }

    /** El bloque de configuracion ya no ofrece `label`: es una columna. */
    public function test_la_etiqueta_no_se_ofrece_como_configuracion_del_tipo(): void
    {
        $plantilla = $this->plantilla();

        $this->actingAs($this->admin())
            ->get(route('business_management.form_templates.structure', $plantilla->slug))
            ->assertInertia(fn ($page) => $page->where('fieldTypes', fn ($tipos) => collect($tipos)
                ->pluck('config')
                ->flatten(1)
                ->pluck('key')
                ->doesntContain('label')));
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    /** Sin `form_templates.edit` no se entra al editor ni se guarda. */
    public function test_sin_permiso_de_edicion_no_se_toca_la_estructura(): void
    {
        $plantilla = $this->plantilla();

        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->get(route('business_management.form_templates.structure', $plantilla->slug)));

        // Y el PUT tampoco, que esconder el boton no protege nada.
        $this->assertProhibido($this->put(
            route('business_management.form_templates.structure_update', $plantilla->slug),
            $this->arbol([['id' => null, 'fields' => [$this->campo()]]]),
        ));

        $this->assertSame(0, $plantilla->fresh()->sections()->count());
    }

    /** El candado (Lockable) cierra tambien esta puerta. */
    public function test_una_plantilla_bloqueada_no_cambia_de_estructura(): void
    {
        $plantilla = $this->plantilla();
        $plantilla->lock($this->makeSuper());

        $this->actingAs($this->admin());

        $this->assertProhibido($this->get(route('business_management.form_templates.structure', $plantilla->slug)));
        $this->assertProhibido($this->put(
            route('business_management.form_templates.structure_update', $plantilla->slug),
            $this->arbol([['id' => null, 'fields' => [$this->campo()]]]),
        ));
    }

    // ── Lo que ya es evidencia ───────────────────────────────────────────────

    private function unPlan(): WorkPlan
    {
        $empresa = Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
        $tipo = WorkType::firstOrCreate(['code' => 'MTTO'], $this->base());
        $sede = WorkLocation::firstOrCreate(['name' => 'Lurín'], $this->base());

        return WorkPlan::create($this->base() + [
            'company_id'       => $empresa->id,
            'work_type_id'     => $tipo->id,
            'work_location_id' => $sede->id,
            'user_id'          => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'code'             => 'PE26-0808-0001',
            'description'      => 'Mantenimiento',
            'date_start'       => '2026-08-08',
        ]);
    }

    /** Le cuelga una entrega, con o sin papelera. */
    private function conEntrega(FormTemplate $plantilla, bool $enPapelera = false): void
    {
        DB::table('form_submissions')->insert([
            'slug' => Str::random(22), 'form_template_id' => $plantilla->id,
            'work_plan_id' => $this->unPlan()->id,
            'template_version' => $plantilla->version, 'status' => 'confirmed',
            'deleted_at' => $enPapelera ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Un documento publicado no se reestructura. Es la regla que ya aplicaba
     * `FormTemplateBuilder::soloBorrador()` y que la pantalla tenia que
     * respetar: lo firmado se firmo con estos campos.
     */
    public function test_un_documento_publicado_no_se_reestructura(): void
    {
        $plantilla = $this->plantilla('AUD Publicado', 'aud_publicado', 'published');

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => null, 'fields' => [$this->campo()]],
            ]))
            ->assertSessionHas('error');

        $this->assertSame(0, $plantilla->fresh()->sections()->count());
    }

    /**
     * Y uno que ya se lleno tampoco, aunque este en borrador.
     *
     * Es el agujero que dejaba mirar solo el estado: «Despublicar» devuelve el
     * documento a borrador y no borra las entregas. Bastaba despublicar, quitar
     * un campo y volver a publicar — y las respuestas de ese campo se las lleva
     * la clave ajena en cascada, en silencio.
     */
    public function test_un_documento_ya_llenado_no_se_reestructura_aunque_este_en_borrador(): void
    {
        $plantilla = $this->plantilla('AUD Llenado', 'aud_llenado');
        $this->conEntrega($plantilla);

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => null, 'fields' => [$this->campo()]],
            ]))
            ->assertSessionHas('error');

        $this->assertSame(0, $plantilla->fresh()->sections()->count());
    }

    /** Ni una entrega en papelera: un borrado logico no desfirma nada. */
    public function test_una_entrega_en_papelera_sigue_protegiendo_la_estructura(): void
    {
        $plantilla = $this->plantilla('AUD Papelera', 'aud_papelera');
        $this->conEntrega($plantilla, enPapelera: true);

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $plantilla->slug), $this->arbol([
                ['id' => null, 'fields' => [$this->campo()]],
            ]))
            ->assertSessionHas('error');

        $this->assertSame(0, $plantilla->fresh()->sections()->count());
    }

    /** La pantalla lo dice, para poder explicarlo en vez de fallar al guardar. */
    public function test_la_pantalla_avisa_de_por_que_no_se_puede_editar(): void
    {
        $plantilla = $this->plantilla('AUD Avisa', 'aud_avisa');
        $this->conEntrega($plantilla);

        $this->actingAs($this->admin())
            ->get(route('business_management.form_templates.structure', $plantilla->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('editable', false)
                ->where('lockedReason', 'submissions')
                ->where('submissions', 1));
    }

    /**
     * La salida: una version nueva. Se copia la estructura en un borrador con la
     * version siguiente y el original —y lo que se lleno con el— no se toca.
     */
    public function test_la_version_nueva_copia_la_estructura_y_deja_intacto_el_original(): void
    {
        $plantilla = $this->plantilla('AUD Version', 'aud_version', 'published');
        $seccion = $plantilla->sections()->create(['position' => 1]);
        $seccion->fields()->create([
            'code' => 'actividad', 'field_type' => 'text', 'is_required' => true, 'position' => 1,
        ]);
        $this->conEntrega($plantilla);

        $this->actingAs($this->admin())
            ->post(route('business_management.form_templates.new_version', $plantilla->slug))
            ->assertRedirect();

        $nueva = FormTemplate::where('code', 'aud_version')->where('version', 2)->first();

        $this->assertNotNull($nueva, 'No se creo la version 2.');
        $this->assertSame('draft', $nueva->status);
        $this->assertSame(['actividad'], $nueva->sections()->first()->fields()->pluck('code')->all());

        // El original sigue publicado, con su version y su campo.
        $plantilla->refresh();
        $this->assertSame('published', $plantilla->status);
        $this->assertSame(1, $plantilla->version);
        $this->assertSame(1, $plantilla->fields()->count());

        // Y la copia si se puede reestructurar: es lo que se estaba pidiendo.
        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.structure_update', $nueva->slug), $this->arbol([
                ['id' => $nueva->sections()->first()->id, 'fields' => [
                    $this->campo(['code' => 'actividad']),
                    $this->campo(['code' => 'observacion', 'field_type' => 'textarea']),
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $nueva->fresh()->fields()->count());
        $this->assertSame(1, $plantilla->fresh()->fields()->count());
    }
}
