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
