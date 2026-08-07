<?php

namespace App\Http\Middleware;

use App\Models\Download;
use App\Support\Tz;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determine the asset version (cache-busting on deploy).
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every Inertia response.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                // Closure (LAZY): permisos + plan + suscripción + tz se computan
                // SOLO cuando Inertia renderiza una página. NO corren en los
                // endpoints JSON de fondo (inbox/poll cada 4s, saved-views, etc.)
                // que no usan estas props → les saca ~20 queries por request.
                'user' => fn () => $user ? [
                    'id'           => $user->id,
                    'name'         => $user->name,
                    'email'        => $user->email,
                    // photo_url tiene cache-busting (?v={updated_at}) — el header
                    // avatar y cualquier render del avatar del usuario logueado
                    // se refresca automatico cuando sube una foto nueva.
                    'photo_url'    => $user->photo_url,
                    'permissions'  => $user->getAllPermissions()->pluck('name'),
                    'roles'        => $user->getRoleNames(),
                    // Usuario acotado a su cartera (customer_user no vacío): es
                    // solo-lectura en Clientes pase lo que pase su perfil. Flag
                    // global para que el front esconda crear/duplicar de cliente
                    // (incl. el "+" del form de trafos), coherente con el override
                    // del backend (FormRequests de Customer).
                    'is_customer_restricted' => ! empty($user->assignedCustomerIds()),
                    // Mapa de módulos → ISO timestamp del tour completado.
                    // El frontend usa esto para decidir si dispara el
                    // onboarding tour de un módulo en su primera visita.
                    'module_tours' => $user->module_tours ?? [],
                    // Apariencia (cross-device): esquema de color + posición del
                    // menú. AppLayout las aplica al cargar (prevalecen sobre el
                    // localStorage local).
                    'ui_scheme'    => $user->ui_scheme ?? 'sap',
                    'nav_position' => $user->nav_position ?? 'top',
                    // Features del plan del tenant — el frontend las usa para
                    // mostrar/ocultar entradas del sidebar según lo que el plan
                    // permite. super no tiene tenant: se le da acceso a todo.
                    'plan_features' => $this->buildPlanFeatures($user),
                    // Info visible del plan: slug, name, icon, color, dias
                    // restantes. Lo usa el badge del header + dropdown del avatar.
                    'plan_info'     => $this->buildPlanInfo($user),
                    // TZ efectivo resuelto por backend (user → tenant → country
                    // → UTC). Siempre devuelve string no-null. El frontend
                    // (useDateFormat) lo usa para mostrar fechas en el huso
                    // horario del usuario, sin tener que recalcularlo por vista.
                    'timezone'      => Tz::for($user),
                ] : null,
            ],
            // Bloque `tz`:
            //   - default: TZ del user actual, listo para usar en cualquier vista.
            //   - available: lista completa de timezones para los selectores
            //     (Profile, Tenant edit). Se cachea forever — la lista no
            //     cambia entre requests; tampoco vale la pena recargarla en
            //     cada response.
            'tz' => [
                'default'   => $user ? Tz::for($user) : config('app.timezone', 'UTC'),
                // Closure → solo se evalúa si la vista lee la prop. Combina
                // con Cache::rememberForever para evitar el filter() en
                // cada request donde sí se accede.
                'available' => fn () => Cache::rememberForever(
                    'tz.available_timezones.v1',
                    fn () => Tz::availableTimezones(),
                ),
            ],
            // Aceptación de Términos/Privacidad (LPDP): si la versión vigente
            // (setting legal.terms_version) difiere de la aceptada por el user,
            // AppLayout muestra el modal de aceptación (bloqueante).
            'legal' => $user ? [
                'version'  => $legalVersion = (string) \App\Models\Setting::get('legal.terms_version', '1.0'),
                'accepted' => $user->terms_accepted_version === $legalVersion,
            ] : null,
            'flash' => [
                // pull() lee Y BORRA en el mismo paso — garantiza que el flash
                // se consuma una sola vez. Sin esto, en Inertia SPA el flash
                // sobrevive entre XHRs y los toasts aparecen en cada nav.
                'success'      => fn () => $request->session()->pull('success'),
                'error'        => fn () => $request->session()->pull('error'),
                // Enlace de "Compartir reporte" recién creado (url + email destinatario).
                'shareCreated' => fn () => $request->session()->pull('shareCreated'),
                // One-time-only API token returned by Workspaces > tokens.create.
                // Shown to super in the Show page modal and never again.
                'newToken'     => fn () => $request->session()->pull('newToken'),
                // Para el patrón "Eliminado. Deshacer (60s)": el controller
                // de delete deja un claim en sesión y un payload en flash;
                // el frontend muestra el toast con botón Undo.
                'recentDelete' => fn () => $request->session()->pull('recentDelete'),
            ],
            // Recientes del usuario — últimos 10 registros vistos (cualquier
            // módulo). El frontend los muestra en el dropdown del avatar para
            // que el usuario pueda volver rápido a algo que estaba mirando.
            'recentViews' => fn () => $user ? $this->buildRecentViewsPayload($user->id) : [],
            // Inbox del bell — recent + unread + processing para el badge.
            // El nombre `inbox` evita name-collision con el page-prop
            // `notifications` que la página /notifications usa para su
            // listado paginado.
            'inbox' => fn () => $user ? $this->buildInboxPayload($user->id) : null,
            // Aprobaciones de informes (etapa 2 de firmas): solo para firmantes.
            // is_signer gatea el menú; pending = badge. Closure → lazy.
            'approvals' => fn () => $user ? $this->buildApprovalsPayload($user) : ['is_signer' => false, 'pending' => 0, 'requires_approval' => false],
            // Frecuencia del polling del bell — configurable desde el modulo
            // Settings (key `notifications.poll_interval_seconds`). El frontend
            // lo clampea a [1, 60]. Closure → solo se evalua si la vista la lee.
            'notificationsPollInterval' => fn () => \App\Models\Setting::getInt('notifications.poll_interval_seconds', 4),
            // Branding global — leido desde Settings. Cualquier vista puede usar
            // page.props.appName / page.props.appLogoUrl para mostrarlos. Sin
            // duplicar logica en cada modulo.
            'appName'    => fn () => \App\Models\Setting::get('app.name', config('app.name', 'Application Name')),
            'appLogoUrl' => fn () => \App\Models\Setting::get('app.logo_url', '') ?: null,
            // Feature flag: si false el Login.vue oculta el boton "Continuar
            // con Google". Setting tiene prioridad sobre la presencia de las
            // credenciales OAuth en .env.
            'googleLoginEnabled' => fn () => \App\Models\Setting::getBool('features.google_login_enabled', false),
            'locale' => app()->getLocale(),
            // Idiomas disponibles para el selector del navbar. Intersección de:
            //   1) config('laravellocalization.supportedLocales') — locales que el router URL acepta
            //   2) Language::where('is_active', true) — los que el super tiene activos en el módulo
            // Si super desactiva uno desde la UI core, desaparece del dropdown.
            // Para agregar un idioma nuevo: alta en módulo Languages + alta en config laravellocalization.
            'availableLocales' => fn () => $this->buildAvailableLocales(),
            // Traducciones del locale actual cargadas desde lang/{locale}/*.php.
            // Esto deja un solo source of truth (PHP lang files) y permite a
            // Vue usar $t('global.active') con el mismo string que __() en
            // PHP/Blade. Solo cargamos los namespaces que el frontend usa
            // (no los de email/auth) para mantener el payload chico.
            'translations' => fn () => $this->loadTranslations(),
            // Colores del diagnóstico (semáforo + gradiente de celda), editables por
            // super con override por tenant. Se comparten en TODA página porque
            // utils/severity.js los usa en cualquier vista (no solo el editor).
            'diagnosticColors' => fn () => \App\Support\Diagnostics\DiagnosticColors::all(),
            'app'    => [
                'name' => config('app.name'),
            ],
            // Estado de suscripción del tenant del user — drives el banner global
            // de warning ("tu plan expira en N días") en AppLayout. null si el user
            // no tiene tenant (super) o si tenant no fue resuelto.
            'subscription' => fn () => $this->buildSubscriptionStatus($user),
        ]);
    }

    /**
     * Construye el mapa de features del plan actual del tenant del user.
     * Super recibe `__all__: true` para que el frontend lo trate como
     * full-access sin tener que enumerar cada feature key.
     */
    protected function buildPlanFeatures($user): array
    {
        if ($user->hasRole('super')) {
            return ['__all__' => true];
        }
        if (!$user->tenant_id) return [];
        $tenant = $user->tenant;
        if (!$tenant) return [];
        $plan = \App\Models\Plan::findBySlug($tenant->currentPlan());
        return $plan?->features ?? [];
    }

    /**
     * Info compacta del plan para mostrarlo en el header + dropdown del avatar.
     * Devuelve null para super (no tiene plan asociado a un workspace).
     */
    protected function buildPlanInfo($user): ?array
    {
        if ($user->hasRole('super')) return null;
        if (!$user->tenant_id) return null;
        $tenant = $user->tenant()->with('activeSubscription')->first();
        if (!$tenant) return null;
        $plan = \App\Models\Plan::findBySlug($tenant->currentPlan());
        if (!$plan) return null;

        $sub = $tenant->activeSubscription;
        return [
            'slug'           => $plan->slug,
            'name'           => $plan->name,
            'icon'           => $plan->icon,
            'color'          => $plan->color,
            'tagline'        => $plan->tagline,
            'days_remaining' => $sub?->daysRemaining(),
            'ends_at'        => $sub?->ends_at?->toIso8601String(),
            'is_trial'       => $sub?->isTrial() ?? false,
        ];
    }

    /**
     * Estado de sub para el banner global. Devuelve null si no aplica banner.
     * El warning se dispara solo si: días_restantes <= 7 OR está en trial.
     */
    protected function buildSubscriptionStatus($user): ?array
    {
        if (!$user || !$user->tenant_id) return null;
        if ($user->hasRole('super')) return null;  // super no ve banner

        $sub = \App\Models\Subscription::where('tenant_id', $user->tenant_id)
            ->current()
            ->orderByDesc('ends_at')
            ->first();

        if (!$sub) return null;

        $days = $sub->daysRemaining();
        $isTrial = $sub->isTrial();
        $showBanner = $isTrial || $days <= 7;
        if (!$showBanner) return null;

        return [
            'plan'           => $sub->plan,
            'status'         => $sub->status,
            'is_trial'       => $isTrial,
            'days_remaining' => $days,
            'ends_at'        => $sub->ends_at?->toIso8601String(),
        ];
    }

    /**
     * Carga los namespaces de traducción que el frontend Vue necesita.
     *
     * Mantenemos esta lista corta a propósito — `auth.php`, `passwords.php`,
     * etc. solo se usan desde Blade y no hace falta mandarlos al cliente.
     */
    /**
     * Construye la lista de idiomas que aparece en el dropdown del navbar.
     * Cacheo en memory por request — se llama muchas veces si Inertia hace
     * navegación parcial.
     */
    protected function buildAvailableLocales(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        // Locales que el router URL acepta (es, en, etc.).
        $supported = array_keys(config('laravellocalization.supportedLocales', []));
        if (empty($supported)) return $cached = [];

        // Languages activos en el módulo del super.
        $activeIsos = \App\Models\Language::query()
            ->where('is_active', true)
            ->whereIn('iso_code', $supported)
            ->orderBy('name')
            ->get(['iso_code', 'name'])
            ->map(fn ($l) => ['code' => $l->iso_code, 'label' => $l->name])
            ->all();

        return $cached = $activeIsos;
    }

    protected function loadTranslations(): array
    {
        $namespaces = ['global', 'regions', 'languages', 'countries', 'locales', 'tenants', 'system_modules', 'settings', 'users', 'roles', 'customers', 'transformers', 'oil_types', 'transformer_types', 'tap_changer_types', 'laboratories', 'tap_changer_brands', 'tap_changer_models', 'tap_changer_technologies', 'brands', 'cromas', 'furanos', 'fiquis', 'fpot', 'diagnostics', 'bitacora', 'audit_logs', 'sidebar', 'imports', 'notifications', 'auth', 'profile', 'subscriptions', 'plans', 'automations', 'dashboard', 'messages', 'sharing', 'diagnostic_rules', 'comments', 'search', 'approvals', 'comparison', 'tools', 'locks'];
        $out = [];
        foreach ($namespaces as $ns) {
            $messages = trans($ns);
            // trans() devuelve el key string si no encuentra el archivo;
            // descartamos esos casos.
            if (is_array($messages)) {
                $out[$ns] = $messages;
            }
        }

        // Las 5 etiquetas de calificación (cond_*) son editables por idioma desde
        // el dataset `condition_labels` (indexado por rating). Las sobrescribimos
        // aquí en UN solo punto → todos los componentes Vue que usan
        // t('diagnostics.cond_*') reflejan la edición sin tocarlos uno por uno.
        // Si la BD no está lista, el helper cae al archivo lang (no-op).
        try {
            if (isset($out['diagnostics'])) {
                $out['diagnostics'] = array_merge(
                    $out['diagnostics'],
                    \App\Support\Diagnostics\ConditionLabel::i18nOverrides()
                );
            }
        } catch (\Throwable) {
            // Tabla aún no migrada / sin datos → se queda con el archivo lang.
        }

        return $out;
    }

    /**
     * Recientes del usuario (últimos 10 vistos, cualquier módulo).
     *
     * Devuelve cada item con label + URL para el dropdown del avatar.
     * Mapeamos el morph type al route name correspondiente. Si un módulo
     * no tiene ruta show definida (o el slug ya no existe), lo skipeamos.
     */
    protected function buildRecentViewsPayload(int $userId): array
    {
        $rows = \App\Models\UserRecentView::where('user_id', $userId)
            ->orderByDesc('viewed_at')
            ->limit(10)
            ->get();

        // Agrupamos por type para hacer 1 query por módulo (evita N+1).
        // No todos los modelos polimorficos tienen `slug` (ej. Automation usa
        // id como route key). Detectamos el routeKey real del modelo antes de
        // seleccionar columnas — sin esto la query peta con "no such column".
        $grouped = $rows->groupBy('viewable_type');
        $resolved = [];
        foreach ($grouped as $type => $items) {
            $ids = $items->pluck('viewable_id')->all();

            $proto    = new $type;
            $routeKey = $proto->getRouteKeyName();

            // Cargamos el modelo completo (≤10 filas, costo nulo) en vez de
            // seleccionar ['id','name',routeKey] fijo: no todos los modelos
            // tienen columna `name` (ej. transformers usan serial/tag), y pedir
            // 'name' a esa tabla petaba con "undefined column name" → 500 global.
            $models = $type::query()->whereIn('id', $ids)->get()->keyBy('id');
            foreach ($items as $item) {
                $m = $models->get($item->viewable_id);
                if (!$m) continue;
                $resolved[] = [
                    'id'         => $m->id,
                    'name'       => $m->name ?? $m->serial ?? $m->tag ?? ('#' . $m->id),
                    'module'     => $this->moduleSlugFor($type),
                    'url'        => $this->showUrlFor($type, $m->{$routeKey}),
                    'viewed_at'  => $item->viewed_at?->toIso8601String(),
                ];
            }
        }

        // Re-ordenar por viewed_at desc, limit 10 (ya venía ordenado pero
        // groupBy puede mezclar el orden).
        usort($resolved, fn ($a, $b) => strcmp($b['viewed_at'] ?? '', $a['viewed_at'] ?? ''));
        return array_slice($resolved, 0, 10);
    }

    /** FQCN → slug del módulo. Lee del allowlist único en config/polymorphic.php. */
    protected function moduleSlugFor(string $type): string
    {
        foreach (config('polymorphic.modules', []) as $slug => $cfg) {
            if (($cfg['model'] ?? null) === $type) return $slug;
        }
        return class_basename($type);
    }

    /** FQCN → URL del show del módulo, o null si no aplica. */
    protected function showUrlFor(string $type, $slugOrId): ?string
    {
        $slug = $this->moduleSlugFor($type);
        $routeName = config("polymorphic.modules.{$slug}.show_route");
        if (!$routeName) return null;
        try {
            return route($routeName, $slugOrId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Inbox payload (bell): las 10 notificaciones más recientes + contadores
     * para el badge (unread) y el polling automático (processing).
     *
     * Hoy solo "downloads" (archivos exportados listos), pero la shape ya está
     * pensada como bandeja unificada — cada item lleva un `kind` que permite
     * renderizar diferente según el tipo (download/task/alert).
     */
    /**
     * Aprobaciones de informes del usuario (etapa 2 de firmas). is_signer gatea
     * el menú "Aprobaciones"; pending alimenta el badge. Si no es firmante,
     * cero queries extra (solo el exists barato).
     */
    protected function buildApprovalsPayload(\App\Models\User $user): array
    {
        // require_approval gatea el botón "Enviar a aprobación" (lo ve cualquier
        // usuario del workspace, no solo firmantes).
        $requires = (bool) ($user->tenant?->require_report_approval ?? false);

        $isSigner = \App\Models\ReportSigner::where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)->exists();

        // "Mis solicitudes": las que ESTE usuario envió a aprobación. Drive del
        // menú propio (visible si tiene alguna) + badge de pendientes.
        $myRequestsTotal   = \App\Models\ReportRequest::where('requester_id', $user->id)->count();
        $myRequestsPending = \App\Models\ReportRequest::where('requester_id', $user->id)
            ->where('status', 'in_review')->count();

        $base = [
            'requires_approval'   => $requires,
            'my_requests_total'   => $myRequestsTotal,
            'my_requests_pending' => $myRequestsPending,
        ];

        if (!$isSigner) {
            return array_merge($base, ['is_signer' => false, 'pending' => 0]);
        }

        $pending = \App\Models\ReportApproval::where('user_id', $user->id)
            ->where('status', 'pending')
            ->whereHas('reportRequest', fn ($q) => $q->where('status', 'in_review'))
            ->count();

        return array_merge($base, ['is_signer' => true, 'pending' => $pending]);
    }

    protected function buildInboxPayload(int $userId): array
    {
        // Fuente unica del payload: lo arma InboxService, que tambien sirve
        // el endpoint de polling (Communication\InboxController::poll).
        return app(\App\Services\Communication\InboxService::class)->payload($userId);
    }
}
