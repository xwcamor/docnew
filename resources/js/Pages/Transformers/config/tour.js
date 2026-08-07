/**
 * Onboarding tour del módulo Transformers. Los selectores apuntan a data-tour="*"
 * en el template; si alguno no está montado al disparar el composable lo
 * saltea automáticamente.
 */
export const transformersTourSteps = (t) => [
    { element: '[data-tour="filters"]',       popover: { title: t('transformers.tour.step2_title'), description: t('transformers.tour.step2_body') }},
    { element: '[data-tour="saved-views"]',   popover: { title: t('transformers.tour.step3_title'), description: t('transformers.tour.step3_body') }},
    { element: '[data-tour="columns"]',       popover: { title: t('transformers.tour.step4_title'), description: t('transformers.tour.step4_body') }},
    { element: '[data-tour="export-import"]', popover: { title: t('transformers.tour.step5_title'), description: t('transformers.tour.step5_body') }},
    // "Filtros avanzados" va más al final, como función de poder: no debe pelear
    // el primer plano con la barra de filtros básica (que ya lo contiene).
    { element: '[data-tour="advanced-filters"]', popover: { title: t('transformers.tour.step_advanced_title'), description: t('transformers.tour.step_advanced_body') }},
    { element: '[data-tour="favorites"]',     popover: { title: t('transformers.tour.step7_title'), description: t('transformers.tour.step7_body') }},
    { element: '[data-tour="bulk"]',          popover: { title: t('transformers.tour.step8_title'), description: t('transformers.tour.step8_body') }},
];
