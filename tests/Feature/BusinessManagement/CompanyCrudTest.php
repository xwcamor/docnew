<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\Person;
use App\Models\PersonCompanyLink;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkArea;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;

/**
 * Las empresas: las contratistas que ponen la gente que firma en obra.
 *
 * No es un catalogo mas. De una empresa cuelgan las personas y los planes, y un
 * plan firmado es el documento que un inspector puede pedir: borrar la empresa
 * que lo ejecuto deja el papel sin autor (docs/UI.md §6).
 */
class CompanyCrudTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'companies';
    }

    private function empresa(string $nombre = 'HITACHI', string $ruc = '20512345678'): Company
    {
        return Company::create($this->base() + [
            'name'          => $nombre,
            'complete_name' => $nombre . ' Energy Perú S.A.',
            'num_doc'       => $ruc,
        ]);
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->empresa();
    }

    /** Un plan ejecutado por esa empresa: es lo que impide borrarla. */
    private function planDe(Company $empresa): WorkPlan
    {
        $tipo = WorkType::firstOrCreate(['code' => 'MTTO'], $this->base());
        $sede = WorkLocation::firstOrCreate(['name' => 'Lurín'], $this->base());
        $area = WorkArea::firstOrCreate(['name' => 'Bobinado'], $this->base());

        return WorkPlan::create($this->base() + [
            'company_id'       => $empresa->id,
            'work_type_id'     => $tipo->id,
            'work_location_id' => $sede->id,
            'work_area_id'     => $area->id,
            'user_id'          => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'code'             => 'PE26-0808-0001',
            'description'      => 'Mantenimiento',
            'date_start'       => '2026-08-08',
        ]);
    }

    /** Una persona vinculada a la empresa. */
    private function personaDe(Company $empresa): PersonCompanyLink
    {
        $cargo   = Position::firstOrCreate(['code' => 'Técnico'], $this->base());
        $persona = Person::create($this->base() + [
            'doc_type' => 'DNI', 'num_doc' => '44556677',
            'name' => 'Juan', 'lastname' => 'Pérez', 'is_active' => true,
        ]);

        return PersonCompanyLink::create([
            'person_id' => $persona->id, 'company_id' => $empresa->id,
            'position_id' => $cargo->id, 'is_active' => true,
        ]);
    }

    // ── Alta ─────────────────────────────────────────────────────────────────

    public function test_una_empresa_nueva_se_da_de_alta_desde_la_pantalla(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.companies.store'), [
            'country_id'    => 1,
            'name'          => 'LIMTEK',
            'complete_name' => 'Limtek Servicios Integrales S.A.',
            'num_doc'       => '20487654321',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', [
            'name'          => 'LIMTEK',
            'complete_name' => 'Limtek Servicios Integrales S.A.',
            'num_doc'       => '20487654321',
            'tenant_id'     => 1,
            'country_id'    => 1,
        ]);
    }

    /** El RUC se teclea con guiones y espacios; se guarda limpio. */
    public function test_el_ruc_se_guarda_sin_espacios_ni_guiones(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.companies.store'), [
            'country_id'    => 1,
            'name'          => 'LIMTEK',
            'complete_name' => 'Limtek Servicios Integrales S.A.',
            'num_doc'       => '20-4876 54321',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', ['num_doc' => '20487654321']);
    }

    public function test_no_puede_haber_dos_empresas_con_el_mismo_ruc_en_un_pais(): void
    {
        $this->actingAs($this->admin());
        $this->empresa('HITACHI', '20512345678');

        $this->post(route('business_management.companies.store'), [
            'country_id'    => 1,
            'name'          => 'HITACHI ENERGY',
            'complete_name' => 'Otra razón social',
            'num_doc'       => '20512345678',
        ])->assertSessionHasErrors('num_doc');

        $this->assertSame(1, Company::where('num_doc', '20512345678')->count());
    }

    /**
     * La razon social es NOT NULL en la tabla: si el formulario la deja pasar
     * vacia, el alta revienta con un 23502 de Postgres en la cara del usuario.
     */
    public function test_sin_razon_social_lo_dice_en_su_campo_y_no_revienta(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.companies.store'), [
            'country_id' => 1, 'name' => 'LIMTEK', 'num_doc' => '20487654321',
        ])->assertSessionHasErrors('complete_name');

        $this->assertDatabaseMissing('companies', ['name' => 'LIMTEK']);
    }

    public function test_sin_ruc_lo_dice_en_su_campo(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.companies.store'), [
            'country_id' => 1, 'name' => 'LIMTEK', 'complete_name' => 'Limtek S.A.',
        ])->assertSessionHasErrors('num_doc');
    }

    // ── Edicion ──────────────────────────────────────────────────────────────

    public function test_se_le_cambia_la_razon_social_desde_la_pantalla(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();

        $this->put(route('business_management.companies.update', $empresa->slug), [
            'country_id'    => 1,
            'name'          => 'HITACHI',
            'complete_name' => 'Hitachi Energy Perú S.A.C.',
            'num_doc'       => '20512345678',
            'is_active'     => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', [
            'id' => $empresa->id, 'complete_name' => 'Hitachi Energy Perú S.A.C.',
        ]);
    }

    /** Editar sin tocar el RUC no puede chocar consigo misma. */
    public function test_al_editar_su_propio_ruc_no_cuenta_como_duplicado(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();

        $this->put(route('business_management.companies.update', $empresa->slug), [
            'country_id'    => 1,
            'name'          => 'HITACHI PERU',
            'complete_name' => 'Hitachi Energy Perú S.A.',
            'num_doc'       => '20512345678',
            'is_active'     => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', ['id' => $empresa->id, 'name' => 'HITACHI PERU']);
    }

    // ── Borrado ──────────────────────────────────────────────────────────────

    public function test_una_empresa_sin_gente_ni_planes_se_borra_y_queda_en_papelera(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();

        $this->delete(route('business_management.companies.deleteSave', $empresa->slug), [
            'deleted_description' => 'se dio de alta por error',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSoftDeleted('companies', ['id' => $empresa->id]);
    }

    /**
     * Un plan firmado es la prueba de que se trabajo. Si la empresa que lo
     * ejecuto desaparece del listado, el plan queda sin contratista.
     */
    public function test_una_empresa_con_planes_no_se_puede_borrar(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();
        $this->planDe($empresa);

        $this->delete(route('business_management.companies.deleteSave', $empresa->slug), [
            'deleted_description' => 'ya no trabaja con nosotros',
        ])->assertSessionHas('error');

        $this->assertNotSoftDeleted('companies', ['id' => $empresa->id]);
    }

    public function test_una_empresa_con_gente_vinculada_tampoco_se_borra(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();
        $this->personaDe($empresa);

        $this->delete(route('business_management.companies.deleteSave', $empresa->slug), [
            'deleted_description' => 'ya no trabaja con nosotros',
        ])->assertSessionHas('error');

        $this->assertNotSoftDeleted('companies', ['id' => $empresa->id]);
    }

    /**
     * En masiva la que tiene planes se aparta, pero las demas se borran igual:
     * marcar veinte y que no se vaya ninguna porque una tiene un plan no le
     * sirve a nadie.
     */
    public function test_una_masiva_aparta_las_que_estan_en_uso_y_borra_el_resto(): void
    {
        $this->actingAs($this->admin());
        $conPlan = $this->empresa('HITACHI', '20512345678');
        $this->planDe($conPlan);
        $libre = $this->empresa('LIMTEK', '20487654321');

        $this->post(route('business_management.companies.bulk_delete'), [
            'ids'                 => [$conPlan->id, $libre->id],
            'deleted_description' => 'limpieza del listado',
        ])->assertSessionHas('success');

        $this->assertNotSoftDeleted('companies', ['id' => $conPlan->id]);
        $this->assertSoftDeleted('companies', ['id' => $libre->id]);
    }

    /** La salida cuando no se puede borrar: desactivarla. El plan sigue en pie. */
    public function test_a_cambio_se_puede_desactivar_y_el_plan_sigue_en_pie(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();
        $plan    = $this->planDe($empresa);

        $this->put(route('business_management.companies.update', $empresa->slug), [
            'country_id'    => 1,
            'name'          => $empresa->name,
            'complete_name' => $empresa->complete_name,
            'num_doc'       => $empresa->num_doc,
            'is_active'     => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', ['id' => $empresa->id, 'is_active' => false]);
        $this->assertDatabaseHas('work_plans', ['id' => $plan->id, 'company_id' => $empresa->id]);
    }

    /** La pantalla de confirmacion avisa ANTES de pedir el motivo. */
    public function test_la_pantalla_de_borrado_avisa_de_lo_que_cuelga_de_la_empresa(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();
        $this->planDe($empresa);
        $this->personaDe($empresa);

        $this->get(route('business_management.companies.delete', $empresa->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Companies/Delete')
                ->where('dependents.work_plans.count', 1)
                ->where('dependents.people.count', 1));
    }

    // ── Listado y ficha ──────────────────────────────────────────────────────

    public function test_el_listado_dice_cuanta_gente_y_cuantos_planes_tiene_cada_empresa(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();
        $this->planDe($empresa);
        $this->personaDe($empresa);

        $this->get(route('business_management.companies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Companies/Index')
                ->where('companies.data.0.name', 'HITACHI')
                ->where('companies.data.0.people_count', 1)
                ->where('companies.data.0.work_plans_count', 1));
    }

    /**
     * En obra la empresa se pide por su RUC tanto como por su nombre. El
     * buscador de la barra mira las tres columnas: teclear el RUC tiene que
     * encontrarla.
     */
    public function test_el_buscador_encuentra_por_nombre_por_razon_social_y_por_ruc(): void
    {
        $this->actingAs($this->admin());
        $this->empresa('HITACHI', '20512345678');
        Company::create($this->base() + [
            'name' => 'LIMTEK', 'complete_name' => 'Limtek Servicios Integrales S.A.', 'num_doc' => '20487654321',
        ]);

        // Por el nombre corto, por un trozo de la razon social y por el RUC —
        // tecleado limpio y tecleado con guiones y espacios, como sale del papel.
        foreach (['HITACHI', 'Energy Perú', '20512345678', '20-5123 45678'] as $termino) {
            $this->get(route('business_management.companies.index', ['name' => [$termino]]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('companies.total', 1)
                    ->where('companies.data.0.name', 'HITACHI'));
        }
    }

    public function test_la_ficha_trae_el_pais_resuelto_y_no_solo_su_id(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();

        $this->get(route('business_management.companies.show', $empresa->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Companies/Show')
                ->where('company.country.name', 'Peru')
                ->where('company.num_doc', '20512345678'));
    }

    // ── Editar todo ──────────────────────────────────────────────────────────

    /**
     * «Editar todo» solo detecta repetidos dentro de la pagina que se ve. Si el
     * nombre choca con una empresa de otra pagina, la comprobacion tiene que
     * estar en el servidor: si no, el lote entero llega al indice unico y
     * revienta.
     */
    public function test_editar_todo_no_deja_ponerle_a_una_empresa_el_nombre_de_otra(): void
    {
        $this->actingAs($this->admin());
        $hitachi = $this->empresa('HITACHI', '20512345678');
        $limtek  = $this->empresa('LIMTEK', '20487654321');

        $this->post(route('business_management.companies.edit_all.update'), [
            'changes' => [['id' => $limtek->id, 'name' => 'hitachi']],
        ])->assertSessionHasErrors('changes.0.name');

        $this->assertDatabaseHas('companies', ['id' => $limtek->id, 'name' => 'LIMTEK']);
        $this->assertDatabaseHas('companies', ['id' => $hitachi->id, 'name' => 'HITACHI']);
    }

    public function test_editar_todo_guarda_el_nombre_y_el_estado(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();

        $this->post(route('business_management.companies.edit_all.update'), [
            'changes' => [['id' => $empresa->id, 'name' => 'HITACHI ENERGY', 'is_active' => false]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', [
            'id' => $empresa->id, 'name' => 'HITACHI ENERGY', 'is_active' => false,
        ]);
    }

    // ── Duplicar ─────────────────────────────────────────────────────────────

    /**
     * El clon nace con un RUC provisional: la tabla lo exige y es unico. Si el
     * duplicado copiara el RUC, el alta reventaria contra el indice.
     */
    public function test_al_duplicar_una_empresa_el_clon_no_hereda_el_ruc(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();

        $this->post(route('business_management.companies.duplicate', $empresa->slug))
            ->assertRedirect()->assertSessionHasNoErrors();

        $clon = Company::where('id', '!=', $empresa->id)->first();
        $this->assertNotNull($clon);
        $this->assertNotSame($empresa->num_doc, $clon->num_doc);
        $this->assertSame($empresa->complete_name, $clon->complete_name);
        $this->assertSame(1, (int) $clon->country_id);
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_sin_permiso_de_crear_no_se_crea(): void
    {
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->get(route('business_management.companies.create')));
        $this->assertProhibido($this->post(route('business_management.companies.store'), [
            'country_id' => 1, 'name' => 'LIMTEK', 'complete_name' => 'Limtek S.A.', 'num_doc' => '20487654321',
        ]));

        $this->assertDatabaseMissing('companies', ['name' => 'LIMTEK']);
    }

    public function test_sin_permiso_de_borrar_no_se_borra(): void
    {
        $empresa = $this->empresa();
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->delete(route('business_management.companies.deleteSave', $empresa->slug), [
            'deleted_description' => 'porque sí',
        ]));

        $this->assertNotSoftDeleted('companies', ['id' => $empresa->id]);
    }

    public function test_sin_permiso_de_ver_no_se_entra_al_listado(): void
    {
        $this->assertProhibido(
            $this->actingAs($this->sinPermisos())->get(route('business_management.companies.index'))
        );
    }

    public function test_la_papelera_es_solo_del_super(): void
    {
        $this->assertProhibido(
            $this->actingAs($this->admin())->get(route('business_management.companies.trash'))
        );
    }
}
