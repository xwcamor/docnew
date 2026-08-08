/**
 * Tour del módulo. Los selectores apuntan a los data-tour="*" de la plantilla;
 * si alguno no está montado, el composable lo salta.
 */
export const approverRolesTourSteps = (t) => [
    { element: '[data-tour="advanced-filters"]', popover: { title: t('approver_roles.tour.step2_title'), description: t('approver_roles.tour.step2_body') }},
    { element: '[data-tour="saved-views"]',      popover: { title: t('approver_roles.tour.step3_title'), description: t('approver_roles.tour.step3_body') }},
    { element: '[data-tour="export-import"]',    popover: { title: t('approver_roles.tour.step5_title'), description: t('approver_roles.tour.step5_body') }},
    { element: '[data-tour="bulk"]',             popover: { title: t('approver_roles.tour.step8_title'), description: t('approver_roles.tour.step8_body') }},
];
