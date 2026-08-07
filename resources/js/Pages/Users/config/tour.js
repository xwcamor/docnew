/**
 * Onboarding tour del módulo Users. Los selectores apuntan a data-tour="*" en
 * el template; si alguno no está montado el composable lo saltea solo.
 */
export const usersTourSteps = (t) => [
    { element: '[data-tour="filters"]',       popover: { title: t('users.tour.step2_title'), description: t('users.tour.step2_body') }},
    { element: '[data-tour="saved-views"]',   popover: { title: t('users.tour.step3_title'), description: t('users.tour.step3_body') }},
    { element: '[data-tour="columns"]',       popover: { title: t('users.tour.step4_title'), description: t('users.tour.step4_body') }},
    { element: '[data-tour="export-import"]', popover: { title: t('users.tour.step5_title'), description: t('users.tour.step5_body') }},
    { element: '[data-tour="edit-all"]',      popover: { title: t('users.tour.step6_title'), description: t('users.tour.step6_body') }},
    { element: '[data-tour="bulk"]',          popover: { title: t('users.tour.step8_title'), description: t('users.tour.step8_body') }},
];
