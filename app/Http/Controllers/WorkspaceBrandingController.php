<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * WorkspaceBrandingController — "Mi workspace" del admin logueado.
 *
 * Autoservicio del membrete de informes: el admin de cada workspace configura
 * SU dirección, disclaimer legal y aprobador de informes sin depender del
 * super. Mismo espíritu que ProfileController: solo opera sobre el tenant
 * propio (el de auth), nunca sobre otros.
 *
 * El nombre, logo, plan y estado del workspace siguen siendo super-only
 * (módulo Tenants): son identidad/facturación, no membrete.
 */
class WorkspaceBrandingController extends Controller
{
    public function edit(Request $request)
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant !== null, 404);

        $tenant->loadMissing('reportSigners.user:id,name,signature,auto_sign_reports');

        return inertia('Workspace/Branding', [
            'workspace' => [
                'name'              => $tenant->name,
                'logo_url'          => $tenant->logo_url,
                'address'           => $tenant->address,
                'report_disclaimer' => $tenant->report_disclaimer,
                'company_id'        => $tenant->company_id,
            ],
            // Las empresas del catalogo, para decir cual de ellas es este
            // workspace. Ver `companySugerida()`.
            'companyOptions'   => $this->companyOptions(),
            'companySugerida'  => $tenant->company_id ? null : $this->companySugerida($tenant),
            // Flujo de firmas del workspace (N slots con cargo). Cada fila trae
            // el estado de la firma para que el admin VEA si saldrá estampada.
            'signers' => $tenant->reportSigners->map(fn ($s) => [
                'id'       => $s->id,
                'user_id'  => $s->user_id,
                'name'     => $s->name,
                'title'    => $s->title,
                'relation' => $s->relation ?: 'approved',
                'status'  => $s->user_id
                    ? (!$s->user || empty($s->user->signature) ? 'no_signature'
                        : (!$s->user->auto_sign_reports ? 'no_autosign' : 'ready'))
                    : 'external',
            ])->values(),
            // Usuarios activos del workspace, candidatos a firmantes.
            'users' => \App\Models\User::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    /** Las empresas del workspace, para el selector de «cual soy yo». */
    protected function companyOptions(): array
    {
        return \App\Models\Company::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'complete_name'])
            ->map(fn ($c) => [
                'value' => $c->id,
                'label' => $c->complete_name && $c->complete_name !== $c->name
                    ? "{$c->name} — {$c->complete_name}"
                    : $c->name,
            ])
            ->all();
    }

    /**
     * Cual de las empresas se parece mas al nombre del workspace.
     *
     * Es una propuesta, no una decision: la pantalla la deja preseleccionada y
     * el admin confirma. Adivinarlo y guardarlo sin preguntar seria peor que no
     * hacer nada — si se falla, el selector de aprobadores empieza a ofrecer a
     * la gente equivocada y nadie se entera hasta que alguien firma un plan que
     * no le tocaba.
     *
     * `similar_text` y no una coincidencia exacta porque el workspace se llama
     * como la organizacion y la empresa lleva ademas su forma societaria:
     * «Acme» contra «Acme Servicios Generales S.A.C.» no son iguales pero se
     * parecen bastante. Por debajo del 55 % no se propone nada.
     */
    protected function companySugerida(\App\Models\Tenant $tenant): ?int
    {
        $mejor = null;
        $mejorParecido = 0.0;

        foreach (\App\Models\Company::where('is_active', true)->get(['id', 'name', 'complete_name']) as $empresa) {
            foreach ([$empresa->name, $empresa->complete_name] as $comoSeLlama) {
                similar_text(
                    mb_strtolower((string) $tenant->name),
                    mb_strtolower((string) $comoSeLlama),
                    $parecido,
                );

                if ($parecido > $mejorParecido) {
                    $mejorParecido = $parecido;
                    $mejor = $empresa->id;
                }
            }
        }

        return $mejorParecido >= 55 ? $mejor : null;
    }

    public function update(Request $request)
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant !== null, 404);

        // Los usuarios-firmantes DEBEN pertenecer al mismo workspace (anti
        // cross-tenant). Cada slot lleva cargo; sin usuario requiere nombre.
        $data = $request->validate([
            'address'           => ['nullable', 'string', 'max:255'],
            // La empresa propia sale del catalogo de ESTE workspace: el scope
            // global de `Company` ya lo garantiza, pero la regla lo deja
            // escrito para quien lea solo esto.
            'company_id'        => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'report_disclaimer' => ['nullable', 'string', 'max:2000'],
            'signers'           => ['nullable', 'array', 'max:8'],
            'signers.*.user_id' => [
                'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('users', 'id')->where('tenant_id', $tenant->id),
            ],
            'signers.*.name'    => ['nullable', 'string', 'max:120', 'required_without:signers.*.user_id'],
            'signers.*.title'   => ['required', 'string', 'max:120'],
            // Sin esta regla `relation` NO llegaba: Laravel descarta las claves
            // de un array validado que no tienen regla propia, así que la
            // relación que elegía el admin ("Revisado por", "Autorizado por"…)
            // se perdía y TODOS los firmantes se guardaban como "approved".
            // El informe salía con un rótulo distinto del que se configuró.
            'signers.*.relation' => [
                'nullable', 'string',
                \Illuminate\Validation\Rule::in(\App\Models\ReportSigner::RELATIONS),
            ],
        ]);

        $tenant->update(\Illuminate\Support\Arr::only($data, ['address', 'report_disclaimer', 'company_id']));

        // Sync de slots: se reemplaza la lista completa (orden = posición).
        $tenant->reportSigners()->delete();
        foreach (array_values($data['signers'] ?? []) as $i => $s) {
            $tenant->reportSigners()->create([
                'user_id'    => $s['user_id'] ?? null,
                'name'       => ($s['user_id'] ?? null) ? null : ($s['name'] ?? null),
                'title'      => $s['title'],
                'relation'   => $s['relation'] ?? 'approved',
                'sort_order' => $i + 1,
            ]);
        }

        return back()->with('success', __('global.updated_success'));
    }

    /**
     * Cambia el logo del workspace propio (sale en el sidebar, informes y
     * portal compartido). Mismo límite/storage que el form del super
     * (TenantService maneja borrado del anterior + guardado).
     */
    public function updateLogo(Request $request, \App\Services\SystemManagement\TenantService $service)
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant !== null, 404);

        $maxLogoKb = \App\Models\Setting::getInt('uploads.tenant_logo_max_mb', 2) * 1024;
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . $maxLogoKb],
        ]);

        $service->update($tenant, [], $request->file('logo'));

        return back()->with('success', __('global.updated_success'));
    }
}
