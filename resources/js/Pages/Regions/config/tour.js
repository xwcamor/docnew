/**
 * Onboarding tour completo del modulo. Los selectores apuntan a data-tour="*"
 * en el template; si alguno no esta montado al disparar (ej. trash sin
 * super, audit sin permiso) el composable lo saltea automaticamente.
 *
 * Orden: intro -> filtros -> guardar/columnas/exportar -> editar todo ->
 * favoritos (estrella) -> bulk -> papelera -> audit -> nuevo registro ->
 * boton de ayuda del tour.
 */
export const regionsTourSteps = (t) => [
    { popover: { title: t('regions.tour.step1_title'), description: t('regions.tour.step1_body') }},
    { element: '[data-tour="filters"]',       popover: { title: t('regions.tour.step2_title'),  description: t('regions.tour.step2_body') }},
    { element: '[data-tour="saved-views"]',   popover: { title: t('regions.tour.step3_title'),  description: t('regions.tour.step3_body') }},
    { element: '[data-tour="columns"]',       popover: { title: t('regions.tour.step4_title'),  description: t('regions.tour.step4_body') }},
    { element: '[data-tour="export-import"]', popover: { title: t('regions.tour.step5_title'),  description: t('regions.tour.step5_body') }},
    { element: '[data-tour="edit-all"]',      popover: { title: t('regions.tour.step6_title'),  description: t('regions.tour.step6_body') }},
    { element: '[data-tour="favorites"]',     popover: { title: t('regions.tour.step7_title'),  description: t('regions.tour.step7_body') }},
    { element: '[data-tour="bulk"]',          popover: { title: t('regions.tour.step8_title'),  description: t('regions.tour.step8_body') }},
    { element: '[data-tour="trash"]',         popover: { title: t('regions.tour.step10_title'), description: t('regions.tour.step10_body') }},
    { element: '[data-tour="new-record"]',    popover: { title: t('regions.tour.step12_title'), description: t('regions.tour.step12_body') }},
    { element: '[data-tour="tour-help"]',     popover: { title: t('regions.tour.step9_title'),  description: t('regions.tour.step9_body') }},
];