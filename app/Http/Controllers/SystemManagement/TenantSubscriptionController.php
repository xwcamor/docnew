<?php

namespace App\Http\Controllers\SystemManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\SystemManagement\Subscription\CancelSubscriptionRequest;
use App\Http\Requests\SystemManagement\Subscription\StoreSubscriptionRequest;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\SystemManagement\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;

/**
 * Controlador thin para subscriptions ligadas a un tenant. Delega al service.
 * Las routes están bajo `system_management.tenants.subscriptions.*` y protegidas
 * por `role:super` (gobernanza de billing es decisión de plataforma).
 */
class TenantSubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $service) {}

    /**
     * Crear nueva subscription para el tenant. Modo `paid` (manual) o `trial`.
     * Si el tenant ya tiene una activa, el caller debería usar renew/extend,
     * pero no lo bloqueamos hard — super sabe lo que hace.
     */
    public function store(StoreSubscriptionRequest $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validated();

        if ($data['kind'] === 'trial') {
            $this->service->startTrial(
                $tenant,
                $data['plan'],
                (int) $data['trial_days'],
            );

            return back()->with('success', __('subscriptions.created_trial'));
        }

        $this->service->create($tenant, [
            'plan'           => $data['plan'],
            'starts_at'      => isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : now(),
            'ends_at'        => Carbon::parse($data['ends_at']),
            'amount_paid'    => $data['amount_paid'] ?? null,
            'currency'       => $data['currency'] ?? 'USD',
            'payment_method' => $data['payment_method'] ?? 'manual',
            'notes'          => $data['notes'] ?? null,
        ]);

        return back()->with('success', __('subscriptions.created'));
    }

    /**
     * Renovar: corta la actual y crea siguiente. Atómico.
     * Acepta el mismo payload que store en modo `paid`.
     */
    public function renew(StoreSubscriptionRequest $request, Tenant $tenant): RedirectResponse
    {
        abort_if($request->kind !== 'paid', 422, 'Renew solo acepta modo paid.');

        $data = $request->validated();

        $this->service->extend($tenant, [
            'plan'           => $data['plan'],
            'ends_at'        => Carbon::parse($data['ends_at']),
            'amount_paid'    => $data['amount_paid'] ?? null,
            'currency'       => $data['currency'] ?? 'USD',
            'payment_method' => $data['payment_method'] ?? 'manual',
            'notes'          => $data['notes'] ?? null,
        ]);

        return back()->with('success', __('subscriptions.renewed'));
    }

    /**
     * Cancelar o suspender. cancel = deja usar hasta ends_at; suspend = corta ya.
     */
    public function cancel(CancelSubscriptionRequest $request, Tenant $tenant, Subscription $subscription): RedirectResponse
    {
        abort_if(
            $subscription->tenant_id !== $tenant->id,
            404,
            'Subscription no pertenece a este tenant.',
        );

        if ($request->mode === 'suspend') {
            $this->service->suspend($subscription, $request->reason);
            return back()->with('success', __('subscriptions.suspended'));
        }

        $this->service->cancel($subscription, $request->reason);
        return back()->with('success', __('subscriptions.cancelled'));
    }
}
