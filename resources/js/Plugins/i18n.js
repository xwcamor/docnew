import { usePage } from '@inertiajs/vue3';

/**
 * Plugin Vue que registra `$t()` global, leyendo del shared prop `translations`
 * que el middleware HandleInertiaRequests inyecta en cada response.
 *
 * Usage en templates:
 *   {{ $t('global.active') }}             → "Activo"
 *   {{ $t('regions.name') }}              → "Nombre"
 *   {{ $t('global.disclosure', { terms: 'X', privacy: 'Y' }) }}  → reemplazo
 *
 * Usage en script setup:
 *   import { useI18n } from '@/Plugins/i18n';
 *   const { t } = useI18n();
 *   const label = t('global.active');
 *
 * Convención:
 *   - key se expresa como 'namespace.subkey.subsubkey' (ej. 'regions.is_active')
 *   - Si no encuentra la traducción, devuelve la key tal cual (para detectar
 *     keys ausentes en desarrollo en lugar de mostrar undefined).
 *   - Soporta reemplazo de :placeholders al estilo Laravel.
 */

/** Resuelve un key dot-path en un objeto anidado. */
function resolveKey(translations, key) {
    if (!translations) return null;
    const parts = key.split('.');
    let cur = translations;
    for (const p of parts) {
        if (cur && typeof cur === 'object' && p in cur) {
            cur = cur[p];
        } else {
            return null;
        }
    }
    return typeof cur === 'string' ? cur : null;
}

/**
 * Reemplaza :placeholders con los valores de replace dict.
 *
 * Los nombres se aplican del MÁS LARGO al más corto: si no, un placeholder que
 * es prefijo de otro se lo come (`:n` dentro de `:names` dejaba "3ames").
 * Es el mismo criterio que usa Laravel en el lado PHP.
 */
function applyReplacements(str, replacements) {
    if (!replacements) return str;
    return Object.entries(replacements)
        .sort(([a], [b]) => b.length - a.length)
        .reduce((acc, [k, v]) => acc.replaceAll(`:${k}`, String(v)), str);
}

// Sufijos que indican texto opcional de ayuda. Si la traducción no existe
// preferimos devolver '' (vacío) en vez de la key cruda — así Ant Design no
// renderiza el ícono de help vacío ni un placeholder con texto basura.
const SOFT_FALLBACK_SUFFIXES = ['_help', '_placeholder', '_hint', '_tooltip'];

/**
 * Pluralización estilo Laravel (trans_choice). Resuelve cadenas como
 *   "{0} sin X|{1} 1 X|[2,*] :count X"
 * eligiendo el segmento según `number`. Soporta {n} exacto y [a,b] rango
 * (* = infinito); sin condiciones, regla simple es/en (1 → singular).
 */
function choosePlural(message, number) {
    const n = Number(number) || 0;
    const segments = String(message).split('|');
    const plain = [];
    for (const seg of segments) {
        const m = seg.match(/^\s*(?:\{(\d+)\}|\[(\d+|\*)\s*,\s*(\d+|\*)\])\s*([\s\S]*)$/);
        if (!m) { plain.push(seg); continue; }
        if (m[1] !== undefined) {
            if (n === Number(m[1])) return m[4];
        } else {
            const lo = m[2] === '*' ? -Infinity : Number(m[2]);
            const hi = m[3] === '*' ? Infinity : Number(m[3]);
            if (n >= lo && n <= hi) return m[4];
        }
    }
    const idx = n === 1 ? 0 : Math.min(1, plain.length - 1);
    return plain[idx] ?? plain[plain.length - 1] ?? message;
}

/** Resuelve + pluraliza un key. `:count` se reemplaza con `number` (+ extras). */
export function transChoice(translations, key, number, replacements) {
    const found = resolveKey(translations, key);
    if (found === null) return key;
    return applyReplacements(choosePlural(found, number).trim(), { count: number, ...(replacements || {}) });
}

/** Función plana — útil cuando no se quiere llamar usePage adentro. */
export function translate(translations, key, replacements) {
    const found = resolveKey(translations, key);
    if (found === null) {
        if (typeof key === 'string' && SOFT_FALLBACK_SUFFIXES.some(s => key.endsWith(s))) {
            return '';
        }
        return key;  // fallback: la key cruda (debug)
    }
    return applyReplacements(found, replacements);
}

/** Composable para usar dentro de <script setup>. */
export function useI18n() {
    const page = usePage();
    return {
        t: (key, replacements) => translate(page.props.translations, key, replacements),
        tc: (key, number, replacements) => transChoice(page.props.translations, key, number, replacements),
    };
}

/** Plugin para registrarlo como $t global en el template. */
export default {
    install(app) {
        app.config.globalProperties.$t = function (key, replacements) {
            const translations = this.$page?.props?.translations;
            return translate(translations, key, replacements);
        };
        app.config.globalProperties.$tc = function (key, number, replacements) {
            const translations = this.$page?.props?.translations;
            return transChoice(translations, key, number, replacements);
        };
    },
};
