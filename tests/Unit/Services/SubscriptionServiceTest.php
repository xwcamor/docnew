<?php

namespace Tests\Unit\Services;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\SystemManagement\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SubscriptionService();
    }

    public function test_create_persists_subscription_with_defaults(): void
    {
        $tenant = Tenant::factory()->create();

        $sub = $this->service->create($tenant, [
            'plan'      => 'pro',
            'ends_at'   => now()->addYear(),
        ]);

        $this->assertSame('pro', $sub->plan);
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertSame('USD', $sub->currency);
        $this->assertSame($tenant->id, $sub->tenant_id);
    }

    public function test_start_trial_creates_trial_with_correct_dates(): void
    {
        $tenant = Tenant::factory()->create();

        $sub = $this->service->startTrial($tenant, 'pro', 14);

        $this->assertTrue($sub->isTrial());
        $this->assertSame('pro', $sub->plan);
        $this->assertNotNull($sub->trial_ends_at);
        $this->assertEqualsWithDelta(14, now()->diffInDays($sub->ends_at), 0.5);
    }

    public function test_extend_closes_current_and_creates_new(): void
    {
        $tenant = Tenant::factory()->create();
        $original = Subscription::factory()->for($tenant)->state(['ends_at' => now()->addMonths(6)])->create();

        $new = $this->service->extend($tenant, [
            'plan'    => 'enterprise',
            'ends_at' => now()->addYear(),
        ]);

        $original->refresh();
        $this->assertSame(Subscription::STATUS_EXPIRED, $original->status, 'Sub anterior debe quedar como expired');
        $this->assertSame('enterprise', $new->plan);
        $this->assertSame(Subscription::STATUS_ACTIVE, $new->status);
        $this->assertSame(2, Subscription::where('tenant_id', $tenant->id)->count(), 'Histórico preservado: 2 filas');
    }

    public function test_cancel_marks_status_and_records_reason(): void
    {
        $sub = Subscription::factory()->create();

        $this->service->cancel($sub, 'Cliente pidió cancelar');

        $sub->refresh();
        $this->assertSame(Subscription::STATUS_CANCELLED, $sub->status);
        $this->assertSame('Cliente pidió cancelar', $sub->cancellation_reason);
        $this->assertNotNull($sub->cancelled_at);
    }

    public function test_mark_expired_only_touches_subs_past_ends_at(): void
    {
        // Active y pasada → debería marcarse
        Subscription::factory()->state(['status' => Subscription::STATUS_ACTIVE, 'ends_at' => now()->subDay()])->create();
        // Trial y pasada → debería marcarse
        Subscription::factory()->trial()->state(['ends_at' => now()->subDay(), 'trial_ends_at' => now()->subDay()])->create();
        // Active y futura → NO debería tocarse
        Subscription::factory()->state(['ends_at' => now()->addDays(30)])->create();
        // Cancelled aunque pasada → NO debería tocarse
        Subscription::factory()->cancelled()->state(['ends_at' => now()->subDay()])->create();

        $count = $this->service->markExpired();

        $this->assertSame(2, $count);
        $this->assertSame(2, Subscription::where('status', Subscription::STATUS_EXPIRED)->count());
    }

    public function test_tenant_current_plan_falls_back_to_free_with_no_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        $this->assertSame('free', $tenant->currentPlan());
        $this->assertFalse($tenant->hasActiveSubscription());
    }

    public function test_tenant_current_plan_reflects_active_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        Subscription::factory()->for($tenant)->plan('enterprise')->create();

        $tenant->refresh();
        $this->assertSame('enterprise', $tenant->currentPlan());
        $this->assertTrue($tenant->hasActiveSubscription());
    }
}
