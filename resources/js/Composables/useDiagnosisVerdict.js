import { useI18n } from '@/Plugins/i18n';

/**
 * useDiagnosisVerdict — compone el veredicto de una prueba a partir del dato
 * estructurado (`diagnosisSummary[type]`).
 *
 * `verdictParts` separa el ESTADO (titular: verde si está bien, coloreado si no)
 * de los DETALLES (líneas secundarias: DP, vida, IEEE, valor…). El resumen usa
 * las partes; el banner por pestaña usa `verdictReason` (todo en una línea).
 */
export function useDiagnosisVerdict() {
    const { t } = useI18n();

    const verdictParts = (type, v) => {
        if (!v || !v.has_data || v.condition == null) return { status: '', details: [], ok: true };
        const ok = (v.rating ?? 4) >= 3 && !v.exceeds;
        let status = '';
        const details = [];

        if (type === 'cromas') {
            status = (v.active_fault && v.fault_zone)
                ? t('diagnostics.verdict_fault', { type: t('cromas.duval.' + v.fault_zone) })
                : t('diagnostics.verdict_no_fault');
            if (v.co2co_level === 'low') details.push(t('diagnostics.verdict_co_low', { r: v.co2co }));
            else if (v.co2co_level === 'high') details.push(t('diagnostics.verdict_co_high', { r: v.co2co }));
        } else if (type === 'furanos') {
            status = ok ? t('diagnostics.verdict_paper_ok') : t('diagnostics.verdict_paper_bad');
            if (v.dp != null) details.push(t('diagnostics.verdict_dp', { dp: v.dp }));
            if (v.life_percent != null) details.push(t('diagnostics.verdict_life', { p: v.life_percent }));
        } else if (type === 'fiquis') {
            status = ok ? t('transformers.all_in_range') : '';
        } else if (type === 'fpot') {
            status = ok ? t('diagnostics.verdict_fpot_ok') : t('diagnostics.verdict_fpot_bad');
            if (v.value != null) details.push(t('diagnostics.verdict_fpot', { v: v.value }));
        }

        return { status, details, ok };
    };

    // Compat: una sola línea (banner por pestaña).
    const verdictReason = (type, v) => {
        const p = verdictParts(type, v);
        return [p.status, ...p.details].filter(Boolean).join(' · ');
    };

    return { verdictParts, verdictReason };
}
