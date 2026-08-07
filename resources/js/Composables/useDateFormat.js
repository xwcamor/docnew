/**
 * useDateFormat — formateo de fechas con el TZ efectivo del user.
 *
 * La BD viene en UTC (ISO 8601 con Z). El TZ del user lo resuelve backend
 * (App\Support\Tz::for($user)) y lo comparte vía page.props.auth.user.timezone.
 *
 * Formatos disponibles (todos en TZ del user):
 *   formatDateTime      → "15-05-2026 16:02"          (default para listados)
 *   formatDate          → "15-05-2026"
 *   formatTime          → "16:02"
 *   formatDateTimeFull  → "15-05-2026 16:02:35"       (con segundos — Show.vue + tooltips de history)
 *
 * Implementado con Intl.DateTimeFormat (nativo, no requiere dayjs).
 */
import { usePage } from '@inertiajs/vue3';

/**
 * Extrae componentes (year/month/day/hour/minute/second) ya convertidos al
 * TZ pedido. Más confiable que toLocaleString() para parsing.
 */
function partsInTz(dateLike, timeZone, withSeconds = false) {
    const d = dateLike instanceof Date ? dateLike : new Date(dateLike);
    if (isNaN(d.getTime())) return null;

    const opts = {
        timeZone,
        year:   'numeric',
        month:  '2-digit',
        day:    '2-digit',
        hour:   '2-digit',
        minute: '2-digit',
        hour12: false,
    };
    if (withSeconds) opts.second = '2-digit';

    const fmt   = new Intl.DateTimeFormat('en-GB', opts);
    const parts = Object.fromEntries(fmt.formatToParts(d).map(p => [p.type, p.value]));
    return {
        year:   parts.year,
        month:  parts.month,
        day:    parts.day,
        hour:   parts.hour === '24' ? '00' : parts.hour, // edge case midnight
        minute: parts.minute,
        second: parts.second ?? '00',
    };
}

export function useDateFormat() {
    const page = usePage();
    const tz = () => page.props.auth?.user?.timezone || page.props.tz?.default || 'UTC';

    const formatDateTime = (value, opts = {}) => {
        if (value == null || value === '') return opts.placeholder ?? '—';
        const p = partsInTz(value, tz());
        if (!p) return opts.placeholder ?? '—';
        return `${p.day}-${p.month}-${p.year} ${p.hour}:${p.minute}`;
    };

    const formatDate = (value, opts = {}) => {
        if (value == null || value === '') return opts.placeholder ?? '—';
        const p = partsInTz(value, tz());
        if (!p) return opts.placeholder ?? '—';
        return `${p.day}-${p.month}-${p.year}`;
    };

    const formatTime = (value, opts = {}) => {
        if (value == null || value === '') return opts.placeholder ?? '—';
        const p = partsInTz(value, tz());
        if (!p) return opts.placeholder ?? '—';
        return `${p.hour}:${p.minute}`;
    };

    /**
     * dd-mm-aaaa con segundos — usado en las páginas Show.vue (pestaña Record
     * audit / Created at / Updated at). Mismo estilo que el resto del proyecto
     * pero con segundos para auditoría (orden exacto de eventos cercanos).
     *   "15-05-2026 16:02:35"
     */
    const formatDateTimeFull = (value, opts = {}) => {
        if (value == null || value === '') return opts.placeholder ?? '—';
        const p = partsInTz(value, tz(), true);
        if (!p) return opts.placeholder ?? '—';
        return `${p.day}-${p.month}-${p.year} ${p.hour}:${p.minute}:${p.second}`;
    };

    return {
        formatDateTime,
        formatDate,
        formatTime,
        formatDateTimeFull,
        timezone: tz,
    };
}
