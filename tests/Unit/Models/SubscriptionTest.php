<?php

namespace Tests\Unit\Models;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_scope_includes_trial_active_and_cancelled_within_period(): void
    {
        // "current" = el tenant todavía tiene acceso. Incluye trial/active, y
        // TAMBIÉN cancelled mientras no haya pasado ends_at (cancel suave: se
        // canceló pero sigue activo hasta fin del período pagado).
        // Quedan FUERA: expired, cancelled-ya-vencida, suspended.
        $tenant = Tenant::factory()->create();

        Subscription::factory()->for($tenant)->create();                                  // active, future     → current
        Subscription::factory()->for($tenant)->trial()->create();                         // trial, future      → current
        Subscription::factory()->for($tenant)->cancelled()->create();                     // cancelled, future  → current (cancel suave)
        Subscription::factory()->for($tenant)->expired()->create();                       // expired            → no
        Subscription::factory()->for($tenant)->cancelled()
            ->state(['ends_at' => now()->subDay()])->create();                            // cancelled, vencida → no
        Subscription::factory()->for($tenant)
            ->state(['status' => Subscription::STATUS_SUSPENDED])->create();              // suspended          → no

        $this->assertSame(3, Subscription::current()->count());
    }

    public function test_is_trial_only_true_for_trial_status_and_future_ends(): void
    {
        $sub = Subscription::factory()->trial()->create();
        $this->assertTrue($sub->isTrial());

        $past = Subscription::factory()->trial()->state(['ends_at' => now()->subDay()])->create();
        $this->assertFalse($past->isTrial());
    }

    public function test_is_expired_true_for_past_ends_at_unless_cancelled(): void
    {
        $expired   = Subscription::factory()->expired()->create();
        $cancelled = Subscription::factory()->cancelled()->state(['ends_at' => now()->subDay()])->create();

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($cancelled->isExpired(), 'Cancelled debe ser cancelled, no expired');
    }

    public function test_days_remaining_returns_zero_when_past(): void
    {
        $sub = Subscription::factory()->expired()->create();
        $this->assertSame(0, $sub->daysRemaining());
    }

    public function test_days_remaining_counts_future_days(): void
    {
        $sub = Subscription::factory()->state(['ends_at' => now()->addDays(7)])->create();
        $this->assertGreaterThanOrEqual(6, $sub->daysRemaining());
        $this->assertLessThanOrEqual(7, $sub->daysRemaining());
    }
}
