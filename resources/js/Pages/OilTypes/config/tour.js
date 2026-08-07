/**
 * Onboarding tour del módulo OilTypes. Los selectores apuntan a data-tour="*"
 * en el template; si alguno no está montado al disparar el composable lo
 * saltea automáticamente.
 */
export const oilTypesTourSteps = (t) => [
    { element: '[data-tour="filters"]',       popover: { title: t('oil_types.tour.step2_title'), description: t('oil_types.tour.step2_body') }},
    { element: '[data-tour="saved-views"]',   popover: { title: t('oil_types.tour.step3_title'), description: t('oil_types.tour.step3_body') }},
    { element: '[data-tour="columns"]',       popover: { title: t('oil_types.tour.step4_title'), description: t('oil_types.tour.step4_body') }},
    { element: '[data-tour="export-import"]', popover: { title: t('oil_types.tour.step5_title'), description: t('oil_types.tour.step5_body') }},
    { element: '[data-tour="favorites"]',     popover: { title: t('oil_types.tour.step7_title'), description: t('oil_types.tour.step7_body') }},
    { element: '[data-tour="bulk"]',          popover: { title: t('oil_types.tour.step8_title'), description: t('oil_types.tour.step8_body') }},
];
