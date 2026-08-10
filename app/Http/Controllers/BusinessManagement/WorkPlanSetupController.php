<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRule;
use App\Models\FormTemplate;
use App\Models\ApproverRole;
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
     * Búsqueda de una persona **por su documento**. No es un listado.
     *
     * Así se hace en obra y así lo hacía el sistema anterior: se escanea o se
     * teclea el DNI («Escanea ó digite DNI»), y si aparece se enseña el nombre.
     * Nunca se despliega el padrón — ni al abrir el desplegable, ni con la
     * búsqueda vacía, ni con dos dígitos.
     *
     * Tres reglas, y las tres vienen de un fallo mío:
     *
     * 1. **Menos de `minimoDocumento()` caracteres no devuelve nada.** Antes,
     *    con la búsqueda vacía, esto contestaba con 25 personas y su DNI
     *    completo, y el selector lo llamaba solo al recibir el foco. Era un
     *    volcado del padrón.
     * 2. **Se busca por documento, no por nombre.** Buscar por nombre convierte
     *    cualquier apellido común en un listado con documentos al lado.
     * 3. **El documento vuelve enmascarado** salvo permiso. Lo que se enseña
     *    para confirmar que es la persona correcta es el nombre.
     *
     * `exclude_assigned` lo usa el selector de la cuadrilla, que no puede
     * ofrecer a quien ya está dentro (hay índice único). El de aprobadores NO
     * lo manda: el supervisor que firma suele salir también en la cuadrilla.
     */
    /**
     * Cuando la busqueda empieza a contestar. Por debajo, nada.
     *
     * Estaba fijo en 8 —el DNI peruano— y en la v1 es un ajuste: `settings.
     * num_doc_minimum`, por pais, sembrado en **7** para los siete. O sea que
     * aqui era mas estricto que alla y un documento de siete caracteres no se
     * podia ni buscar.
     *
     * Aqui el ajuste es uno solo y no por pais, porque el 100 % de los planes
     * son de Peru (ver docs/PENDIENTES.md #8). Si algun dia entra otro pais con
     * otra longitud, esto es lo que hay que partir por pais.
     */
    public const MINIMO_DOCUMENTO_POR_DEFECTO = 7;

    protected function minimoDocumento(): int
    {
        $valor = \App\Models\Setting::getInt('docufiz.num_doc_minimum');

        // Un cero o un negativo dejaria que la busqueda vacia devolviera el
        // padron entero, que es justo el fallo que este minimo existe para
        // tapar. Un ajuste mal puesto no puede abrir esa puerta.
        return $valor >= 1 ? $valor : self::MINIMO_DOCUMENTO_POR_DEFECTO;
    }

    public function personCandidates(Request $request, WorkPlan $workPlan): JsonResponse
    {
        $q = trim((string) $request->get('q', ''));
        $minimo = $this->minimoDocumento();

        // Sin documento suficiente no hay búsqueda. Se contesta con la lista
        // vacía y el mínimo, para que la pantalla pueda decir qué falta en vez
        // de quedarse en blanco sin explicación.
        if (mb_strlen($q) < $minimo) {
            return response()->json([
                'people'  => [],
                'minimum' => $minimo,
                'partial' => true,
            ]);
        }

        $isPgsql = config('database.default') === 'pgsql';

        $personas = Person::query()
            ->where('is_active', true)
            ->when($request->boolean('exclude_assigned'),
                fn ($query) => $query->whereNotIn('id', $workPlan->people()->pluck('person_id')))
            // Un supervisor HSE lo firma alguien con ese rol, no cualquiera.
            //
            // El sistema anterior tenía **tres endpoints distintos** para el
            // selector de aprobador —`workers/select2_list`,
            // `supervisors/select2_list`, `hse_supervisors/select2_list`— o sea
            // que la lista dependía del rol que había que firmar. Aquí devolvía
            // a cualquier persona activa: se podía poner al ayudante a firmar
            // como supervisor de seguridad.
            //
            // Esto es sólo el filtro de la búsqueda; la regla que vale está en
            // WorkPlanSetupService::assignApprover(), porque una petición hecha
            // a mano no pasa por este selector.
            // El representante sale de los trabajadores de ESTE plan y, ademas,
            // de los que YA FIRMARON: esa firma es la que vale, asi que
            // designar a quien no ha firmado dejaria al plan esperando una
            // responsabilidad que nadie ha asumido. Ver
            // WorkPlanSetupService::designarRepresentante().
            ->when($request->boolean('representante'),
                fn ($query) => $query->whereIn('id', $workPlan->people()
                    ->where(fn ($q) => $q->where('is_approved', true)->orWhereHas('signatureEvents'))
                    ->pluck('person_id')))
            // Los aprobadores no estan en la cuadrilla: lo que se exige es el rol.
            ->when($request->filled('role'),
                fn ($query) => $query->whereHas(
                    'roles',
                    fn ($q) => $q->where('role', $request->string('role'))->where('is_active', true),
                ))
            ->when(true, function ($query) use ($q, $isPgsql) {
                $needle = LikeQuery::contains($q);
                $isPgsql
                    ? $query->whereRaw('unaccent(lower(people.num_doc)) LIKE unaccent(lower(?))', [$needle])
                    : $query->whereRaw("people.num_doc LIKE ? ESCAPE '\\'", [$needle]);
            })
            ->orderBy('lastname')
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'slug', 'name', 'lastname', 'num_doc', 'doc_type']);

        // ¿Lo que se tecleó ES un documento, y de una sola persona?
        //
        // Es lo que permite que la pantalla no tenga desplegable: en obra se
        // escanea el DNI y la persona entra sola. Un desplegable ahí obliga a
        // teclear el documento entero y ADEMÁS elegir de una lista de un solo
        // elemento, con guantes y a pleno sol.
        //
        // Se exige coincidencia EXACTA y única: «4001» encaja con veinte
        // documentos y no se puede adivinar cuál. Mientras no sea exacta no se
        // añade nada — la búsqueda por parecido sigue existiendo para decir si
        // hay alguien o no, pero no decide por nadie.
        $exacta = $personas->filter(fn ($p) => $p->num_doc === $q);

        return response()->json([
            'people' => $personas->map(fn ($p) => [
                'slug'    => $p->slug,
                'name'    => $p->list_name,
                'num_doc' => $p->safe_num_doc,
            ])->all(),
            'exact' => $exacta->count() === 1
                ? ['slug' => $exacta->first()->slug, 'name' => $exacta->first()->list_name]
                : null,
            'minimum' => $minimo,
            'partial' => false,
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

    /**
     * Asigna quién firma una aprobación pendiente.
     *
     * No hay contraparte que la borre, a propósito: las aprobaciones las crea
     * la regla del país al nacer el plan y pertenecen al flujo, no al plan.
     */
    public function assignApprover(Request $request, WorkPlan $workPlan, WorkPlanApproval $workPlanApproval): RedirectResponse
    {
        $datos = $request->validate(['person_slug' => ['required', 'string', 'size:22']]);

        $persona = Person::where('slug', $datos['person_slug'])->where('is_active', true)->firstOrFail();

        return $this->run($workPlan,
            fn () => $this->armado->assignApprover($workPlan, $workPlanApproval, $persona),
            __('work_plans.approval_assigned', ['name' => $persona->list_name]));
    }

    /**
     * Designa quien responde por la cuadrilla.
     *
     * No es una aprobacion: es una columna del plan. Ver la migracion
     * `el_representante_no_es_una_aprobacion` para el porque.
     */
    public function designarRepresentante(Request $request, WorkPlan $workPlan): RedirectResponse
    {
        $datos = $request->validate(['person_slug' => ['required', 'string', 'size:22']]);

        $persona = Person::where('slug', $datos['person_slug'])->where('is_active', true)->firstOrFail();

        return $this->run($workPlan,
            fn () => $this->armado->designarRepresentante($workPlan, $persona),
            __('work_plans.representative_assigned', ['name' => $persona->list_name]));
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
