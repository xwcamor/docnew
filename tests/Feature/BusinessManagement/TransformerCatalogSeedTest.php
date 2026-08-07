<?php

namespace Tests\Feature\BusinessManagement;

use Database\Seeders\ConnectionTypesSeeder;
use Database\Seeders\TransformerPreservationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransformerCatalogSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_load_catalogs_and_are_idempotent(): void
    {
        (new ConnectionTypesSeeder())->run();
        (new TransformerPreservationsSeeder())->run();
        // Repetir no duplica (firstOrCreate por code).
        (new ConnectionTypesSeeder())->run();
        (new TransformerPreservationsSeeder())->run();

        $this->assertSame(34, DB::table('connection_types')->count());
        $this->assertSame(4, DB::table('transformer_preservations')->count());
        $this->assertSame('Dyn5', DB::table('connection_types')->orderBy('sort_order')->value('name'));
        $this->assertDatabaseHas('transformer_preservations', ['name' => 'Tanque sellado con nitrógeno']);
    }
}
