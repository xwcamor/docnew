<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * ProfileController — "Mi perfil" del usuario logueado.
 *
 * Distinto del UserController de admin: aquí el usuario solo edita SU propia
 * info, no la de otros. No requiere permiso especial — auth middleware basta.
 *
 * Campos editables por el usuario:
 *   - name (display name)
 *
 * Inmutables desde aquí:
 *   - email (es la identidad — cambiarlo requiere flujo de verificación)
 *   - tenant_id, locale_id, country_id (admin-managed)
 *   - is_active, roles, permissions (admin-managed)
 */
class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $user->loadMissing(['country', 'tenant']);

        return inertia('Profile/Show', [
            'profile' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'photo'      => $user->photo,
                'photo_url'  => $user->photo_url,
                'is_active'  => $user->is_active,
                'tenant'     => $user->tenant ? ['id' => $user->tenant->id, 'name' => $user->tenant->name, 'timezone' => $user->tenant->timezone] : null,
                'country'    => $user->country ? ['id' => $user->country->id, 'name' => $user->country->name] : null,
                'roles'      => $user->getRoleNames(),
                'created_at' => $user->created_at?->toIso8601String(),
                'has_password' => !empty($user->password),
                'has_signature' => !empty($user->signature),
                'auto_sign_reports' => (bool) $user->auto_sign_reports,
                // TZ propio (puede ser null si el user hereda del workspace o
                // país). El frontend lo distingue del TZ "efectivo" (siempre
                // un string) para mostrar la opción "heredar".
                'timezone'   => $user->timezone,
            ],
        ]);
    }

    /**
     * Update name (and photo si se sumó). Email no se toca aquí — mantiene la
     * identidad estable y el audit_log limpio.
     */
    public function update(Request $request)
    {
        // El TZ se valida contra la lista que Tz::availableTimezones()
        // construye (la misma que ofrece el dropdown). null = heredar del
        // workspace; cualquier string fuera de la lista = rechazo.
        $allowedTimezones = \App\Support\Tz::availableTimezones();

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'timezone' => ['nullable', 'string', 'in:' . implode(',', $allowedTimezones)],
        ]);

        $user = $request->user();
        $user->name     = $data['name'];
        // `timezone` puede llegar como '' (campo vacio) o null — ambos => null
        // para que el user vuelva a heredar del workspace.
        $user->timezone = $data['timezone'] ?: null;
        $user->save();

        // Limpiamos la cache de Tz para que la próxima request resuelva el
        // valor nuevo. Sin esto, la página siguiente seguiría mostrando el
        // TZ viejo hasta que el proceso PHP termine.
        \App\Support\Tz::forget($user->id);

        return back()->with('success', __('global.updated_success'));
    }

    /**
     * Preferencias de apariencia (cross-device, en BD): esquema de color +
     * posición del menú. Listas cerradas; las aplica AppLayout al cargar.
     */
    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'ui_scheme'    => ['required', 'string', 'in:sap,slate,emerald,indigo,red,amber,teal,contrast'],
            'nav_position' => ['required', 'string', 'in:top,side,bottom'],
        ]);

        $request->user()->forceFill([
            'ui_scheme'    => $data['ui_scheme'],
            'nav_position' => $data['nav_position'],
        ])->save();

        return back()->with('success', __('global.updated_success'));
    }

    /**
     * Aceptación REGISTRADA de Términos y Privacidad (LPDP Ley 29733: el
     * consentimiento debe ser demostrable). Guarda versión + timestamp en el
     * usuario y deja rastro en el audit log (quién, cuándo, desde qué IP).
     * El modal del front la exige cuando la versión vigente cambió.
     */
    public function acceptTerms(Request $request)
    {
        $user = $request->user();
        $version = (string) \App\Models\Setting::get('legal.terms_version', '1.0');

        $user->forceFill([
            'terms_accepted_version' => $version,
            'terms_accepted_at'      => now(),
        ])->save();

        \App\Models\AuditLog::create([
            'user_id'        => $user->id,
            'event'          => 'terms_accepted',
            'auditable_type' => \App\Models\User::class,
            'auditable_id'   => $user->id,
            'module'         => 'users',
            'old_values'     => null,
            'new_values'     => ['version' => $version],
            'url'            => $request->fullUrl(),
            'ip_address'     => $request->ip(),
            'user_agent'     => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back();
    }

    /**
     * Derecho de ACCESO/portabilidad (LPDP): el usuario descarga SUS datos
     * personales en JSON — perfil, aceptaciones legales, firma (si tiene) y
     * su historial de consentimientos. La descarga misma queda auditada.
     */
    public function exportData(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('country:id,name', 'tenant:id,name');
        $localeCode = $user->locale_id ? \App\Models\Locale::find($user->locale_id)?->code : null;

        // Historial de consentimientos del propio usuario (demostrable).
        $consents = \App\Models\AuditLog::where('user_id', $user->id)
            ->whereIn('event', ['terms_accepted', 'report_autosign_granted', 'report_autosign_revoked'])
            ->orderBy('created_at')
            ->get(['event', 'new_values', 'ip_address', 'created_at'])
            ->map(fn ($l) => [
                'event'      => $l->event,
                'detail'     => $l->new_values,
                'ip_address' => $l->ip_address,
                'at'         => $l->created_at?->toIso8601String(),
            ]);

        // Firma manuscrita (es SU dato personal): incluida como data-URI.
        $signature = null;
        if ($user->signature) {
            try {
                $disk = \Illuminate\Support\Facades\Storage::disk('local');
                if ($disk->exists($user->signature)) {
                    $signature = 'data:' . $disk->mimeType($user->signature) . ';base64,' . base64_encode($disk->get($user->signature));
                }
            } catch (\Throwable) {
                // sin firma en el export si falla la lectura
            }
        }

        $data = [
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'name'       => $user->name,
                'email'      => $user->email,
                'timezone'   => $user->timezone,
                'country'    => $user->country?->name,
                'locale'     => $localeCode,
                'workspace'  => $user->tenant?->name,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'legal' => [
                'terms_accepted_version' => $user->terms_accepted_version,
                'terms_accepted_at'      => $user->terms_accepted_at?->toIso8601String(),
            ],
            'signature' => [
                'has_signature'     => !empty($user->signature),
                'auto_sign_reports' => (bool) $user->auto_sign_reports,
                'image'             => $signature,
            ],
            'consent_history' => $consents,
        ];

        \App\Models\AuditLog::create([
            'user_id'        => $user->id,
            'event'          => 'personal_data_exported',
            'auditable_type' => \App\Models\User::class,
            'auditable_id'   => $user->id,
            'module'         => 'users',
            'old_values'     => null,
            'new_values'     => null,
            'url'            => $request->fullUrl(),
            'ip_address'     => $request->ip(),
            'user_agent'     => substr((string) $request->userAgent(), 0, 500),
        ]);

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="datos-personales-' . now()->format('Ymd') . '.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Derecho de CANCELACIÓN (LPDP): el usuario solicita la eliminación de su
     * cuenta. La ejecuta un admin (módulo Users) para mantener trazabilidad;
     * aquí se registra la solicitud (audit) y se notifica a los admins del
     * workspace (super como fallback).
     */
    public function requestDeletion(Request $request)
    {
        $user = $request->user();

        // Idempotente: una solicitud vigente (últimas 24 h) no se duplica ni
        // re-notifica al admin — el usuario ve "ya registrada".
        $already = \App\Models\AuditLog::where('event', 'account_deletion_requested')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->exists();
        if ($already) {
            return back()->with('success', __('profile.deletion_already_requested'));
        }

        \App\Models\AuditLog::create([
            'user_id'        => $user->id,
            'event'          => 'account_deletion_requested',
            'auditable_type' => \App\Models\User::class,
            'auditable_id'   => $user->id,
            'module'         => 'users',
            'old_values'     => null,
            'new_values'     => ['email' => $user->email],
            'url'            => $request->fullUrl(),
            'ip_address'     => $request->ip(),
            'user_agent'     => substr((string) $request->userAgent(), 0, 500),
        ]);

        // Admins del workspace; si no hay, supers. Plomería interna de
        // notificación (no listado visible) → SIN los global scopes que la
        // vaciarían cuando consulta un no-super: 'tenant' (BelongsToTenant
        // filtra tenant_id=X y el super tiene tenant NULL) y HideSuperScope
        // (oculta supers). Con ellos, el caso "único admin pide eliminación"
        // notificaba a una lista VACÍA. whereHas('roles') por nombre = patrón
        // del proyecto (el scope role() de Spatie no se usa aquí).
        $admins = \App\Models\User::withoutGlobalScopes(['tenant', \App\Scopes\HideSuperScope::class])
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->where('tenant_id', $user->tenant_id)
            ->where('id', '!=', $user->id)
            ->get();
        if ($admins->isEmpty()) {
            $admins = \App\Models\User::withoutGlobalScopes(['tenant', \App\Scopes\HideSuperScope::class])
                ->whereHas('roles', fn ($q) => $q->where('name', 'super'))
                ->get();
        }
        \Illuminate\Support\Facades\Notification::send(
            $admins,
            new \App\Notifications\AccountDeletionRequested($user->name, $user->email)
        );

        return back()->with('success', __('profile.deletion_requested'));
    }

    /**
     * Cambia la foto de perfil propia. Mismo storage que las fotos que sube
     * el admin de usuarios (disk public, photos/{slug}) — ver UserService.
     */
    public function updatePhoto(Request $request)
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:2048'],
        ]);

        $user = $request->user();

        if ($user->photo && \Illuminate\Support\Facades\Storage::disk('public')->exists($user->photo)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($user->photo);
        }

        $slug = \Illuminate\Support\Str::slug($user->name) . '-' . uniqid();
        $ext  = strtolower($request->file('photo')->extension() ?: 'jpg');
        $user->photo = $request->file('photo')
            ->storeAs("photos/{$slug}", time() . '_' . \Illuminate\Support\Str::random(12) . '.' . $ext, 'public');
        $user->save();

        return back()->with('success', __('profile.photo_updated'));
    }

    /**
     * Sube la firma manuscrita del usuario (imagen). Va al disk `local`
     * (PRIVADO — una firma no debe tener URL pública): solo se embebe en los
     * PDFs que el propio usuario genera y se previsualiza vía signature().
     */
    public function updateSignature(Request $request)
    {
        $request->validate([
            'signature' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        ]);

        $user = $request->user();

        if ($user->signature && \Illuminate\Support\Facades\Storage::disk('local')->exists($user->signature)) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($user->signature);
        }

        $ext = strtolower($request->file('signature')->extension() ?: 'png');
        $user->signature = $request->file('signature')
            ->storeAs('signatures/' . $user->id, time() . '_' . \Illuminate\Support\Str::random(10) . '.' . $ext, 'local');
        $user->save();

        return back()->with('success', __('profile.signature_updated'));
    }

    /** Vista previa de la firma PROPIA (ruta autenticada; nunca URL pública). */
    public function signature(Request $request)
    {
        $user = $request->user();
        abort_unless($user->signature && \Illuminate\Support\Facades\Storage::disk('local')->exists($user->signature), 404);

        return response()->file(\Illuminate\Support\Facades\Storage::disk('local')->path($user->signature));
    }

    /**
     * Consentimiento de auto-firma como aprobador: el PROPIO usuario autoriza
     * (o revoca) que su firma se estampe automáticamente en los informes del
     * workspace donde está asignado como aprobador, sin aprobación individual.
     * Acto explícito y auditado — esa es la diferencia entre un sello
     * pre-autorizado y una suplantación. Nadie puede activarlo por él.
     */
    public function updateAutoSign(Request $request)
    {
        $data = $request->validate(['auto_sign_reports' => ['required', 'boolean']]);
        $user = $request->user();

        // Activar requiere tener firma cargada (sin firma no hay qué estampar).
        if ($data['auto_sign_reports'] && empty($user->signature)) {
            return back()->withErrors(['auto_sign_reports' => __('profile.autosign_needs_signature')]);
        }

        $user->auto_sign_reports = $data['auto_sign_reports'];
        $user->save();

        // Rastro explícito del consentimiento (quién, cuándo, desde dónde).
        \App\Models\AuditLog::create([
            'user_id'        => $user->id,
            'event'          => $data['auto_sign_reports'] ? 'report_autosign_granted' : 'report_autosign_revoked',
            'auditable_type' => \App\Models\User::class,
            'auditable_id'   => $user->id,
            'module'         => 'users',
            'old_values'     => null,
            'new_values'     => ['auto_sign_reports' => $data['auto_sign_reports']],
            'url'            => $request->fullUrl(),
            'ip_address'     => $request->ip(),
            'user_agent'     => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back()->with('success', __('global.updated_success'));
    }

    /** Quita la firma del usuario. */
    public function removeSignature(Request $request)
    {
        $user = $request->user();

        if ($user->signature && \Illuminate\Support\Facades\Storage::disk('local')->exists($user->signature)) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($user->signature);
        }
        $user->signature = null;
        // Sin firma no puede haber auto-firma: se revoca junto con la imagen.
        $user->auto_sign_reports = false;
        $user->save();

        return back()->with('success', __('profile.signature_removed'));
    }

    /**
     * Cambio de password. Pide current + new + confirm. Si el usuario nunca
     * tuvo password (Google login), `current_password` no se requiere.
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();
        $hasPassword = !empty($user->password);

        $data = $request->validate([
            'current_password' => $hasPassword ? 'required|string' : 'nullable',
            'password'         => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if ($hasPassword && !Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => __('profile.current_password_incorrect')]);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        $user->notify(new \App\Notifications\PasswordChangedNotification('self'));

        return back()->with('success', __('profile.password_updated'));
    }
}
