<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ReportShare;
use App\Models\Transformer;
use App\Support\LikeQuery;
use App\Support\Tz;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

/**
 * "Envíos de informes": el historial de enlaces compartidos, CRUZANDO clientes.
 *
 * El modal de compartir muestra el historial de UN alcance (un trafo o la flota
 * de un cliente), así que no respondía "¿qué mandé esta semana?" sin entrar
 * cliente por cliente. Esta pantalla es esa vista.
 *
 * Alcance: los enlaces del workspace del usuario (el super ve todos). Además se
 * respeta la restricción de clientes asignados reusando los scopes globales de
 * Customer/Transformer como subconsulta — ReportShare no usa BelongsToTenant
 * (el portal es público), así que el filtrado se hace acá a mano.
 */
class ReportShareLogController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $user = auth()->user();

        $q = ReportShare::query()
            // transformer.customer va SÍ o SÍ: en un enlace de un solo trafo el
            // cliente sale de ahí, y sin precargarlo era una consulta por fila.
            ->with([
                'customer:id,name',
                'transformer:id,serial,tag,customer_id',
                'transformer.customer:id,name',
                'creator:id,name',
                'tenant:id,name',
            ]);

        if (!$user->hasRole('super')) {
            $q->where('tenant_id', $user->tenant_id);
        } elseif ($request->filled('f_tenant')) {
            $q->where('tenant_id', $request->integer('f_tenant'));
        }

        // Clientes asignados: las subconsultas arrastran los scopes globales de
        // cada modelo, así que no hace falta repetir la regla acá.
        $q->where(function ($sub) {
            $sub->whereIn('customer_id', Customer::query()->select('id'))
                ->orWhereIn('transformer_id', Transformer::query()->select('id'));
        });

        if ($term = trim((string) $request->query('q'))) {
            // Mismo criterio que los índices del proyecto: unaccent+ILIKE en
            // Postgres, LIKE con ESCAPE en SQLite (tests). LikeQuery escapa los
            // comodines del usuario — sin eso, escribir "%" traía todo.
            $isPgsql = DB::connection()->getDriverName() === 'pgsql';
            $needle  = LikeQuery::contains($term);
            $like    = fn (string $expr) => $isPgsql
                ? "unaccent(lower({$expr})) LIKE unaccent(lower(?))"
                : "{$expr} LIKE ? ESCAPE '\\'";

            $q->where(function ($sub) use ($like, $needle) {
                $sub->whereRaw($like('report_shares.recipient_email'), [$needle])
                    ->orWhereRaw($like('report_shares.note'), [$needle])
                    ->orWhereHas('customer', fn ($c) => $c->whereRaw($like('customers.name'), [$needle]))
                    ->orWhereHas('transformer', fn ($t) => $t
                        ->whereRaw($like('transformers.serial'), [$needle])
                        ->orWhereRaw($like('transformers.tag'), [$needle]));
            });
        }

        match ($request->query('estado')) {
            'active'  => $q->whereNull('revoked_at')->where('expires_at', '>', now()),
            'expired' => $q->whereNull('revoked_at')->where('expires_at', '<=', now()),
            'revoked' => $q->whereNotNull('revoked_at'),
            default   => null,
        };

        if ($request->filled('f_customer')) {
            $q->where('customer_id', $request->integer('f_customer'));
        }
        if ($request->filled('desde')) {
            $q->whereDate('created_at', '>=', $request->query('desde'));
        }
        if ($request->filled('hasta')) {
            $q->whereDate('created_at', '<=', $request->query('hasta'));
        }

        $page = $q->orderByDesc('created_at')->paginate(self::PER_PAGE)->withQueryString();

        return inertia('ReportShares/Index', [
            'rows' => [
                'data'         => collect($page->items())->map(fn ($s) => $this->row($s))->values(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
            'filters'   => $request->only(['q', 'estado', 'f_customer', 'f_tenant', 'desde', 'hasta']),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'isSuper'   => $user->hasRole('super'),
            'tenants'   => $user->hasRole('super')
                ? \App\Models\Tenant::orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    /** Revocar desde el historial (mismo efecto que en el modal: no borra). */
    public function revoke(ReportShare $share): RedirectResponse
    {
        $user = auth()->user();
        abort_unless($user->hasRole('super') || $share->tenant_id === $user->tenant_id, 403);

        if ($share->revoked_at === null) {
            $share->update(['revoked_at' => now()]);
            $share->auditRevoked();
        }

        return back()->with('success', __('sharing.revoked'));
    }

    private function row(ReportShare $s): array
    {
        return [
            'id'              => $s->id,
            'recipient_email' => $s->recipient_email,
            'link_only'       => $s->isLinkOnly(),
            'note'            => $s->note,
            'url'             => route('share.gate', $s->token),
            'scope_type'      => $s->scope_type,
            'customer'        => $s->customer?->name ?? $s->transformer?->customer?->name,
            // Qué abarca: un trafo (su serial) o cuántos de la flota.
            'target'          => $s->isFleet()
                ? (is_array($s->transformer_ids) ? count($s->transformer_ids) : null)
                : ($s->transformer?->serial ?: $s->transformer?->tag),
            // Fechas YA formateadas en la zona del usuario (Tz resuelve
            // usuario → workspace → país → UTC). Antes se mandaba el ISO crudo y
            // el navegador convertía con SU zona y SU formato: si no coincidían,
            // esta pantalla mostraba una hora distinta al resto del sistema.
            'created_at'      => Tz::format($s->created_at),
            'expires_at'      => Tz::formatDate($s->expires_at),
            'revoked_at'      => $s->revoked_at ? Tz::formatDate($s->revoked_at) : null,
            'open_count'      => $s->open_count,
            'last_opened_at'  => $s->last_opened_at ? Tz::formatDate($s->last_opened_at) : null,
            'sent_by'         => $s->creator?->name,
            'tenant'          => $s->tenant?->name,
            'estado'          => $s->revoked_at ? 'revoked' : ($s->expires_at?->isPast() ? 'expired' : 'active'),
        ];
    }
}
