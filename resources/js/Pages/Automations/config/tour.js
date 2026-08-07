/**
 * Onboarding tour del módulo Automations. Selectores apuntan a
 * data-tour="*" en el template; si alguno no está montado al disparar
 * (ej. trash sin super, audit sin permiso), el composable lo
 * saltea automáticamente.
 *
 * Orden: intro -> filtros -> vistas/columnas -> editar todo -> favoritos ->
 * bulk -> papelera -> audit -> nuevo registro -> botón de ayuda.
 */
export const automationsTourSteps = (t) => [
    { element: '[data-tour="filters"]',      popover: { title: t('automations.tour.step2_title'),  description: t('automations.tour.step2_body') }},
    { element: '[data-tour="saved-views"]',  popover: { title: t('automations.tour.step3_title'),  description: t('automations.tour.step3_body') }},
    { element: '[data-tour="columns"]',      popover: { title: t('automations.tour.step4_title'),  description: t('automations.tour.step4_body') }},
    { element: '[data-tour="edit-all"]',     popover: { title: t('automations.tour.step5_title'),  description: t('automations.tour.step5_body') }},
    { element: '[data-tour="favorites"]',    popover: { title: t('automations.tour.step6_title'),  description: t('automations.tour.step6_body') }},
    { element: '[data-tour="bulk"]',         popover: { title: t('automations.tour.step7_title'),  description: t('automations.tour.step7_body') }},
    { element: '[data-tour="trash"]',        popover: { title: t('automations.tour.step8_title'),  description: t('automations.tour.step8_body') }},
    { element: '[data-tour="new-record"]',   popover: { title: t('automations.tour.step10_title'), description: t('automations.tour.step10_body') }},
    { element: '[data-tour="tour-help"]',    popover: { title: t('automations.tour.step11_title'), description: t('automations.tour.step11_body') }},
];
