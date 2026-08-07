<?php

namespace Tests\Feature\Diagnostics;

use App\Models\Chromatographical;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Models\User;
use App\Services\Diagnostics\HealthIndexService;
use Database\Seeders\CromasRulesSeeder;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Alertas in-app de salud: al persistir el HI, si el trafo ENTRÓ a Malo/Muy
 * Malo (o empezó a empeorar) se publica un Message con audiencia tenant.
 * Solo en transiciones: re-evaluar el mismo estado no re-alerta.
 */
class HealthAlertTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DiagnosticCatalogSeeder::class);
        $this->seed(CromasRulesSeeder::class);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        // Clientes para la 3ª capa (restricción por cliente).
        DB::table('customers')->insertOrIgnore([
            ['id' => 10, 'slug' => Str::random(22), 'name' => 'Cliente A', 'is_active' => true, 'tenant_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 99, 'slug' => Str::random(22), 'name' => 'Cliente Z', 'is_active' => true, 'tenant_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->user = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
    }

    /** Trafo del tenant 1 con una muestra de cromas en tramo "Malo" (gases altos). */
    private function badTransformer(): Transformer
    {
        $oil   = OilType::where('code', 'mineral')->firstOrFail();
        $trafo = TransformerType::where('code', 'potencia')->firstOrFail();

        $t = new Transformer();
        $t->forceFill([
            'serial' => 'ALERTA-1', 'slug' => 'alerta-' . uniqid(),
            'oil_type_id' => $oil->id, 'transformer_type_id' => $trafo->id,
            'customer_id' => 10, 'tenant_id' => 1,
        ])->saveQuietly();

        // Gases que dan DGAF ≈ 2.6 → "Malo" (NO el tope: deja banda peor para
        // que el forecast tenga hacia dónde proyectar).
        (new Chromatographical())->forceFill([
            'transformer_id' => $t->id, 'sample_date' => now(), 'tenant_id' => 1,
            'h2' => 300, 'ch4' => 250, 'c2h4' => 300, 'c2h6' => 120,
            'co' => 800, 'co2' => 14000, 'c2h2' => 30,
        ])->saveQuietly();

        return $t;
    }

    public function test_entrar_en_riesgo_publica_alerta_al_tenant_y_no_duplica(): void
    {
        $t = $this->badTransformer();

        app(HealthIndexService::class)->evaluate($t); // notify default

        $this->assertSame(1, Message::count(), 'Debe publicarse exactamente una alerta.');
        $msg = Message::first();
        $this->assertSame(Message::AUDIENCE_TENANT, $msg->audience_type);
        $this->assertSame(1, (int) $msg->audience_id);
        $this->assertNotNull($msg->published_at);
        $this->assertStringContainsString('ALERTA-1', $msg->subject);
        // El usuario del workspace la recibe en su campana.
        $this->assertSame(1, MessageRecipient::where('user_id', $this->user->id)->count());

        // Re-evaluar el mismo estado: sin transición → sin alerta nueva.
        app(HealthIndexService::class)->evaluate($t->fresh());
        $this->assertSame(1, Message::count());
    }

    public function test_notify_false_no_alerta(): void
    {
        $t = $this->badTransformer();

        app(HealthIndexService::class)->evaluate($t, persist: true, notify: false);

        $this->assertSame(0, Message::count());
    }

    public function test_usuario_restringido_a_otro_cliente_no_recibe_la_alerta(): void
    {
        // dueño: asignado al cliente del trafo (10) → SÍ recibe.
        $dueno = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        DB::table('customer_user')->insert(['user_id' => $dueno->id, 'customer_id' => 10, 'created_at' => now(), 'updated_at' => now()]);
        // ajeno: restringido a OTRO cliente (99) → NO debe recibir.
        $ajeno = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        DB::table('customer_user')->insert(['user_id' => $ajeno->id, 'customer_id' => 99, 'created_at' => now(), 'updated_at' => now()]);

        $t = $this->badTransformer(); // customer_id = 10

        app(HealthIndexService::class)->evaluate($t);

        $msg = Message::firstOrFail();
        $recipients = MessageRecipient::where('message_id', $msg->id)->pluck('user_id')->map(fn ($i) => (int) $i);
        $this->assertTrue($recipients->contains($this->user->id), 'Sin restricción → recibe.');
        $this->assertTrue($recipients->contains($dueno->id), 'Asignado al cliente del trafo → recibe.');
        $this->assertFalse($recipients->contains($ajeno->id), 'Restringido a otro cliente → NO recibe.');
    }

    public function test_forecast_proyecta_meses_hacia_la_banda_peor(): void
    {
        // Muestra vieja (hace 6 meses) buena + muestra nueva mala (DGAF subió a
        // tramo "Malo", 2..3): la proyección debe apuntar a "Muy Malo" (>= 3).
        $t = $this->badTransformer(); // muestra mala con sample_date = now()
        (new Chromatographical())->forceFill([
            'transformer_id' => $t->id, 'sample_date' => now()->subMonths(6), 'tenant_id' => 1,
            'h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50,
            'co' => 500, 'co2' => 10000, 'c2h2' => 10,
        ])->saveQuietly();

        $f = app(HealthIndexService::class)->cromasForecast($t->fresh());

        $this->assertNotNull($f);
        $this->assertSame('Muy Malo', $f['target']);
        $this->assertGreaterThan(0, $f['months']);
        $this->assertLessThan(24, $f['months'], 'Con esa pendiente el cruce queda a meses, no años.');
    }
}
