<?php

namespace App\Http\Requests\AutomationManagement\Automation;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use App\Services\Automations\DataSourceRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación al crear o editar una automation. Usa la misma forma para
 * ambos (no hay constraints únicos en el shape, así que reusamos).
 *
 * Los catálogos de data_source y action_type vienen del frontend (registries
 * del backend los exponen). Aquí validamos que vengan como strings — la
 * existencia real en los registries la chequea el controller.
 */
class StoreAutomationRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    // Etiquetas de :attribute = los mismos labels del formulario (resources/lang).
    protected $attributeNamespace = 'automations';

    // Campos anidados de config cuyo label vive bajo otra clave que el path.
    protected $attributeOverrides = [
        'trigger_config.kind'       => 'automations.trigger_kind',
        'trigger_config.expression' => 'automations.trigger_cron_expression',
        'trigger_config.time'       => 'automations.trigger_time',
        'trigger_config.day'        => 'automations.trigger_day_of_month',
        'data_filter.limit'         => 'automations.data_filter_limit',
        'action_config.to'          => 'automations.action_email_to',
        'action_config.subject'     => 'automations.action_email_subject',
        'action_config.body'        => 'automations.action_email_body',
        'action_config.recipients'  => 'automations.action_in_app_recipients',
        'action_config.title'       => 'automations.action_in_app_title',
        'action_config.user_ids'    => 'automations.action_in_app_specific_users',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Defense-in-depth: si NO es super, removemos cualquier `tenant_id` que
     * haya llegado en el payload. Sin esto, un admin podría mandar
     * `tenant_id=B` y como la rule es `nullable`, pasaría al validated() y
     * podría escribir cross-tenant. El trait BelongsToTenant le autoasigna
     * su propio tenant en `creating`.
     */
    protected function prepareForValidation(): void
    {
        $isSuper = $this->user()?->hasRole('super') ?? false;
        if (!$isSuper) {
            $this->offsetUnset('tenant_id');
        }
    }

    public function rules(): array
    {
        $isSuper = $this->user()?->hasRole('super') ?? false;

        return [
            // Super DEBE elegir workspace al crear/editar (las automations sin
            // tenant no funcionan: los DataSources filtran por tenant_id y
            // devolverian 0 records). Admin no envia este campo — el trait
            // BelongsToTenant le autoasigna su propio tenant.
            'tenant_id' => $isSuper
                ? ['required', 'integer', Rule::exists('tenants', 'id')->whereNull('deleted_at')]
                : ['nullable'],

            'name'             => ['required', 'string', 'max:150'],
            'description'      => ['nullable', 'string', 'max:1000'],
            'is_active'        => ['sometimes', 'boolean'],

            'trigger_type'     => ['required', 'string', 'in:schedule'],
            'trigger_config'   => ['required', 'array'],
            'trigger_config.kind' => ['required', 'string', 'in:cron,daily,weekly,monthly'],
            'trigger_config.expression' => ['required_if:trigger_config.kind,cron', 'nullable', 'string', 'max:120'],
            'trigger_config.time'       => ['required_unless:trigger_config.kind,cron', 'nullable', 'date_format:H:i'],
            'trigger_config.day'        => ['nullable', 'integer', 'min:0', 'max:31'],

            'data_source'      => [
                'nullable', 'string', 'max:60',
                // Solo data sources permitidos para el rol actual (ej.
                // subscriptions queda fuera para admin/user).
                function ($attribute, $value, $fail) {
                    if (!$value) return;
                    if (!app(DataSourceRegistry::class)->userCanUseKey($value)) {
                        $fail(__('automations.data_source_not_allowed'));
                    }
                },
            ],
            'data_filter'      => ['nullable', 'array'],
            'data_filter.where'         => ['nullable', 'array', 'max:10'],
            'data_filter.where.*.field' => ['required_with:data_filter.where', 'string', 'max:60'],
            'data_filter.where.*.op'    => ['required_with:data_filter.where', 'string', 'in:=,!=,>,<,>=,<=,in,contains'],
            'data_filter.where.*.value' => ['nullable'],
            'data_filter.limit'         => ['nullable', 'integer', 'min:1', 'max:1000'],

            'action_type'      => ['required', 'string', 'in:email,in_app_notification'],
            'action_config'    => ['required', 'array'],

            // Config de la acción según el tipo. Antes solo se validaba que
            // `action_config` fuera un array → los campos internos (destinatario,
            // asunto, cuerpo, etc.) NO se validaban y entraban vacíos.
            // — Email —
            'action_config.to'         => ['required_if:action_type,email', 'array', 'min:1'],
            'action_config.to.*'       => ['email', 'max:190'],
            'action_config.subject'    => ['required_if:action_type,email', 'nullable', 'string', 'max:200'],
            // — Notificación in-app —
            'action_config.recipients' => ['required_if:action_type,in_app_notification', 'nullable', 'string', 'in:tenant_admins,specific_users'],
            'action_config.title'      => ['required_if:action_type,in_app_notification', 'nullable', 'string', 'max:200'],
            'action_config.user_ids'   => ['required_if:action_config.recipients,specific_users', 'array'],
            'action_config.user_ids.*' => ['integer'],
            // El cuerpo del mensaje es obligatorio para ambos tipos.
            'action_config.body'       => ['required', 'string', 'max:5000'],
        ];
    }
}
