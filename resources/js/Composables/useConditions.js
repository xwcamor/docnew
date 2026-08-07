import { useI18n } from '@/Plugins/i18n';

/**
 * useConditions — traduce la condición del semáforo de diagnóstico a la etiqueta
 * que ve el usuario, en el idioma activo.
 *
 * El valor guardado en datos (result_scales.condition_label y las cachés de las
 * muestras) es un CÓDIGO interno estable en español ('Muy Bueno'…'Muy Malo'). La
 * etiqueta visible se traduce vía i18n (diagnostics.cond_*), así funciona en ES
 * y EN sin tocar los datos. Condiciones desconocidas (ej. IEEE 1-4) se muestran
 * tal cual.
 */
const KEY = {
    'Muy Bueno': 'muy_bueno',
    'Bueno':     'bueno',
    'Medio':     'medio',
    'Malo':      'malo',
    'Muy Malo':  'muy_malo',
};

// Rating ENTERO 0-4 (canónico del estado de salud del trafo) → clave i18n.
// 4 = Muy Bueno … 0 = Muy Malo. Espeja result_scales.hi_rating.
const RATING_KEY = ['muy_malo', 'malo', 'medio', 'bueno', 'muy_bueno'];

export function useConditions() {
    const { t } = useI18n();
    const condLabel = (condition) => {
        if (condition === null || condition === undefined || condition === '') return '—';
        const k = KEY[condition];
        return k ? t('diagnostics.cond_' + k) : condition;
    };
    // Traduce el rating numérico de salud (0-4) a la palabra del idioma activo.
    const ratingLabel = (rating) => {
        if (rating === null || rating === undefined || rating === '') return '—';
        const k = RATING_KEY[rating];
        return k ? t('diagnostics.cond_' + k) : '—';
    };
    // Calificación en estrellas (idioma-cero): el rating ES la nota. 4 = ★★★★.
    // Devuelve { filled, empty, total } para pintar la fila de estrellas.
    const ratingStars = (rating, total = 4) => {
        const r = Number.isFinite(+rating) ? Math.max(0, Math.min(total, Math.round(+rating))) : 0;
        return { filled: r, empty: total - r, total };
    };
    // Palabra a partir de una condición que puede venir como rating (número) o
    // como código canónico (texto). Unifica ambos caminos en la etiqueta del idioma.
    const labelFor = ({ rating = null, condition = null } = {}) => {
        if (rating !== null && rating !== undefined && rating !== '') return ratingLabel(rating);
        return condLabel(condition);
    };
    // Línea de estado/urgencia que ENCABEZA la conclusión de cada prueba, anclada a
    // la escala de acción común (0 rutina · 1 seguimiento · 2 investigar · 3 crítico).
    // Refuerza la severidad con tono imperativo cuando está comprometido/crítico.
    const ACTION_KEYS = ['routine', 'watch', 'investigate', 'critical'];
    const urgencyLine = (level) => t('diagnostics.urgency_' + (ACTION_KEYS[level] ?? 'routine'));
    return { condLabel, ratingLabel, ratingStars, labelFor, urgencyLine };
}
