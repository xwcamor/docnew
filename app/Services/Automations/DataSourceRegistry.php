<?php

namespace App\Services\Automations;

use App\Services\Automations\Contracts\DataSourceContract;
use InvalidArgumentException;

/**
 * Registry de data sources — punto único donde se declara qué módulos
 * pueden alimentar una automation.
 *
 * Para agregar uno nuevo: crea una clase que implemente DataSourceContract,
 * regístrala en register() y aparece en la UI automáticamente.
 */
class DataSourceRegistry
{
    /** @var array<string, DataSourceContract> */
    protected array $sources = [];

    public function register(DataSourceContract $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    public function resolve(string $key): DataSourceContract
    {
        if (!isset($this->sources[$key])) {
            throw new InvalidArgumentException("Data source '{$key}' no registrado.");
        }
        return $this->sources[$key];
    }

    /** @return array<int, DataSourceContract> */
    public function all(): array
    {
        return array_values($this->sources);
    }

    /**
     * Catálogo serializable para la UI. Filtra por allowedRoles() del data
     * source contra el rol del user actual. Si el data source declara
     * `allowedRoles() = []`, esta disponible para todos.
     *
     * Ej: SubscriptionsDataSource declara ['super'] → admin no la ve.
     */
    public function catalog(): array
    {
        $user = auth()->user();

        return array_values(array_map(
            fn (DataSourceContract $s) => [
                'key'    => $s->key(),
                'label'  => $s->label(),
                'fields' => $s->fields(),
            ],
            array_filter($this->all(), fn (DataSourceContract $s) => $this->userCanUse($s, $user))
        ));
    }

    /**
     * Public por si un caller externo (ej. validacion de FormRequest) necesita
     * saber si el user actual puede usar un data source especifico por key.
     */
    public function userCanUseKey(string $key): bool
    {
        if (!isset($this->sources[$key])) return false;
        return $this->userCanUse($this->sources[$key], auth()->user());
    }

    protected function userCanUse(DataSourceContract $source, $user): bool
    {
        $allowed = $source->allowedRoles();
        if (empty($allowed)) return true;
        if (!$user) return false;
        foreach ($allowed as $role) {
            if (method_exists($user, 'hasRole') && $user->hasRole($role)) return true;
        }
        return false;
    }
}
