/**
 * Onboarding tour del módulo Laboratories. Los selectores apuntan a data-tour="*"
 * en el template; si alguno no está montado al disparar el composable lo
 * saltea automáticamente.
 */
export const laboratoriesTourSteps = (t) => [
    { element: '[data-tour="filters"]',       popover: { title: t('laboratories.tour.step2_title'), description: t('laboratories.tour.step2_body') }},
    { element: '[data-tour="saved-views"]',   popover: { title: t('laboratories.tour.step3_title'), description: t('laboratories.tour.step3_body') }},
    { element: '[data-tour="columns"]',       popover: { title: t('laboratories.tour.step4_title'), description: t('laboratories.tour.step4_body') }},
    { element: '[data-tour="export-import"]', popover: { title: t('laboratories.tour.step5_title'), description: t('laboratories.tour.step5_body') }},
    { element: '[data-tour="favorites"]',     popover: { title: t('laboratories.tour.step7_title'), description: t('laboratories.tour.step7_body') }},
    { element: '[data-tour="bulk"]',          popover: { title: t('laboratories.tour.step8_title'), description: t('laboratories.tour.step8_body') }},
];
