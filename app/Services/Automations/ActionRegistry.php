<?php

namespace App\Services\Automations;

use App\Services\Automations\Contracts\ActionContract;
use InvalidArgumentException;

/**
 * Registry de actions disponibles. Mismo patrón que DataSourceRegistry —
 * para agregar un action nuevo (ej. Slack, webhook), implementa la interfaz
 * y regístralo en el provider.
 */
class ActionRegistry
{
    /** @var array<string, ActionContract> */
    protected array $actions = [];

    public function register(ActionContract $action): void
    {
        $this->actions[$action->key()] = $action;
    }

    public function resolve(string $key): ActionContract
    {
        if (!isset($this->actions[$key])) {
            throw new InvalidArgumentException("Action '{$key}' no registrado.");
        }
        return $this->actions[$key];
    }

    /** @return array<int, ActionContract> */
    public function all(): array
    {
        return array_values($this->actions);
    }

    public function catalog(): array
    {
        return array_map(fn (ActionContract $a) => [
            'key'    => $a->key(),
            'label'  => $a->label(),
            'config' => $a->configSchema(),
        ], $this->all());
    }
}
