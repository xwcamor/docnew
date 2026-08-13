<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\FormTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dar de alta un formato desde la pantalla.
 *
 * El modulo no tenia ni una prueba, y por eso nadie vio que **crear un formato
 * reventaba**: `form_templates.country_id` es NOT NULL y ni el formulario, ni
 * las reglas, ni el servicio lo ponian. Salia un 23502 de Postgres en la cara
 * del usuario.
 *
 * Viene de que este modulo se genero clonando `Brand`, que no tiene pais.
 */
class FormTemplateCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LaravelLocalizationRedirectFilter::class, LocaleSessionRedirect::class]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['view', 'show', 'create', 'edit', 'delete'] as $a) {
            Permission::firstOrCreate(['name' => "form_templates.{$a}", 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo(Permission::all());

        return $u;
    }

    /** El super: es el unico que ve la papelera. */
    private function super(): User
    {
        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 's']);
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('super');
        $u->givePermissionTo(Permission::all());

        return $u;
    }

    /** Un documento cualquiera, ya guardado. */
    private function documento(array $extra = []): FormTemplate
    {
        return FormTemplate::create(array_merge([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1,
            'code' => 'AST', 'name' => 'Analisis de Seguridad', 'kind' => FormTemplate::STRUCTURED,
            'status' => 'draft', 'version' => 1,
        ], $extra));
    }

    /**
     * Un formato nuevo se guarda. Sin más.
     *
     * Esto es exactamente lo que reventaba: el insert salía sin `country_id` y
     * Postgres lo rechazaba con un 23502 que llegaba entero a la pantalla.
     */
    public function test_un_formato_nuevo_se_da_de_alta(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.form_templates.store'), [
            'country_id' => 1, 'name' => 'Analisis de Seguridad', 'code' => 'AST',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('form_templates', [
            'name' => 'Analisis de Seguridad', 'code' => 'AST', 'country_id' => 1, 'tenant_id' => 1,
        ]);
    }

    /**
     * Y se aterriza EN el documento, no en el listado.
     *
     * Guardar la cabecera no termina un documento: le faltan las secciones y
     * los campos, que es donde de verdad se define y lo que se hace en su
     * ficha. Devolver al indice obligaba a buscarlo entre los demas para poder
     * seguir con el paso siguiente.
     */
    public function test_dar_de_alta_un_formato_lleva_a_su_ficha(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.form_templates.store'), [
            'country_id' => 1, 'name' => 'Permiso de trabajo', 'code' => 'PDT',
        ])->assertRedirect(route(
            'business_management.form_templates.show',
            FormTemplate::where('code', 'PDT')->firstOrFail()->slug,
        ));
    }

    /** Y sin país no se guarda a medias: se dice qué falta. */
    public function test_sin_pais_no_se_guarda(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.form_templates.store'), [
            'name' => 'Sin pais', 'code' => 'XXX',
        ])->assertSessionHasErrors('country_id');

        $this->assertDatabaseMissing('form_templates', ['name' => 'Sin pais']);
    }

    /** El formulario llega con el país del usuario ya puesto. */
    public function test_el_formulario_propone_el_pais_del_usuario(): void
    {
        $this->actingAs($this->admin())
            ->get(route('business_management.form_templates.create'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('defaultCountryId', 1)
                ->has('countryOptions', 1));
    }

    /**
     * Un formato creado se puede publicar, y hasta que no lo esté ningún plan
     * lo ve.
     *
     * Éste era el motivo real de que «no se pudieran añadir documentos a un
     * plan»: un formato nace en `draft`, la ficha del plan sólo lista los
     * publicados y **no había ninguna acción para publicarlo** en toda la
     * aplicación. El único camino era `docufiz:migrate-formats`, o sea los
     * cuatro que trajo la v1 y nada más.
     */
    public function test_un_formato_nace_en_borrador_y_se_publica_desde_la_ficha(): void
    {
        $plantilla = FormTemplate::create([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1,
            'code' => 'HOJA', 'name' => 'Hoja suelta', 'kind' => FormTemplate::UPLOAD_ONLY,
            'status' => 'draft', 'version' => 1,
        ]);

        $this->actingAs($this->admin());

        $this->assertSame('draft', $plantilla->status);

        $this->post(route('business_management.form_templates.publish', $plantilla->slug))
            ->assertSessionHas('success');

        $this->assertSame('published', $plantilla->fresh()->status);
        $this->assertNotNull($plantilla->fresh()->published_at);

        // Y se puede volver atrás sin borrar nada.
        $this->post(route('business_management.form_templates.unpublish', $plantilla->slug))
            ->assertSessionHas('success');

        $this->assertSame('draft', $plantilla->fresh()->status);
    }

    /** Un formato con campos no se publica vacío: sería un papel en blanco. */
    public function test_un_formato_estructurado_vacio_no_se_publica(): void
    {
        $plantilla = FormTemplate::create([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1,
            'code' => 'AST', 'name' => 'Analisis', 'kind' => FormTemplate::STRUCTURED,
            'status' => 'draft', 'version' => 1,
        ]);

        $this->actingAs($this->admin());

        $this->post(route('business_management.form_templates.publish', $plantilla->slug))
            ->assertSessionHas('error');

        $this->assertSame('draft', $plantilla->fresh()->status);
    }

    /** Las pantallas del módulo abren, que es lo que nadie había comprobado. */
    public function test_las_pantallas_del_modulo_abren(): void
    {
        $plantilla = $this->documento();

        $this->actingAs($this->admin());

        foreach (['index', 'create', 'edit_all'] as $pantalla) {
            $this->get(route("business_management.form_templates.{$pantalla}"))->assertOk();
        }
        foreach (['show', 'edit', 'delete'] as $pantalla) {
            $this->get(route("business_management.form_templates.{$pantalla}", $plantilla->slug))->assertOk();
        }

        // La papelera es sólo del super.
        $this->actingAs($this->super())
            ->get(route('business_management.form_templates.trash'))->assertOk();
    }

    /**
     * Duplicar reventaba igual que crear, y por lo mismo.
     *
     * El servicio copiaba `is_active` y `version` y se dejaba fuera
     * `country_id`, que es NOT NULL: el 23502 de Postgres salía tal cual al
     * pulsar el botón de copiar de cualquier fila del listado. Es el mismo
     * agujero del alta, en el otro camino, y quedaba abierto.
     */
    public function test_duplicar_un_documento_no_revienta_y_conserva_el_pais(): void
    {
        $plantilla = $this->documento(['kind' => FormTemplate::UPLOAD_ONLY, 'name' => 'Hoja suelta']);

        $this->actingAs($this->admin());

        $this->post(route('business_management.form_templates.duplicate', $plantilla->slug))
            ->assertRedirect()
            ->assertSessionHas('success');

        $copia = FormTemplate::where('id', '!=', $plantilla->id)->firstOrFail();

        $this->assertSame(1, $copia->country_id);
        // Y la copia se llena igual que el original: un «sólo foto del papel»
        // que saliera «con campos» ya no se podría publicar nunca.
        $this->assertSame(FormTemplate::UPLOAD_ONLY, $copia->kind);
        // Nace en borrador: es un documento nuevo, no una versión.
        $this->assertSame('draft', $copia->status);
        $this->assertSame(1, (int) $copia->version);
    }

    /**
     * Cómo se llena el documento se elige desde la pantalla.
     *
     * La columna `kind` existía y el formulario no la ofrecía, así que todo
     * nacía «con campos» — y un documento con campos y sin ninguno no se puede
     * publicar. Como todavía no hay pantalla para definir campos, eso dejaba el
     * módulo sin ninguna salida: nada de lo creado aquí llegaba a un plan.
     */
    public function test_se_elige_como_se_llena_y_asi_se_puede_publicar(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.form_templates.store'), [
            'country_id' => 1, 'name' => 'Hoja suelta', 'code' => 'HOJA',
            'kind' => FormTemplate::UPLOAD_ONLY,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $plantilla = FormTemplate::where('code', 'HOJA')->firstOrFail();
        $this->assertSame(FormTemplate::UPLOAD_ONLY, $plantilla->kind);

        // Y con eso ya se publica: el camino completo desde la pantalla.
        $this->post(route('business_management.form_templates.publish', $plantilla->slug))
            ->assertSessionHas('success');

        $this->assertSame('published', $plantilla->fresh()->status);
    }

    /** Un `kind` inventado no entra. */
    public function test_como_se_llena_solo_acepta_los_tres_valores(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.form_templates.store'), [
            'country_id' => 1, 'name' => 'Raro', 'code' => 'RARO', 'kind' => 'lo_que_sea',
        ])->assertSessionHasErrors('kind');

        $this->assertDatabaseMissing('form_templates', ['name' => 'Raro']);
    }

    /** Editar guarda de verdad: se comprueba la fila, no el 302. */
    public function test_editar_guarda_la_fila(): void
    {
        $plantilla = $this->documento();

        $this->actingAs($this->admin());

        $this->put(route('business_management.form_templates.update', $plantilla->slug), [
            'country_id' => 1,
            'name'       => 'AST (Análisis de Seguridad en el Trabajo)',
            'code'       => 'AST',
            'kind'       => FormTemplate::HYBRID,
            'is_active'  => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $plantilla->refresh();
        $this->assertSame('AST (Análisis de Seguridad en el Trabajo)', $plantilla->name);
        $this->assertSame(FormTemplate::HYBRID, $plantilla->kind);
    }

    /**
     * Borrar el código y guardar tampoco puede reventar.
     *
     * `form_templates.code` es NOT NULL. El campo se manda vacío, Laravel lo
     * convierte en `null` y la pantalla de editar —que no derivaba el código del
     * nombre como sí hace la de alta— lo escribía tal cual: otro 23502 de
     * Postgres en la cara del usuario, esta vez al editar.
     */
    public function test_editar_sin_codigo_deriva_uno_y_no_revienta(): void
    {
        $plantilla = $this->documento();

        $this->actingAs($this->admin());

        $this->put(route('business_management.form_templates.update', $plantilla->slug), [
            'country_id' => 1, 'name' => 'Pare Tome 5', 'code' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $plantilla->refresh();
        $this->assertSame('pare_tome_5', $plantilla->code);
    }

    /**
     * Y el código derivado cabe en la columna.
     *
     * Los nombres de verdad son largos —«AST (Análisis de Seguridad en el
     * Trabajo)» pasa de 40— y el código se deriva del nombre: sin recortar,
     * quien deja el código en blanco recibía un error de longitud sobre un campo
     * que ni había tocado.
     */
    public function test_un_nombre_largo_sin_codigo_no_da_error_de_longitud(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.form_templates.store'), [
            'country_id' => 1,
            'name'       => 'AST (Analisis de Seguridad en el Trabajo) para trabajos en altura',
            'kind'       => FormTemplate::UPLOAD_ONLY,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $plantilla = FormTemplate::firstOrFail();
        $this->assertNotNull($plantilla->code);
        $this->assertLessThanOrEqual(40, mb_strlen($plantilla->code));
    }

    /**
     * Un documento publicado no cambia de forma de llenarse.
     *
     * Pasar de «sólo foto del papel» a «con campos» con entregas ya hechas
     * cambiaría qué se le exige a algo que ya está cerrado y firmado.
     */
    public function test_publicado_no_cambia_como_se_llena(): void
    {
        $plantilla = $this->documento([
            'kind' => FormTemplate::UPLOAD_ONLY, 'status' => 'published', 'published_at' => now(),
        ]);

        $this->actingAs($this->admin());

        $this->put(route('business_management.form_templates.update', $plantilla->slug), [
            'country_id' => 1, 'name' => $plantilla->name, 'code' => 'AST',
            'kind' => FormTemplate::STRUCTURED,
        ])->assertSessionHasErrors('kind');

        $this->assertSame(FormTemplate::UPLOAD_ONLY, $plantilla->fresh()->kind);
    }

    /**
     * El listado dice cuáles están publicados.
     *
     * Enseñaba activo/inactivo, que aquí no es lo que importa: lo que decide si
     * un plan puede usar un documento es la publicación, y no se veía. Se podía
     * tener el catálogo entero en borrador sin enterarse.
     */
    public function test_el_listado_trae_la_publicacion_de_cada_documento(): void
    {
        $this->documento(['name' => 'En borrador', 'code' => 'B1']);
        $this->documento(['name' => 'Ya publicado', 'code' => 'P1', 'status' => 'published']);

        $this->actingAs($this->admin())
            ->get(route('business_management.form_templates.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('form_templates.data', 2)
                ->where('form_templates.data.0.status', 'published')
                ->where('form_templates.data.1.status', 'draft'));
    }

    /** Y se puede filtrar por publicación, que es la pregunta real del listado. */
    public function test_se_filtra_por_publicacion(): void
    {
        $this->documento(['name' => 'En borrador', 'code' => 'B1']);
        $this->documento(['name' => 'Ya publicado', 'code' => 'P1', 'status' => 'published']);

        $this->actingAs($this->admin())
            ->get(route('business_management.form_templates.index', ['status' => 'published']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('form_templates.data', 1)
                ->where('form_templates.data.0.name', 'Ya publicado'));
    }

    /**
     * La ficha trae el país y la versión.
     *
     * La versión salía siempre vacía porque leía `sort_order`, columna que no
     * existe en esta tabla; y el país se pedía al crear y luego no aparecía en
     * ningún sitio.
     */
    public function test_la_ficha_trae_pais_version_y_cuantos_campos_tiene(): void
    {
        $plantilla = $this->documento(['version' => 3]);

        $this->actingAs($this->admin())
            ->get(route('business_management.form_templates.show', $plantilla->slug))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('formTemplate.version', 3)
                ->where('formTemplate.country_id', 1)
                ->where('formTemplate.country_name', 'Peru')
                ->where('formTemplate.kind', FormTemplate::STRUCTURED)
                // Con esto la ficha sabe deshabilitar «Publicar» y decir por qué,
                // en vez de dejar que falle al pulsarlo.
                ->where('formTemplate.fields_count', 0));
    }
}
