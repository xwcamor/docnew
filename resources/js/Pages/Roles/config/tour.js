/**
 * Onboarding tour del módulo Roles. Los selectores apuntan a data-tour="*" en
 * el template; si alguno no está montado el composable lo saltea solo.
 */
export const rolesTourSteps = (t) => [
    { element: '[data-tour="filters"]',       popover: { title: t('roles.tour.step2_title'), description: t('roles.tour.step2_body') }},
    { element: '[data-tour="saved-views"]',   popover: { title: t('roles.tour.step3_title'), description: t('roles.tour.step3_body') }},
    { element: '[data-tour="columns"]',       popover: { title: t('roles.tour.step4_title'), description: t('roles.tour.step4_body') }},
    { element: '[data-tour="export-import"]', popover: { title: t('roles.tour.step5_title'), description: t('roles.tour.step5_body') }},
    { element: '[data-tour="edit-all"]',      popover: { title: t('roles.tour.step6_title'), description: t('roles.tour.step6_body') }},
    { element: '[data-tour="favorites"]',     popover: { title: t('roles.tour.step7_title'), description: t('roles.tour.step7_body') }},
    { element: '[data-tour="bulk"]',          popover: { title: t('roles.tour.step8_title'), description: t('roles.tour.step8_body') }},
];
