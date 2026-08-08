/**
 * Tour del módulo. El paso de la vista previa es el que importa: es lo que
 * responde a «¿y esto qué acabo de configurar?».
 */
export const approvalRulesTourSteps = (t) => [
    { element: '[data-tour="preview"]',          popover: { title: t('approval_rules.tour.step7_title'), description: t('approval_rules.tour.step7_body') }},
    { element: '[data-tour="advanced-filters"]', popover: { title: t('approval_rules.tour.step2_title'), description: t('approval_rules.tour.step2_body') }},
    { element: '[data-tour="saved-views"]',      popover: { title: t('approval_rules.tour.step3_title'), description: t('approval_rules.tour.step3_body') }},
    { element: '[data-tour="export-import"]',    popover: { title: t('approval_rules.tour.step5_title'), description: t('approval_rules.tour.step5_body') }},
    { element: '[data-tour="bulk"]',             popover: { title: t('approval_rules.tour.step8_title'), description: t('approval_rules.tour.step8_body') }},
];
