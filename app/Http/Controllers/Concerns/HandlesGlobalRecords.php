<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * HandlesGlobalRecords — aparta de una operación masiva los registros GLOBALES
 * del catálogo (`tenant_id` null) cuando quien opera no es super.
 *
 * Por qué hace falta: el guard de `BelongsToTenantOrGlobal` salta en el
 * `updating`/`deleting` del modelo, o sea DENTRO de la transacción y con el
 * lote ya a medias. La excepción sube, la transacción hace rollback y el
 * usuario pierde el lote ENTERO —incluidas las filas que sí podía tocar—
 * terminando en un 403 que le manda al dashboard sin decirle nada.
 *
 * Es exactamente el mismo problema que ya resolvía `splitLockedIds` para los
 * candados, y se resuelve igual: se separan antes de empezar, se opera con el
 * resto y se le dice al usuario cuántos quedaron fuera y por qué.
 *
 * @see HandlesRecordLocking::splitLockedIds()
 */
trait HandlesGlobalRecords
{
    /**
     * Separa los IDs globales de un lote. Devuelve [permitidos, globales].
     * Para un super no aparta nada: él sí puede tocarlos.
     *
     * @param  class-string<Model>  $modelClass
     * @return array{0: array<int>, 1: array<int>}
     */
    protected function splitGlobalIds(string $modelClass, array $ids): array
    {
        $ids = array_map('intval', $ids);

        if (empty($ids) || request()->user()?->hasRole('super')) {
            return [array_values($ids), []];
        }

        $globales = $modelClass::whereIn('id', $ids)
            ->whereNull('tenant_id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [array_values(array_diff($ids, $globales)), $globales];
    }
}
