<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Transformer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Lista transformadores ACTIVOS (no borrados) con serie (o tag) duplicada,
 * para decidir a mano cuál conservar antes de endurecer la unicidad.
 *
 *   php artisan transformers:find-duplicates                 # series repetidas por workspace
 *   php artisan transformers:find-duplicates --tag           # tags repetidos por subestación
 *   php artisan transformers:find-duplicates --include-deleted
 *
 * Salta el scope de tenant (ve TODOS los workspaces) pero respeta soft-delete
 * por defecto. Los IDs son los reales de la BD actual (no los del sistema viejo).
 */
class FindDuplicateTransformersCommand extends Command
{
    protected $signature = 'transformers:find-duplicates
        {--tag : Detecta tags repetidos por subestación en vez de series por workspace}
        {--include-deleted : Incluye también los registros borrados (soft-deleted)}';

    protected $description = 'Lista transformadores activos con serie (o tag) duplicada';

    public function handle(): int
    {
        $byTag = (bool) $this->option('tag');

        $query = Transformer::withoutGlobalScopes()
            ->withCount(['chromatographicals', 'furanos', 'fiquis', 'fpots']);

        if (! $this->option('include-deleted')) {
            $query->whereNull('deleted_at');
        }

        if ($byTag) {
            $query->whereNotNull('tag')->whereNotIn('tag', ['', '-']);
        } else {
            $query->whereNotNull('serial')->where('serial', '<>', '');
        }

        $rows = $query->get();

        // Nombres de cliente sin scope de tenant (para que no salgan vacíos
        // al listar varios workspaces a la vez).
        $customers = Customer::withoutGlobalScopes()
            ->whereIn('id', $rows->pluck('customer_id')->filter()->unique())
            ->pluck('name', 'id');

        // Agrupa: por (tenant, serie) o por (subestación, tag). Clave normalizada
        // solo en espacios (no en mayúsculas) para no fusionar de más.
        $groups = $rows->groupBy(function (Transformer $t) use ($byTag) {
            return $byTag
                ? $t->customer_substation_id . '|tag|' . trim((string) $t->tag)
                : $t->tenant_id . '|ser|' . trim((string) $t->serial);
        })->filter(fn (Collection $g) => $g->count() > 1)
          ->sortByDesc(fn (Collection $g) => $g->count());

        if ($groups->isEmpty()) {
            $this->info($byTag
                ? 'No hay tags duplicados activos por subestación. Limpio.'
                : 'No hay series duplicadas activas por workspace. Limpio.');
            return self::SUCCESS;
        }

        $what     = $byTag ? 'TAG' : 'SERIE';
        $scope    = $byTag ? 'subestación' : 'workspace';
        $totalDup = $groups->sum(fn (Collection $g) => $g->count());

        $this->warn(sprintf(
            '%d %s%s duplicad%s (%d registros en total) por %s:',
            $groups->count(),
            $what,
            $groups->count() === 1 ? '' : 'S',
            $byTag ? ($groups->count() === 1 ? 'o' : 'os') : ($groups->count() === 1 ? 'a' : 'as'),
            $totalDup,
            $scope,
        ));
        $this->newLine();

        foreach ($groups as $group) {
            /** @var Transformer $first */
            $first  = $group->first();
            $header = $byTag
                ? sprintf('TAG "%s"  ·  subestación #%s  ·  %d equipos', trim((string) $first->tag), $first->customer_substation_id ?? '—', $group->count())
                : sprintf('SERIE "%s"  ·  workspace #%s  ·  %d equipos', trim((string) $first->serial), $first->tenant_id ?? '—', $group->count());

            $this->line("<options=bold>{$header}</>");

            $table = $group->map(function (Transformer $t) {
                $samples = $t->chromatographicals_count + $t->furanos_count + $t->fiquis_count + $t->fpots_count;
                return [
                    'id'        => $t->id,
                    'serie'     => $t->serial,
                    'tag'       => $t->tag,
                    'cliente'   => $customers[$t->customer_id] ?? '—',
                    'HI'        => $t->health_index !== null ? round($t->health_index) : '—',
                    'muestras'  => $samples,
                    'cro/fur/fiq/fpot' => "{$t->chromatographicals_count}/{$t->furanos_count}/{$t->fiquis_count}/{$t->fpots_count}",
                    'creado'    => optional($t->created_at)->format('Y-m-d'),
                    'borrado'   => $t->deleted_at ? 'SÍ' : '',
                ];
            })->sortByDesc('muestras')->values()->all();

            $this->table(
                ['ID', 'Serie', 'Tag', 'Cliente', 'HI', 'Muestras', 'cro/fur/fiq/fpot', 'Creado', 'Borr.'],
                $table,
            );
            $this->newLine();
        }

        $this->line("Sugerencia: conservá el de MÁS muestras y reasigná/elimina los otros.");

        return self::SUCCESS;
    }
}
