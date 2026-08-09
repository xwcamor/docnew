<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Person;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * No se da de baja a quien tiene planes, firmas o aprobaciones.
 *
 * `Person::dependents()` marcaba esas tres con `block => true` desde el
 * principio y su docblock decia que bloquean, pero ni `deleteSave()` ni
 * `bulkDelete()` llamaban a `hasBlockingDependents()`. Se podia dar de baja a
 * alguien con anos de firmas sin un solo aviso — y borrar a quien firmo deja el
 * papel sin autor, que es justo lo que un inspector pide. Encima el borrado
 * definitivo posterior chocaba con la clave ajena y devolvia un 500.
 *
 * Todos los demas modulos ya lo comprobaban (RegionController:319,
 * CountryController:388, LocaleController:359...). Este no.
 */
class PersonBlockingDependentsTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'people';
    }

    protected function setUp(): void
    {
        parent::setUp();

        DocumentType::firstOrCreate(
            ['country_id' => 1, 'code' => 'DNI'],
            ['slug' => Str::random(22), 'name' => 'Documento Nacional de Identidad',
             'min_length' => 7, 'max_length' => 8, 'is_active' => true, 'created_by' => 1],
        );
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->persona();
    }

    private function persona(string $numDoc = '47019236'): Person
    {
        return Person::create($this->base() + [
            'doc_type' => 'DNI', 'num_doc' => $numDoc,
            'name' => 'Ana', 'lastname' => 'Quispe', 'is_active' => true,
        ]);
    }

    /** Ata a la persona a un plan, que es la dependencia que bloquea. */
    private function conPlan(Person $p): void
    {
        $empresa = Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
        $tipo = WorkType::firstOrCreate(['code' => 'MTTO'], $this->base());
        $sede = WorkLocation::firstOrCreate(['name' => 'Lurín'], $this->base());

        $plan = WorkPlan::create($this->base() + [
            'company_id'       => $empresa->id,
            'work_type_id'     => $tipo->id,
            'work_location_id' => $sede->id,
            'user_id'          => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'code'             => 'PE26-0808-' . str_pad((string) $p->id, 4, '0', STR_PAD_LEFT),
            'description'      => 'Mantenimiento',
            'date_start'       => '2026-08-08',
        ]);

        DB::table('work_plan_people')->insert([
            'slug' => Str::random(22),
            'work_plan_id' => $plan->id, 'person_id' => $p->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_no_se_da_de_baja_a_quien_tiene_un_plan(): void
    {
        $ana = $this->persona();
        $this->conPlan($ana);

        $this->actingAs($this->admin())
            ->delete(route('business_management.people.deleteSave', $ana->slug), [
                'deleted_description' => 'Ya no trabaja aqui',
            ])
            ->assertSessionHas('error');

        $this->assertFalse($ana->fresh()->trashed(), 'Se dio de baja a alguien con un plan.');
    }

    /** Sin dependencias sigue funcionando: el guard no puede bloquearlo todo. */
    public function test_a_quien_no_tiene_nada_si_se_le_da_de_baja(): void
    {
        $suelta = $this->persona('10203040');

        $this->actingAs($this->admin())
            ->delete(route('business_management.people.deleteSave', $suelta->slug), [
                'deleted_description' => 'Ya no trabaja aqui',
            ]);

        $this->assertTrue($suelta->fresh()->trashed());
    }

    /** En masa: la que tiene plan se aparta y las demas se van. */
    public function test_una_masiva_aparta_a_quien_tiene_plan_y_borra_al_resto(): void
    {
        $conPlan = $this->persona('47019236');
        $this->conPlan($conPlan);
        $libre = $this->persona('10203040');

        $this->actingAs($this->admin())
            ->post(route('business_management.people.bulk_delete'), [
                'ids' => [$conPlan->id, $libre->id],
                'deleted_description' => 'Limpieza de padron',
            ]);

        $this->assertFalse($conPlan->fresh()->trashed(), 'La masiva se llevo a alguien con un plan.');
        $this->assertTrue($libre->fresh()->trashed(), 'La masiva no borro a la que si se podia.');
    }
}
