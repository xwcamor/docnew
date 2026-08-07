<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Comment;
use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Models\User;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use RefreshDatabase;

    protected Transformer $transformer;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('plans')->insertOrIgnore([['id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1, 'export_rate_limit' => 50, 'support_level' => 'priority', 'features' => json_encode(['team_management' => true]), 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD', 'is_active' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        $this->seed(DiagnosticCatalogSeeder::class);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['transformers.view', 'comments.view', 'comments.create', 'comments.delete', 'diagnosis_notes.create'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin']);
        $admin->syncPermissions(Permission::all());

        $this->admin = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->admin->assignRole('admin');

        $this->transformer = (new Transformer())->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => 'S1', 'tag' => 'TR',
            'oil_type_id' => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id' => TransformerType::where('code', 'potencia')->value('id'),
            'tenant_id' => 1, 'created_by' => $this->admin->id,
        ]);
        $this->transformer->save();
    }

    public function test_user_with_permission_can_comment_on_transformer(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('business_management.comments.store'), [
                'type' => 'transformer', 'id' => $this->transformer->id,
                'context' => 'diag_furanos', 'body' => 'Revisar bushing fase A en próxima parada.',
            ])
            ->assertCreated()
            ->assertJsonPath('body', 'Revisar bushing fase A en próxima parada.')
            ->assertJsonPath('author', $this->admin->name);

        $this->assertDatabaseHas('comments', [
            'commentable_type' => Transformer::class,
            'commentable_id'   => $this->transformer->id,
            'context'          => 'diag_furanos',
            'user_id'          => $this->admin->id,
            'tenant_id'        => 1,
        ]);
    }

    public function test_user_without_create_permission_is_forbidden(): void
    {
        $viewerRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web'], ['description' => 'Test viewer']);
        $viewerRole->syncPermissions(['transformers.view', 'comments.view']);
        $viewer = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)
            ->postJson(route('business_management.comments.store'), [
                'type' => 'transformer', 'id' => $this->transformer->id, 'body' => 'no debería poder',
            ])
            ->assertForbidden();
    }

    public function test_comment_on_a_sample_and_list_it(): void
    {
        $furano = \App\Models\Furano::create([
            'transformer_id' => $this->transformer->id, 'sample_date' => '2024-01-01',
            'fal' => 100, 'tenant_id' => 1, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('business_management.comments.store'), [
                'type' => 'furano', 'id' => $furano->id, 'context' => 'sample', 'body' => 'Muestra dudosa, repetir.',
            ])->assertCreated();

        $this->actingAs($this->admin)
            ->postJson(route('business_management.comments.index'), ['type' => 'furano', 'id' => $furano->id, 'context' => 'sample'])
            ->assertOk()
            ->assertJsonPath('comments.0.body', 'Muestra dudosa, repetir.');

        $this->assertDatabaseHas('comments', [
            'commentable_type' => \App\Models\Furano::class,
            'commentable_id'   => $furano->id,
            'context'          => 'sample',
        ]);
    }

    public function test_sample_commenter_cannot_write_diagnostician_note(): void
    {
        // Perfil "carga de muestras": comenta SUS muestras (comments.create) pero
        // NO firma la nota del diagnosticador (sin diagnosis_notes.create).
        $loaderRole = Role::firstOrCreate(['name' => 'sample_loader', 'guard_name' => 'web'], ['description' => 'Test loader']);
        $loaderRole->syncPermissions(['transformers.view', 'comments.view', 'comments.create']);
        $loader = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $loader->assignRole('sample_loader');

        $furano = \App\Models\Furano::create([
            'transformer_id' => $this->transformer->id, 'sample_date' => '2024-02-02',
            'fal' => 50, 'tenant_id' => 1, 'created_by' => $this->admin->id,
        ]);

        // SÍ puede comentar la muestra.
        $this->actingAs($loader)
            ->postJson(route('business_management.comments.store'), [
                'type' => 'furano', 'id' => $furano->id, 'context' => 'sample', 'body' => 'Muestra mal sellada.',
            ])->assertCreated();

        // NO puede escribir la nota del diagnosticador (sobre el transformer).
        $this->actingAs($loader)
            ->postJson(route('business_management.comments.store'), [
                'type' => 'transformer', 'id' => $this->transformer->id, 'context' => 'diag_furanos', 'body' => 'no debería poder firmar la nota',
            ])->assertForbidden();

        $this->assertDatabaseMissing('comments', [
            'commentable_type' => Transformer::class,
            'commentable_id'   => $this->transformer->id,
            'user_id'          => $loader->id,
        ]);
    }

    public function test_author_can_delete_but_non_author_non_admin_cannot(): void
    {
        $comment = Comment::create([
            'commentable_type' => Transformer::class, 'commentable_id' => $this->transformer->id,
            'context' => 'diag_furanos', 'user_id' => $this->admin->id, 'body' => 'nota', 'tenant_id' => 1,
        ]);

        // Otro usuario, con permiso de borrar pero NO autor ni admin → 403.
        $otherRole = Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web'], ['description' => 'Test editor']);
        $otherRole->syncPermissions(['comments.create', 'comments.delete', 'comments.view']);
        $other = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $other->assignRole('editor');

        $this->actingAs($other)
            ->deleteJson(route('business_management.comments.destroy', $comment->id))
            ->assertForbidden();

        // El autor sí puede borrar el suyo.
        $this->actingAs($this->admin)
            ->deleteJson(route('business_management.comments.destroy', $comment->id))
            ->assertOk();

        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }
}
