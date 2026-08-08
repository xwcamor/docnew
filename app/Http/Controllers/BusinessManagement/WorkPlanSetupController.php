<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRule;
use App\Models\FormTemplate;
use App\Models\Person;
use App\Models\WorkPlan;
use App\Models\WorkPlanApproval;
use App\Models\WorkPlanPerson;
use App\Services\BusinessManagement\WorkPlanSetupService;
use App\Support\LikeQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Composición del plan desde su ficha: cuadrilla, formatos exigidos y
 * aprobadores.
 *
 * Todo cuelga de `work_plans.edit` (ver routes/business_management.php): armar
 * el plan es trabajo del supervisor. El usuario de campo tiene
 * `form_submissions.*` y con eso llena y firma, pero no decide quién entra a la
 * obra ni qué formatos se exigen.
 *
 * Cada acción vuelve a la ficha con un flash. Las negativas (quitar a quien ya
 * firmó, quitar un formato lleno) llegan como DomainException desde el servicio
 * con el mensaje ya traducido: aquí solo se convierte en flash de error.
 */
class WorkPlanSetupController extends Controller
{
    public function __construct(private readonly WorkPlanSetupService $armado)
    {
    }

    // ── Cuadrilla ────────────────────────────────────────────────────────────

    /**
     * Buscador de personas para asignar. Son 228 y crecen: no se manda el
     * padrón entero a la ficha, se busca por nombre, apellido o documento.
     * Devuelve JSON porque alimenta un select remoto, no una página.
     *
     * `exclude_assigned` lo usa el selector de la cuadrilla, que no puede
     * ofrecer a quien ya está dentro (hay índice único). El de aprobadores NO
     * lo manda: el supervisor que firma suele salir también en la cuadrilla.
     */
    public function personCandidates(Request $request, WorkPlan $workPlan): JsonResponse
    {
        $q = trim((string) $request->get('q', ''));
        $isPgsql = config('database.default') === 'pgsql';

        $personas = Person::query()
            ->where('is_active', true)
            ->when($request->boolean('exclude_assigned'),
                fn ($query) => $query->whereNotIn('id', $workPlan->people()->pluck('person_id')))
            ->when($q !== '', function ($query) use ($q, $isPgsql) {
                $needle = LikeQuery::contains($q);
                $query->where(function ($qq) use ($needle, $isPgsql) {
                    foreach (['name', 'lastname', 'num_doc'] as $col) {
                        if ($isPgsql) {
                            $qq->orWhereRaw("unaccent(lower(people.{$col})) LIKE unaccent(lower(?))", [$needle]);
                        } else {
                            $qq->orWhereRaw("people.{$col} LIKE ? ESCAPE '\\'", [$needle]);
                        }
                    }
                });
            })
            ->orderBy('lastname')
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'slug', 'name', 'lastname', 'num_doc', 'doc_type']);

        return response()->json([
            'people' => $personas->map(fn ($p) => [
                'slug'    => $p->slug,
                'name'    => trim($p->name . ' ' . $p->lastname),
                'num_doc' => $p->num_doc,
            ])->all(),
        ]);
    }

    public function addPerson(Request $request, WorkPlan $workPlan): RedirectResponse
    {
        $datos = $request->validate(['person_slug' => ['required', 'string', 'size:22']]);
        $persona = Person::where('slug', $datos['person_slug'])->firstOrFail();

        return $this->run($workPlan, fn () => $this->armado->addPerson($workPlan, $persona),
            __('work_plans.crew_added', ['name' => $persona->full_name]));
    }

    public function removePerson(WorkPlan $workPlan, WorkPlanPerson $workPlanPerson): RedirectResponse
    {
        return $this->run($workPlan, fn () => $this->armado->removePerson($workPlan, $workPlanPerson),
            __('work_plans.crew_removed'));
    }

    // ── Formatos ─────────────────────────────────────────────────────────────

    public function addForm(Request $request, WorkPlan $workPlan): RedirectResponse
    {
        $datos = $request->validate([
            'form_template_slug' => ['required', 'string', 'size:22'],
            'is_required'        => ['nullable', 'boolean'],
        ]);
        $plantilla = FormTemplate::where('slug', $datos['form_template_slug'])->firstOrFail();

        return $this->run($workPlan,
            fn () => $this->armado->addFormTemplate($workPlan, $plantilla, $request->boolean('is_required', true)),
            __('work_plans.form_added', ['code' => $plantilla->code]));
    }

    public function removeForm(WorkPlan $workPlan, FormTemplate $formTemplate): RedirectResponse
    {
        return $this->run($workPlan, fn () => $this->armado->removeFormTemplate($workPlan, $formTemplate),
            __('work_plans.form_removed', ['code' => $formTemplate->code]));
    }

    // ── Aprobaciones ─────────────────────────────────────────────────────────

    public function addApproval(Request $request, WorkPlan $workPlan): RedirectResponse
    {
        $datos = $request->validate([
            'approval_rule_id' => ['required', 'integer'],
            'person_slug'      => ['nullable', 'string', 'size:22'],
            'is_required'      => ['nullable', 'boolean'],
        ]);

        $regla = ApprovalRule::where('is_active', true)->findOrFail($datos['approval_rule_id']);
        $persona = ! empty($datos['person_slug'])
            ? Person::where('slug', $datos['person_slug'])->firstOrFail()
            : null;

        return $this->run($workPlan,
            fn () => $this->armado->addApproval($workPlan, $regla, $persona, $request->boolean('is_required', (bool) $regla->is_required)),
            __('work_plans.approval_added'));
    }

    public function removeApproval(WorkPlan $workPlan, WorkPlanApproval $workPlanApproval): RedirectResponse
    {
        return $this->run($workPlan, fn () => $this->armado->removeApproval($workPlan, $workPlanApproval),
            __('work_plans.approval_removed'));
    }

    // ── Apoyo ────────────────────────────────────────────────────────────────

    /**
     * Ejecuta la operación y vuelve a la ficha. Una negativa del servicio no es
     * un error del sistema: es una regla del negocio y se cuenta como tal.
     */
    private function run(WorkPlan $workPlan, callable $accion, string $exito): RedirectResponse
    {
        try {
            $accion();
        } catch (\DomainException $e) {
            return $this->backToPlan($workPlan)->with('error', $e->getMessage());
        }

        return $this->backToPlan($workPlan)->with('success', $exito);
    }

    private function backToPlan(WorkPlan $workPlan): RedirectResponse
    {
        return redirect()->route('business_management.work_plans.show', $workPlan->slug);
    }
}
