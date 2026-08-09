<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\DocumentType;
use App\Models\Person;
use Illuminate\Support\Str;

/**
 * Los tipos de documento, que hasta ahora vivian dentro del codigo.
 *
 * `StorePersonRequest` traia `Rule::in(['DNI', 'CE', 'PASAPORTE'])`, asi que
 * dar de alta a alguien con **PTP** —el permiso temporal de permanencia, que
 * en Peru llevan miles de venezolanos— pasaba por tocar PHP y desplegar.
 *
 * Ojo: en la v1 esta tabla NO existe. Lo unico que hay alli es
 * `settings.num_doc_minimum`, un numero por pais. Esto no reproduce nada de
 * alla; lo arregla.
 *
 * Las pruebas comunes de catalogo —pantallas, permisos, papelera, candado—
 * salen de CatalogTestCase. Aqui va lo que este modulo tiene de propio: que la
 * persona valide contra el catalogo y contra las longitudes.
 */
class DocumentTypeCrudTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'document_types';
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->tipo('DNI', 7, 8);
    }

    private function tipo(string $codigo, ?int $min = null, ?int $max = null): DocumentType
    {
        return DocumentType::create($this->base() + [
            'code' => $codigo, 'name' => $codigo . ' largo',
            'min_length' => $min, 'max_length' => $max, 'is_active' => true,
        ]);
    }

    public function test_un_tipo_nuevo_se_da_de_alta_con_sus_longitudes(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.document_types.store'), [
            'country_id' => 1, 'code' => 'PTP', 'name' => 'Permiso Temporal de Permanencia',
            'min_length' => 9, 'max_length' => 12,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('document_types', ['code' => 'PTP', 'min_length' => 9, 'max_length' => 12]);
    }

    /** El maximo por debajo del minimo no es una longitud: es un error de tecleo. */
    public function test_el_maximo_no_puede_ser_menor_que_el_minimo(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.document_types.store'), [
            'country_id' => 1, 'code' => 'PTP', 'min_length' => 12, 'max_length' => 9,
        ])->assertSessionHasErrors('max_length');
    }

    public function test_el_mismo_codigo_no_se_repite_en_el_mismo_pais(): void
    {
        $this->tipo('DNI');
        $this->actingAs($this->admin());

        $this->post(route('business_management.document_types.store'), [
            'country_id' => 1, 'code' => 'DNI',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, DocumentType::where('code', 'DNI')->count());
    }

    /**
     * «dni» y «DNI» son la misma sigla.
     *
     * Aqui pesa mas que en otros catalogos: `people.doc_type` guarda el TEXTO,
     * asi que una persona dada de alta como «dni» no la encuentra quien busca
     * por «DNI». Y el indice unico de la tabla no lo impide — con `deleted_at`
     * en nulo el motor considera cada tupla distinta.
     */
    public function test_la_sigla_no_se_repite_cambiando_mayusculas(): void
    {
        $this->tipo('DNI');
        $this->actingAs($this->admin());

        $this->post(route('business_management.document_types.store'), [
            'country_id' => 1, 'code' => 'dni',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, DocumentType::withoutGlobalScopes()->where('country_id', 1)->count());
    }

    /**
     * «Ya existe esa sigla» no puede referirse a una fila de otra empresa.
     *
     * `Rule::unique` consultaba la tabla cruda, sin el scope de
     * `BelongsToTenantOrGlobal`: el admin de la empresa B escribia «PTP», la
     * pantalla contestaba «ya existe» y esa fila era de la empresa A. No sale
     * en su listado, no la puede abrir, ni renombrar, ni borrar — un callejon
     * sin salida que ademas cuenta que la otra empresa la usa.
     */
    public function test_una_sigla_privada_de_otra_empresa_no_bloquea_el_alta(): void
    {
        \Illuminate\Support\Facades\DB::table('tenants')->insertOrIgnore([[
            'id' => 2, 'slug' => Str::random(22), 'name' => 'Empresa 2',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);

        // Un tipo PRIVADO de la empresa 2, que la empresa 1 no ve en ningun sitio.
        DocumentType::withoutGlobalScopes()->create([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 2,
            'code' => 'PTP', 'is_active' => true, 'created_by' => 1,
        ]);

        $this->actingAs($this->admin()); // empresa 1

        $this->post(route('business_management.document_types.store'), [
            'country_id' => 1, 'code' => 'PTP', 'name' => 'Permiso Temporal de Permanencia',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, DocumentType::withoutGlobalScopes()->where('code', 'PTP')->count());
    }

    /** Un tipo GLOBAL si bloquea: ese lo ve —y lo puede usar— todo el mundo. */
    public function test_una_sigla_global_si_bloquea_el_alta(): void
    {
        DocumentType::withoutGlobalScopes()->create([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => null,
            'code' => 'PASAPORTE', 'is_active' => true, 'created_by' => 1,
        ]);

        $this->actingAs($this->admin());

        $this->post(route('business_management.document_types.store'), [
            'country_id' => 1, 'code' => 'PASAPORTE',
        ])->assertSessionHasErrors('code');
    }

    // ── Los tres campos propios llegan a la pantalla ─────────────────────────

    /**
     * El nombre largo y las longitudes salen en el listado y en la ficha.
     *
     * El modulo se genero copiando Nacionalidades, que no tiene ninguno de los
     * tres: se guardaban bien y no se veian en ninguna parte.
     */
    public function test_el_nombre_largo_y_las_longitudes_llegan_al_listado_y_a_la_ficha(): void
    {
        $tipo = $this->tipo('CE', 9, 12);
        $tipo->update(['name' => 'Carné de Extranjería']);

        $this->actingAs($this->admin());

        $this->get(route('business_management.document_types.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('DocumentTypes/Index')
                ->where('document_types.data.0.code', 'CE')
                ->where('document_types.data.0.name', 'Carné de Extranjería')
                ->where('document_types.data.0.min_length', 9)
                ->where('document_types.data.0.max_length', 12));

        $this->get(route('business_management.document_types.show', $tipo->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('DocumentTypes/Show')
                ->where('documentType.name', 'Carné de Extranjería')
                ->where('documentType.min_length', 9)
                ->where('documentType.max_length', 12));
    }

    /** El buscador de la barra encuentra por nombre largo, no solo por sigla. */
    public function test_el_buscador_encuentra_por_el_nombre_largo(): void
    {
        $ce = $this->tipo('CE', 9, 12);
        $ce->update(['name' => 'Carné de Extranjería']);
        $this->tipo('DNI', 7, 8);

        $this->actingAs($this->admin());

        $this->get(route('business_management.document_types.index', ['name' => 'Extranj']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('document_types.total', 1)
                ->where('document_types.data.0.code', 'CE'));
    }

    /** Dejar el nombre largo en blanco guarda NULL, no una cadena vacia. */
    public function test_el_nombre_largo_en_blanco_se_guarda_como_nulo(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.document_types.store'), [
            'country_id' => 1, 'code' => 'PTP', 'name' => '   ',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(DocumentType::where('code', 'PTP')->first()->name);
    }

    // ── Lo que gana la persona ───────────────────────────────────────────────

    /**
     * Un tipo que está en el catálogo se puede usar; uno que no, no.
     *
     * Ésta es la prueba que justifica el módulo: antes «PTP» era un error de
     * validación por estar fuera de una lista escrita en PHP. Ahora basta con
     * darlo de alta.
     */
    public function test_la_persona_admite_cualquier_tipo_del_catalogo(): void
    {
        $this->tipo('PTP', 9, 12);
        $this->actingAs($this->admin());

        $this->post(route('business_management.people.store'), $this->datosDePersona([
            'doc_type' => 'PTP', 'num_doc' => '001234567',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('people', ['num_doc' => '001234567', 'doc_type' => 'PTP']);

        // Y uno que no está dado de alta se rechaza.
        $this->post(route('business_management.people.store'), $this->datosDePersona([
            'doc_type' => 'INVENTADO', 'num_doc' => '009999999',
        ]))->assertSessionHasErrors('doc_type');
    }

    /**
     * La longitud del catálogo se hace valer al dar de alta.
     *
     * Y sólo ahí. El buscador de la cuadrilla va por coincidencia exacta del
     * número, no por longitud, porque en el volcado hay dos peruanos con DNI de
     * 7 caracteres — por eso el DNI se siembra con mínimo 7 y no 8. Una regla
     * más estricta que la realidad deja gente fuera del sistema.
     */
    public function test_la_longitud_del_catalogo_se_hace_valer(): void
    {
        $this->tipo('CE', 9, 12);
        $this->actingAs($this->admin());

        $this->post(route('business_management.people.store'), $this->datosDePersona([
            'doc_type' => 'CE', 'num_doc' => '12345',
        ]))->assertSessionHasErrors('num_doc');

        $this->post(route('business_management.people.store'), $this->datosDePersona([
            'doc_type' => 'CE', 'num_doc' => '123456789',
        ]))->assertSessionHasNoErrors();
    }

    /** Sin longitudes declaradas no se exige ninguna: no todo documento la tiene. */
    public function test_un_tipo_sin_longitudes_no_exige_ninguna(): void
    {
        $this->tipo('PASAPORTE');
        $this->actingAs($this->admin());

        $this->post(route('business_management.people.store'), $this->datosDePersona([
            'doc_type' => 'PASAPORTE', 'num_doc' => 'X1',
        ]))->assertSessionHasNoErrors();
    }

    /** @param array<string, mixed> $extra */
    private function datosDePersona(array $extra): array
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        $empresa = \App\Models\Company::firstOrCreate(['num_doc' => '20100000001'],
            $base + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC', 'is_active' => true]);
        $cargo = \App\Models\Position::firstOrCreate(['code' => 'Tecnico'], $base + ['is_active' => true]);

        return $extra + [
            'name' => 'Ana', 'lastname' => 'Quispe', 'country_id' => 1,
            'company_id' => $empresa->id, 'position_id' => $cargo->id,
        ];
    }
}
