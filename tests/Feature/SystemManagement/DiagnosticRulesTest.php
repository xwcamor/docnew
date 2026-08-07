<?php

namespace Tests\Feature\SystemManagement;

use App\Models\ResultScale;
use App\Models\Rule;
use App\Models\RuleCondition;
use App\Models\RuleSet;
use App\Models\Test;
use App\Models\User;
use App\Models\Variable;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DiagnosticRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        $this->seed(DiagnosticCatalogSeeder::class);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Super']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Admin']);
    }

    private function actingAsSuper(): User
    {
        $u = User::factory()->create(['tenant_id' => null, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('super');
        $this->actingAs($u);
        return $u;
    }

    /** Crea un cuadro mínimo (cromas) con una regla y una condición. */
    private function makeRuleSet(): RuleSet
    {
        $cromas = Test::where('code', 'cromas')->value('id');
        $oil = \App\Models\OilType::query()->value('id');
        $var = Variable::query()->value('id');

        $set = RuleSet::create([
            'test_id' => $cromas, 'oil_type_id' => $oil, 'transformer_type_id' => null,
            'standard_id' => null, 'total_weight' => 2, 'label' => 'Base', 'is_active' => true,
        ]);
        $rule = Rule::create([
            'rule_set_id' => $set->id, 'variable_id' => $var, 'score' => 1, 'weight' => 2,
            'priority' => 1, 'result_label' => null, 'is_active' => true,
        ]);
        RuleCondition::create([
            'rule_id' => $rule->id, 'variable_id' => $var, 'operator' => '<=', 'value' => 100, 'sort_order' => 1,
        ]);
        return $set;
    }

    public function test_index_loads_for_super(): void
    {
        $this->actingAsSuper();
        $this->get(route('system_management.diagnostic_rules.index'))
            ->assertInertia(fn ($p) => $p->component('SystemManagement/DiagnosticRules/Index')->has('blocks'));
    }

    public function test_admin_can_access_own_rules(): void
    {
        // Híbrido: el admin del workspace edita SUS reglas (override aislado).
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $this->actingAs($u);
        $this->get(route('system_management.diagnostic_rules.index'))->assertOk();
    }

    public function test_unprivileged_user_blocked(): void
    {
        // Sin rol super/admin → bloqueado por el middleware role:super|admin.
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->actingAs($u);
        $this->get(route('system_management.diagnostic_rules.index'))->assertRedirect();
    }

    public function test_admin_can_reach_rule_sets_editor(): void
    {
        // Los cuadros de reglas (sets) ahora son tenant-safe (copy-on-write).
        $this->makeRuleSet(); // un cuadro global de fábrica
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $this->actingAs($u);
        $this->get(route('system_management.diagnostic_rules.sets'))
            ->assertInertia(fn ($p) => $p
                ->component('SystemManagement/DiagnosticRules/Sets')
                ->where('isSuper', false)->has('sets'));
    }

    public function test_admin_edit_of_global_set_is_copy_on_write(): void
    {
        // Cuadro GLOBAL de fábrica (tenant_id null) con su regla original.
        $global = $this->makeRuleSet();
        $this->assertNull($global->tenant_id);
        $var = Variable::query()->firstOrFail();

        // El admin del tenant 1 "edita" el cuadro global → NO lo toca; crea su copia.
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $this->actingAs($u);

        $this->put(route('system_management.diagnostic_rules.set_update', $global->id), [
            'label' => 'Mi override', 'total_weight' => 9, 'is_active' => true,
            'rules' => [[
                'variable_id' => $var->id, 'score' => 4, 'weight' => 5, 'priority' => 1,
                'result_label' => null, 'is_active' => true,
                'conditions' => [['variable_id' => $var->id, 'operator' => '>', 'value' => 999]],
            ]],
        ])->assertRedirect();

        // El global quedó intacto (su regla original: score 1).
        $global->refresh();
        $this->assertNull($global->tenant_id);
        $globalRules = Rule::where('rule_set_id', $global->id)->get();
        $this->assertCount(1, $globalRules);
        $this->assertSame('1.00', (string) $globalRules->first()->score);

        // Se creó un override del tenant 1 con las reglas nuevas.
        $override = RuleSet::where('tenant_id', 1)
            ->where('test_id', $global->test_id)
            ->where('oil_type_id', $global->oil_type_id)
            ->first();
        $this->assertNotNull($override);
        $this->assertNotSame($global->id, $override->id);
        $ovRules = Rule::where('rule_set_id', $override->id)->get();
        $this->assertCount(1, $ovRules);
        $this->assertSame('4.00', (string) $ovRules->first()->score);

        // Restaurar borra el override del tenant (cae al global).
        $this->post(route('system_management.diagnostic_rules.set_restore', $override->id))
            ->assertRedirect();
        $this->assertNull(RuleSet::find($override->id));
        $this->assertNotNull(RuleSet::find($global->id)); // el global sigue intacto
    }

    public function test_admin_cannot_edit_another_tenants_set(): void
    {
        $cromas = Test::where('code', 'cromas')->value('id');
        $oil = \App\Models\OilType::query()->value('id');
        $foreign = RuleSet::create([
            'tenant_id' => 2, 'test_id' => $cromas, 'oil_type_id' => $oil, 'transformer_type_id' => null,
            'standard_id' => null, 'total_weight' => 2, 'label' => 'Ajeno', 'is_active' => true,
        ]);

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $this->actingAs($u);

        // El admin del tenant 1 no puede abrir el cuadro del tenant 2 (la app
        // redirige el 403 fuera → status 403 o 302, nunca 200 con los datos).
        $r = $this->get(route('system_management.diagnostic_rules.set_edit', $foreign->id));
        $this->assertContains($r->status(), [403, 302]);
    }

    public function test_update_replaces_bands(): void
    {
        $this->actingAsSuper();
        $furanos = Test::where('code', 'furanos')->value('id');

        $this->put(route('system_management.diagnostic_rules.update', 'furanos'), [
            'bands' => [
                ['condition_label' => 'Bueno', 'color' => 'green',  'hi_rating' => 4, 'score_to' => 0.5],
                ['condition_label' => 'Medio', 'color' => 'yellow', 'hi_rating' => 2, 'score_to' => 2],
                ['condition_label' => 'Malo',  'color' => 'red',    'hi_rating' => 0, 'score_to' => null],
            ],
            'hi_weight' => 7, 'hi_enabled' => true,
        ])->assertRedirect();

        $bands = ResultScale::where('test_id', $furanos)->orderBy('sort_order')->get();
        $this->assertCount(3, $bands);
        $this->assertNull($bands->first()->score_from);          // primera: −∞
        $this->assertSame('0.50', (string) $bands->first()->score_to);
        $this->assertNull($bands->last()->score_to);             // última: +∞
        $this->assertSame(7, Test::where('id', $furanos)->value('hi_weight'));
    }

    public function test_update_rejects_non_ascending_cuts(): void
    {
        $this->actingAsSuper();
        $this->put(route('system_management.diagnostic_rules.update', 'furanos'), [
            'bands' => [
                ['condition_label' => 'A', 'color' => 'green', 'hi_rating' => 4, 'score_to' => 2],
                ['condition_label' => 'B', 'color' => 'red',   'hi_rating' => 0, 'score_to' => 1], // baja → inválido
                ['condition_label' => 'C', 'color' => 'red',   'hi_rating' => 0, 'score_to' => null],
            ],
        ])->assertSessionHasErrors('bands');
    }

    // ───────────────────────── Fase 2: cuadros de reglas ─────────────────────

    public function test_sets_list_loads_for_super(): void
    {
        $this->actingAsSuper();
        $this->get(route('system_management.diagnostic_rules.sets'))
            ->assertInertia(fn ($p) => $p->component('SystemManagement/DiagnosticRules/Sets')->has('sets'));
    }

    public function test_edit_set_loads_with_rules(): void
    {
        $this->actingAsSuper();
        $set = $this->makeRuleSet();
        $this->get(route('system_management.diagnostic_rules.set_edit', $set->id))
            ->assertInertia(fn ($p) => $p
                ->component('SystemManagement/DiagnosticRules/SetEdit')
                ->has('rules')->has('variables')->has('operators'));
    }

    public function test_update_set_replaces_rules_and_conditions(): void
    {
        $this->actingAsSuper();
        $set = $this->makeRuleSet();
        $var = Variable::query()->firstOrFail();

        $this->put(route('system_management.diagnostic_rules.set_update', $set->id), [
            'label'        => 'Cuadro de prueba',
            'total_weight' => 10,
            'is_active'    => true,
            'rules' => [
                [
                    'variable_id' => $var->id, 'score' => 1, 'weight' => 2, 'priority' => 1,
                    'result_label' => null, 'is_active' => true,
                    'conditions' => [
                        ['variable_id' => $var->id, 'operator' => '<=', 'value' => 150],
                    ],
                ],
                [
                    'variable_id' => $var->id, 'score' => 4, 'weight' => 2, 'priority' => 2,
                    'result_label' => null, 'is_active' => true,
                    'conditions' => [
                        ['variable_id' => $var->id, 'operator' => '>', 'value' => 150],
                        ['variable_id' => $var->id, 'operator' => '<=', 'value' => 300],
                    ],
                ],
            ],
        ])->assertRedirect();

        $set->refresh();
        $this->assertSame('Cuadro de prueba', $set->label);
        $rules = Rule::where('rule_set_id', $set->id)->orderBy('priority')->get();
        $this->assertCount(2, $rules);
        $this->assertCount(1, RuleCondition::where('rule_id', $rules[0]->id)->get());
        $this->assertCount(2, RuleCondition::where('rule_id', $rules[1]->id)->get());
    }

    public function test_update_set_rejects_bad_operator(): void
    {
        $this->actingAsSuper();
        $set = $this->makeRuleSet();
        $var = Variable::query()->firstOrFail();

        $this->put(route('system_management.diagnostic_rules.set_update', $set->id), [
            'total_weight' => 10, 'is_active' => true,
            'rules' => [[
                'variable_id' => $var->id, 'score' => 1, 'weight' => 2, 'priority' => 1,
                'result_label' => null, 'is_active' => true,
                'conditions' => [['variable_id' => $var->id, 'operator' => '<<', 'value' => 1]],
            ]],
        ])->assertSessionHasErrors('rules.0.conditions.0.operator');
    }

    /** Editor de umbrales (tablas fiqui_*): guardar cambia el diagnóstico; restaurar revierte a norma. */
    public function test_edit_data_updates_and_restores_fiquis(): void
    {
        $this->actingAsSuper();
        (new \Database\Seeders\FiquiRulesSeeder())->run();

        $this->get(route('system_management.diagnostic_rules.data_edit', 'fiquis_rules'))
            ->assertInertia(fn ($p) => $p
                ->component('SystemManagement/DiagnosticRules/DataEdit')
                ->where('dataKey', 'fiquis_rules')
                ->has('data.tables'));

        // Sube el umbral de rigidez de mineral.mid: rig=50 ya no será "óptimo".
        $this->put(route('system_management.diagnostic_rules.data_update', 'fiquis_rules'), [
            'data' => ['tables' => ['mineral' => ['mid' => ['rig' => [99, 40, 35]]]]],
        ])->assertRedirect();

        // Persistió en la tabla relacional (no en el blob).
        $this->assertDatabaseHas('fiqui_thresholds', [
            'param_key' => 'rig', 'oil_code' => 'mineral', 'voltage_class' => 'mid', 't1' => 99,
        ]);

        // El motor lee el nuevo umbral (rig=50 < 99 → ya no es score 1).
        $r = (new \App\Services\Diagnostics\FiquisDiagnosisService())->evaluate('mineral', 100, ['rig' => 50]);
        $this->assertNotSame(1.0, $r['score']);

        // Restaurar re-siembra de norma → mineral.mid rig vuelve a 47.
        $this->post(route('system_management.diagnostic_rules.data_restore', 'fiquis_rules'))->assertRedirect();
        $this->assertDatabaseHas('fiqui_thresholds', [
            'param_key' => 'rig', 'oil_code' => 'mineral', 'voltage_class' => 'mid', 't1' => 47,
        ]);

        $r2 = (new \App\Services\Diagnostics\FiquisDiagnosisService())->evaluate('mineral', 100, ['rig' => 50]);
        $this->assertSame(1.0, $r2['score']); // mineral.mid rig>=47 → score 1 de nuevo
    }

    public function test_super_guarda_colores_globales(): void
    {
        $this->actingAsSuper();

        $this->put(route('system_management.diagnostic_rules.colors_update'), [
            'sema'  => ['green' => '#111111', 'lime' => '#222222', 'yellow' => '#333333', 'orange' => '#444444', 'red' => '#555555'],
            'stops' => ['good' => '#aaaaaa', 'warn' => '#bbbbbb', 'bad' => '#cccccc'],
        ])->assertRedirect();

        $this->assertDatabaseHas('diagnostic_datasets', ['key' => 'diagnostic_colors', 'tenant_id' => null]);
        $c = \App\Support\Diagnostics\DiagnosticColors::all();
        $this->assertSame('#111111', $c['sema']['green']);
        $this->assertSame('#cccccc', $c['stops']['bad']);
    }

    public function test_colores_rechaza_hex_invalido(): void
    {
        $this->actingAsSuper();

        $this->put(route('system_management.diagnostic_rules.colors_update'), [
            'sema'  => ['green' => 'rojo', 'lime' => '#222222', 'yellow' => '#333333', 'orange' => '#444444', 'red' => '#555555'],
            'stops' => ['good' => '#aaaaaa', 'warn' => '#bbbbbb', 'bad' => '#cccccc'],
        ])->assertSessionHasErrors('sema.green');
    }

    public function test_admin_personaliza_colores_por_tenant_y_restaura(): void
    {
        // Global de fábrica.
        \App\Models\DiagnosticDataset::create(['key' => 'diagnostic_colors', 'tenant_id' => null, 'data' => ['sema' => ['green' => '#1D7044']]]);

        $admin = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->put(route('system_management.diagnostic_rules.colors_update'), [
            'sema'  => ['green' => '#0a0a0a', 'lime' => '#222222', 'yellow' => '#333333', 'orange' => '#444444', 'red' => '#555555'],
            'stops' => ['good' => '#aaaaaa', 'warn' => '#bbbbbb', 'bad' => '#cccccc'],
        ])->assertRedirect();

        // Se creó el override del tenant (no se tocó el global).
        $this->assertDatabaseHas('diagnostic_datasets', ['key' => 'diagnostic_colors', 'tenant_id' => 1]);
        \App\Support\Diagnostics\RuleData::flush();
        $this->assertSame('#0a0a0a', \App\Support\Diagnostics\DiagnosticColors::all()['sema']['green']);

        // Restaurar borra SOLO el override del tenant → cae al global (#1D7044).
        $this->post(route('system_management.diagnostic_rules.colors_restore'))->assertRedirect();
        $this->assertDatabaseMissing('diagnostic_datasets', ['key' => 'diagnostic_colors', 'tenant_id' => 1]);
        $this->assertDatabaseHas('diagnostic_datasets', ['key' => 'diagnostic_colors', 'tenant_id' => null]);
        \App\Support\Diagnostics\RuleData::flush();
        $this->assertSame('#1D7044', \App\Support\Diagnostics\DiagnosticColors::all()['sema']['green']);
    }

    // ─────────────── Recálculo de flota tras editar reglas ───────────────

    public function test_guardar_semaforo_encola_recalculo_global(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->actingAsSuper();

        $this->put(route('system_management.diagnostic_rules.update', 'furanos'), [
            'bands' => [
                ['condition_label' => 'Bueno', 'color' => 'green', 'hi_rating' => 4, 'score_to' => 1],
                ['condition_label' => 'Malo',  'color' => 'red',   'hi_rating' => 0, 'score_to' => null],
            ],
        ])->assertRedirect();

        // Super edita el GLOBAL → recalcula la flota completa (tenantId null).
        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\RecalculateFleetCache::class,
            fn ($job) => $job->tenantId === null
        );
    }

    public function test_admin_guardar_encola_recalculo_solo_de_su_workspace(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $admin = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->put(route('system_management.diagnostic_rules.update', 'furanos'), [
            'bands' => [
                ['condition_label' => 'Bueno', 'color' => 'green', 'hi_rating' => 4, 'score_to' => 1],
                ['condition_label' => 'Malo',  'color' => 'red',   'hi_rating' => 0, 'score_to' => null],
            ],
        ])->assertRedirect();

        // Admin personaliza SUS reglas → recalcula solo los trafos de su tenant.
        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\RecalculateFleetCache::class,
            fn ($job) => $job->tenantId === 1
        );
    }

    public function test_editar_colores_no_encola_recalculo(): void
    {
        // Los colores son presentación: no cambian el diagnóstico → sin recálculo.
        \Illuminate\Support\Facades\Queue::fake();
        $this->actingAsSuper();

        $this->put(route('system_management.diagnostic_rules.colors_update'), [
            'sema'  => ['green' => '#111111', 'lime' => '#222222', 'yellow' => '#333333', 'orange' => '#444444', 'red' => '#555555'],
            'stops' => ['good' => '#aaaaaa', 'warn' => '#bbbbbb', 'bad' => '#cccccc'],
        ])->assertRedirect();

        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RecalculateFleetCache::class);
    }

    public function test_job_scoped_recalcula_solo_trafos_del_tenant(): void
    {
        DB::table('tenants')->insertOrIgnore([['id' => 2, 'slug' => Str::random(22), 'name' => 'Empresa 2', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        $mk = function (?int $tenantId) {
            $t = new \App\Models\Transformer();
            $t->forceFill([
                'serial' => 'FLT-' . uniqid(), 'slug' => 'flt-' . uniqid(),
                'oil_type_id' => \App\Models\OilType::query()->value('id'),
                'transformer_type_id' => \App\Models\TransformerType::query()->value('id'),
                'customer_id' => null, 'tenant_id' => $tenantId,
                'health_index' => 77, // valor basura: si cambia, el job lo tocó
            ])->saveQuietly();
            return $t;
        };
        $mio = $mk(1);
        $ajeno = $mk(2);

        (new \App\Jobs\RecalculateFleetCache(tenantId: 1))
            ->handle(app(\App\Services\Diagnostics\HealthIndexService::class));

        // El del tenant 1 se recalculó (sin muestras: deja de valer 77); el del
        // tenant 2 no se tocó.
        $this->assertNotEquals(77.0, (float) $mio->fresh()->health_index);
        $this->assertEquals(77.0, (float) $ajeno->fresh()->health_index);
    }
}
