<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Person;
use App\Models\WorkPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Buscador global de la barra superior.
 *
 * Busca las tres cosas por las que se pregunta en obra: un plan de trabajo (por
 * su codigo u orden de servicio), una empresa contratista (por nombre o RUC) y
 * una persona (por nombre o documento).
 *
 * Seguridad: cada modelo ya filtra por workspace (BelongsToTenant); ademas se
 * respeta el permiso de vista de cada modulo, asi que quien no puede ver
 * personas tampoco las encuentra por aqui.
 */
class SearchController extends Controller
{
    /** Cuantas coincidencias por grupo. Es un buscador rapido, no un listado. */
    private const LIMITE = 6;

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['work_plans' => [], 'companies' => [], 'people' => []]);
        }

        $user = $request->user();
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $term = '%' . $q . '%';

        return response()->json([
            'work_plans' => $user->can('work_plans.view') ? $this->planes($like, $term) : [],
            'companies'  => $user->can('companies.view') ? $this->empresas($like, $term) : [],
            'people'     => $user->can('people.view') ? $this->personas($like, $term) : [],
        ]);
    }

    protected function planes(string $like, string $term): array
    {
        return WorkPlan::query()
            ->where(fn ($w) => $w
                ->where('code', $like, $term)
                ->orWhere('num_os', $like, $term)
                ->orWhere('description', $like, $term)
                ->orWhereHas('company', fn ($c) => $c->where('name', $like, $term)))
            ->with('company:id,name')
            ->latest('date_start')
            ->limit(self::LIMITE)
            ->get(['id', 'slug', 'code', 'num_os', 'company_id', 'date_start', 'is_done'])
            ->map(fn ($p) => [
                'slug'   => $p->slug,
                'label'  => $p->code,
                'sub'    => trim(($p->company?->name ?? '') . ' · ' . ($p->date_start?->format('d/m/Y') ?? '')),
                'closed' => (bool) $p->is_done,
            ])
            ->all();
    }

    protected function empresas(string $like, string $term): array
    {
        return Company::query()
            ->where(fn ($w) => $w
                ->where('name', $like, $term)
                ->orWhere('complete_name', $like, $term)
                ->orWhere('num_doc', $like, $term))
            ->orderBy('name')
            ->limit(self::LIMITE)
            ->get(['id', 'slug', 'name', 'num_doc'])
            ->map(fn ($c) => ['slug' => $c->slug, 'label' => $c->name, 'sub' => $c->num_doc])
            ->all();
    }

    protected function personas(string $like, string $term): array
    {
        return Person::query()
            ->where(fn ($w) => $w
                ->where('name', $like, $term)
                ->orWhere('lastname', $like, $term)
                ->orWhere('num_doc', $like, $term))
            ->orderBy('lastname')
            ->limit(self::LIMITE)
            ->get(['id', 'slug', 'name', 'lastname', 'doc_type', 'num_doc'])
            ->map(fn ($p) => [
                'slug'  => $p->slug,
                'label' => trim("{$p->name} {$p->lastname}"),
                // El buscador global era la ultima puerta por la que salia el
                // documento entero: el resto de pantallas ya leen `safe_num_doc`.
                // Se busca contra `num_doc` (arriba) y se pinta el tapado.
                'sub'   => trim("{$p->doc_type} {$p->safe_num_doc}"),
            ])
            ->all();
    }
}
