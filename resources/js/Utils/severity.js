import { reactive } from 'vue';

// Semáforo de condición (5 niveles). El token vive en datos (result_scales.color);
// aquí se mapea a hex. 5 colores distinguibles para que Muy Bueno≠Bueno y
// Malo≠Muy Malo (antes ambos compartían verde/rojo).
// Los HEX son editables por super (global) con override por tenant: el backend
// (DiagnosticColors) los inyecta como prop compartida de Inertia y app.js llama a
// setDiagnosticColors() al iniciar y en cada navegación. Estos valores son el
// DEFAULT de fábrica (última red de seguridad si la inyección no llega).
const DEFAULT_SEMA = {
    green:  '#1D7044', // Muy Bueno
    lime:   '#5AA82E', // Bueno
    yellow: '#E9A23B', // Medio
    orange: '#E2661E', // Malo
    red:    '#C8281D', // Muy Malo
};
const DEFAULT_STOPS = [[46, 147, 60], [233, 162, 59], [200, 40, 29]]; // good, warn, bad

// Store REACTIVO: al editarlo (setDiagnosticColors) Vue re-renderiza los
// componentes que llaman a semaforoHex/sevColor en su render/computed, sin
// recargar la página. (Si fuera una variable plana, el cambio no se reflejaría
// hasta un refresh porque las funciones no crean dependencia reactiva.)
const colors = reactive({
    sema: { ...DEFAULT_SEMA },
    stops: DEFAULT_STOPS.map((s) => [...s]),
});

const HEX_RE = /^#[0-9a-fA-F]{6}$/;
const hexToRgb = (h) => [parseInt(h.slice(1, 3), 16), parseInt(h.slice(3, 5), 16), parseInt(h.slice(5, 7), 16)];

/**
 * Aplica los colores editables (override del tenant/global). Valida cada HEX; un
 * valor inválido o ausente cae al default — nunca rompe el render. Idempotente.
 * @param {{sema?:Object, stops?:Object}} data
 */
export function setDiagnosticColors(data) {
    if (!data || typeof data !== 'object') return;
    if (data.sema && typeof data.sema === 'object') {
        for (const k of Object.keys(DEFAULT_SEMA)) {
            colors.sema[k] = HEX_RE.test(data.sema[k] || '') ? data.sema[k] : DEFAULT_SEMA[k];
        }
    }
    if (data.stops && typeof data.stops === 'object') {
        const order = ['good', 'warn', 'bad'];
        colors.stops = order.map((k, i) => (HEX_RE.test(data.stops[k] || '') ? hexToRgb(data.stops[k]) : [...DEFAULT_STOPS[i]]));
    }
}

/** Hex del semáforo para un token de color. Gris si el token es desconocido. */
export function semaforoHex(token) {
    return colors.sema[token] ?? '#9aa0a6';
}

// Severidad continua para límites de diagnóstico (cromas/fiquis).
// `sev` va de 0 (mejor, verde) a 1 (peor, rojo), pasando por ámbar.
// (STOPS se define arriba, junto al semáforo, porque ambos son editables.)

/** Color rgb() para una severidad 0..1. */
export function sevColor(sev) {
    const STOPS = colors.stops;
    const t = Math.min(Math.max(sev ?? 0, 0), 1);
    const [a, b, f] = t < 0.5 ? [STOPS[0], STOPS[1], t * 2] : [STOPS[1], STOPS[2], (t - 0.5) * 2];
    const c = a.map((v, i) => Math.round(v + (b[i] - v) * f));
    return `rgb(${c[0]},${c[1]},${c[2]})`;
}

/** Mismo color con alpha (para fondos suaves). */
export function sevRgba(sev, alpha) {
    return sevColor(sev).replace('rgb(', 'rgba(').replace(')', `,${alpha})`);
}

/** Banda en la que cae un valor: { from, to, sev }. Null si no hay banda o valor vacío. */
export function bandOf(bands, value) {
    if (value === null || value === undefined || !Array.isArray(bands)) {
        return null;
    }
    const v = Number(value);
    return bands.find((b) => v >= b.from && (b.to === null || b.to === undefined || v < b.to)) ?? null;
}

/**
 * Fondo de alerta de una celda según su severidad (0..1). Regla de oro (es una
 * herramienta de diagnóstico con consecuencias graves):
 *
 *  - sev <= 0  (óptimo)        → sin color (la celda queda limpia).
 *  - sev >= 1  (pasó el límite) → SIEMPRE rojo. Ignora `alertFloor`: un valor
 *                                 fuera de norma NUNCA se puede ocultar.
 *  - 0 < sev < 1 (precaución)  → ámbar→rojo; se muestra si sev >= alertFloor
 *                                 (control opcional de ruido, solo del amarillo).
 *
 * Se pinta en la mitad ámbar(0.5)→rojo(1) de la rampa: nunca verde, para no
 * confundir "celda pintada" con "valor bueno".
 *
 * @returns {{background:string}|null}
 */
export function cellAlertBg(sev, alertFloor = 0) {
    if (sev === null || sev === undefined) return null;
    const s = Number(sev);
    if (s <= 0) return null;                                   // óptimo → limpio
    const over = s >= 1;                                       // pasó el límite
    if (!over && s < (Number(alertFloor) || 0)) return null;  // precaución leve filtrada
    return { background: sevRgba(0.5 + s * 0.5, over ? 0.30 : 0.18) };
}
