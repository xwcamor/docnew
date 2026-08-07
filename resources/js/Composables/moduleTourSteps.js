/**
 * Pasos de tour ESTÁNDAR para todos los módulos de listado (driver.js).
 * El layout es uniforme (clases mi-/tx- + data-tour), así que un solo set sirve
 * para todos. Tour corto y enfocado en lo NO obvio (3 pasos):
 *
 *   1. Vistas y favoritos   — presets / vistas guardadas / favoritos (1 clic).
 *   2. Barra de herramientas— buscar, filtrar, exportar, columnas, vista, crear.
 *   3. Tus resultados       — cómo abrir/editar cada registro.
 *
 * Se omite el paso de "título del módulo" (obvio). Transformers suma el paso de
 * flota al inicio con { hasFleet: true }. Los pasos cuyo selector no exista al
 * disparar se saltean solos (useModuleTour) — por eso los módulos especiales
 * (dashboard, bandeja, etc.) no usan este set.
 */
export function moduleTourSteps(t, { hasFleet = false } = {}) {
    return [
        ...(hasFleet ? [{ element: '[data-tour="fleet-panel"]', popover: { title: t('global.tour.fleet_title'), description: t('global.tour.fleet_body') } }] : []),
        { element: '[data-tour="saved-views"]',  popover: { title: t('global.tour.views_title'),   description: t('global.tour.views_body') } },
        // La barra de herramientas completa (búsqueda + filtros + columnas + exportar + vista + crear).
        { element: '.mi-tabletoolbar__right',     popover: { title: t('global.tour.actions_title'), description: t('global.tour.actions_body') } },
        // Resultados: señala SOLO el primer item visible (card/fila/lista), no toda la tabla.
        { element: '.rt-card, .rt-row, .tcard, .tlrow, .grid-card .ant-table-tbody > tr', popover: { title: t('global.tour.results_title'), description: t('global.tour.results_body') } },
    ];
}
