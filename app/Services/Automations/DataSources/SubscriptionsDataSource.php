<?php

namespace App\Services\Automations\DataSources;

use App\Models\Automation;
use App\Models\Subscription;
use App\Services\Automations\Contracts\DataSourceContract;
use App\Services\Automations\Support\FilterApplier;
use Illuminate\Support\Collection;

class SubscriptionsDataSource implements DataSourceContract
{
    public function key(): string
    {
        return 'subscriptions';
    }

    public function label(): string
    {
        return __('automations.source_subscriptions');
    }

    public function allowedRoles(): array
    {
        // Subscriptions es data cross-tenant de billing/operacion. Solo super
        // tiene contexto para automatizar sobre esto.
        return ['super'];
    }

    public function fields(): array
    {
        return [
            [
                'key' => 'plan', 'label' => __('subscriptions.plan'),
                'type' => 'enum', 'operators' => ['=', '!='],
                'options' => [
                    ['value' => 'free',       'label' => 'Free'],
                    ['value' => 'basic',      'label' => 'Basic'],
                    ['value' => 'pro',        'label' => 'Pro'],
                    ['value' => 'enterprise', 'label' => 'Enterprise'],
                ],
            ],
            [
                'key' => 'status', 'label' => __('subscriptions.status'),
                'type' => 'enum', 'operators' => ['=', '!='],
                'options' => [
                    ['value' => 'active',    'label' => __('subscriptions.status_active')],
                    ['value' => 'expired',   'label' => __('subscriptions.status_expired')],
                    ['value' => 'cancelled', 'label' => __('subscriptions.status_cancelled')],
                    ['value' => 'suspended', 'label' => __('subscriptions.status_suspended')],
                ],
            ],
            ['key' => 'ends_at', 'label' => __('subscriptions.ends_at'), 'type' => 'date',   'operators' => ['>', '<', '>=', '<=']],
        ];
    }

    public function fetch(Automation $automation): Collection
    {
        $query = Subscription::query()
            ->where('tenant_id', $automation->tenant_id);

        FilterApplier::apply($query, $automation->data_filter ?? [], $this->fields());

        $limit = (int) ($automation->data_filter['limit'] ?? 100);
        return $query->limit($limit)->get();
    }
}
