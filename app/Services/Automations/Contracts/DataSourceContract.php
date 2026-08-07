<?php

namespace App\Services\Automations\Contracts;

use App\Models\Automation;
use Illuminate\Support\Collection;

/**
 * DataSourceContract — fuente de datos que una automation puede consumir.
 *
 * Cada data source declara:
 *   - key(): identificador único usado en automations.data_source
 *   - label(): nombre para la UI (traducido)
 *   - fields(): catálogo de campos filtrables, cada uno con tipo y
 *     operadores soportados ('=', '!=', '>', '<', 'in', 'contains', ...).
 *     El frontend usa esto para armar el query builder.
 *   - fetch(): ejecuta la consulta con los filtros guardados en
 *     automation.data_filter y devuelve los registros encontrados.
 *
 * Scope: la fetch() DEBE respetar el tenant_id de la automation. La global
 * scope de BelongsToTenant no aplica aquí porque corremos en queue (sin user).
 */
interface DataSourceContract
{
    public function key(): string;

    public function label(): string;

    /** @return array<int, array{key: string, label: string, type: string, operators: array<int, string>}> */
    public function fields(): array;

    public function fetch(Automation $automation): Collection;

    /**
     * Roles que pueden usar este data source. Cuando el array esta vacio
     * (default), todos pueden. Cuando trae roles especificos, solo esos
     * los ven en el catalogo y pueden guardar automatizaciones con esta
     * fuente. Ej: ['super'] restringe a super-admins.
     *
     * @return array<int, string>
     */
    public function allowedRoles(): array;
}
