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
                'require_report_approval' => (bool) $tenant->require_report_approval,
                'notify_approval_by_email' => (bool) $tenant->notify_approval_by_email,
            ],
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

    public function update(Request $request)
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant !== null, 404);

        // Los usuarios-firmantes DEBEN pertenecer al mismo workspace (anti
        // cross-tenant). Cada slot lleva cargo; sin usuario requiere nombre.
        $data = $request->validate([
            'address'           => ['nullable', 'string', 'max:255'],
            'report_disclaimer' => ['nullable', 'string', 'max:2000'],
            'require_report_approval' => ['nullable', 'boolean'],
            'notify_approval_by_email' => ['nullable', 'boolean'],
            'signers'           => ['nullable', 'array', 'max:8'],
            'signers.*.user_id' => [
                'nullable', 'integer',
                \Illuminate\Validation\Rule::exists('users', 'id')->where('tenant_id', $tenant->id),
            ],
            'signers.*.name'    => ['nullable', 'string', 'max:120', 'required_without:signers.*.user_id'],
            'signers.*.title'   => ['required', 'string', 'max:120'],
            'signers.*.relation'=> ['nullable', \Illuminate\Validation\Rule::in(\App\Models\ReportSigner::RELATIONS)],
        ]);

        $tenant->update(\Illuminate\Support\Arr::only($data, ['address', 'report_disclaimer']) + [
            'require_report_approval'  => (bool) ($data['require_report_approval'] ?? false),
            'notify_approval_by_email' => (bool) ($data['notify_approval_by_email'] ?? false),
        ]);

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
