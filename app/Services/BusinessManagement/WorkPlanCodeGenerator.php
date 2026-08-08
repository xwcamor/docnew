<?php

namespace App\Services\BusinessManagement;

use App\Models\Country;
use App\Models\WorkPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El codigo de un plan de trabajo.
 *
 * Se conserva la forma que ya conoce la gente en obra —`PE26-0608-0001`: pais,
 * año, dia y mes del trabajo, y un bloque de cuatro cifras— pero ese ultimo
 * bloque cambia de significado.
 *
 * En el sistema anterior era la **hora** de creacion (HHMM), y ahi estaba el
 * problema: dos planes registrados en el mismo minuto salian con el mismo
 * codigo. Pasaba a menudo — 3 722 planes tenian solo 3 526 codigos distintos, y
 * hubo dias con cuatro planes compartiendo codigo. Buscar uno devolvia varios
 * trabajos y no habia forma de saber cual se firmo, que en un documento de
 * seguridad que puede acabar ante un inspector no es un detalle.
 *
 * Ahora es un **correlativo del dia**: el primer plan del 6 de agosto es el
 * 0001, el segundo el 0002. Misma longitud, imposible que choque, y se lee
 * mejor. La hora no se pierde: sigue en `created_at`.
 */
class WorkPlanCodeGenerator
{
    /** Cuantos intentos antes de rendirse si dos personas graban a la vez. */
    private const INTENTOS = 25;

    /**
     * Codigo siguiente para un plan de ese pais, ese workspace y ese dia.
     *
     * La carrera existe: dos supervisores dando de alta un plan a la vez leen
     * el mismo correlativo. Por eso no basta con contar — se comprueba que el
     * candidato no exista y, si existe, se prueba el siguiente. El indice unico
     * de la base es la ultima palabra.
     */
    public function siguiente(int $countryId, ?int $tenantId, Carbon|string|null $fecha = null): string
    {
        $dia = $fecha ? Carbon::parse($fecha) : Carbon::today();
        $prefijo = $this->prefijo($countryId, $dia);

        for ($i = 0; $i < self::INTENTOS; $i++) {
            $usados = $this->correlativosUsados($prefijo, $tenantId);
            $siguiente = ($usados === [] ? 0 : max($usados)) + 1 + $i;
            $codigo = $this->componer($prefijo, $siguiente);

            if (! $this->existe($codigo, $tenantId)) {
                return $codigo;
            }
        }

        // 25 intentos fallidos no es contencion, es que algo esta mal. Mejor
        // fallar que devolver un codigo que la base va a rechazar igual.
        throw new \RuntimeException("No se pudo generar un codigo libre para {$prefijo}.");
    }

    /**
     * Renumera de golpe un conjunto de planes, en orden estable.
     *
     * Se usa al migrar: los codigos del sistema anterior venian repetidos y hay
     * que rehacerlos. El orden lo da `legacy_id` (o el id, si no lo hay), asi
     * que dos ejecuciones producen exactamente la misma numeracion.
     *
     * @return int cuantos planes cambiaron de codigo
     */
    public function renumerar(?int $tenantId = null): int
    {
        $cambiados = 0;

        WorkPlan::withTrashed()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('country_id')
            ->orderByRaw('date(coalesce(date_start, created_at))')
            ->orderByRaw('coalesce(legacy_id, id)')
            ->chunkById(500, function ($planes) use (&$cambiados) {
                $contador = [];

                foreach ($planes as $plan) {
                    $dia = Carbon::parse($plan->date_start ?? $plan->created_at);
                    $prefijo = $this->prefijo($plan->country_id, $dia);
                    $clave = $plan->tenant_id . '|' . $prefijo;

                    // Al entrar a un dia nuevo se arranca de lo que ya haya en
                    // base, para que trocear en lotes no reinicie la cuenta.
                    $contador[$clave] ??= $this->maximoEnBase($prefijo, $plan->tenant_id, $plan->id);

                    $codigo = $this->componer($prefijo, ++$contador[$clave]);

                    if ($plan->code !== $codigo) {
                        WorkPlan::withTrashed()->whereKey($plan->id)->update(['code' => $codigo]);
                        $cambiados++;
                    }
                }
            });

        return $cambiados;
    }

    /** `PE26-0608` — pais, año, dia y mes del trabajo. */
    protected function prefijo(int $countryId, Carbon $dia): string
    {
        $iso = Country::withTrashed()->whereKey($countryId)->value('iso_code') ?: 'XX';

        return sprintf('%s%s-%s', strtoupper($iso), $dia->format('y'), $dia->format('dm'));
    }

    protected function componer(string $prefijo, int $numero): string
    {
        return $prefijo . '-' . str_pad((string) $numero, 4, '0', STR_PAD_LEFT);
    }

    /** Correlativos ya usados ese dia, leidos del propio codigo. */
    protected function correlativosUsados(string $prefijo, ?int $tenantId): array
    {
        return WorkPlan::withTrashed()
            ->where('code', 'like', $prefijo . '-%')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->pluck('code')
            ->map(fn ($c) => (int) substr($c, strlen($prefijo) + 1))
            ->filter()
            ->all();
    }

    /** Como el anterior, ignorando el plan que se esta renumerando. */
    protected function maximoEnBase(string $prefijo, ?int $tenantId, int $exceptoId): int
    {
        $codigos = WorkPlan::withTrashed()
            ->where('code', 'like', $prefijo . '-%')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('id', '<', $exceptoId)
            ->pluck('code')
            ->map(fn ($c) => (int) substr($c, strlen($prefijo) + 1))
            ->filter();

        return $codigos->isEmpty() ? 0 : $codigos->max();
    }

    protected function existe(string $codigo, ?int $tenantId): bool
    {
        return WorkPlan::withTrashed()
            ->whereRaw('UPPER(code) = UPPER(?)', [$codigo])
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->exists();
    }
}
