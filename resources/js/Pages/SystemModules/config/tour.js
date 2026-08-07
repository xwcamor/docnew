/**
 * Onboarding tour completo del modulo. Los selectores apuntan a data-tour="*"
 * en el template; si alguno no esta montado al disparar (ej. trash sin
 * super, audit sin permiso) el composable lo saltea automaticamente.
 *
 * Orden: intro -> filtros -> guardar/columnas/exportar -> editar todo ->
 * favoritos (estrella) -> bulk -> papelera -> audit -> nuevo registro ->
 * boton de ayuda del tour.
 */
export const system_modulesTourSteps = (t) => [
    { popover: { title: t('system_modules.tour.step1_title'), description: t('system_modules.tour.step1_body') }},
    { element: '[data-tour="filters"]',       popover: { title: t('system_modules.tour.step2_title'),  description: t('system_modules.tour.step2_body') }},
    { element: '[data-tour="saved-views"]',   popover: { title: t('system_modules.tour.step3_title'),  description: t('system_modules.tour.step3_body') }},
    { element: '[data-tour="columns"]',       popover: { title: t('system_modules.tour.step4_title'),  description: t('system_modules.tour.step4_body') }},
    { element: '[data-tour="export-import"]', popover: { title: t('system_modules.tour.step5_title'),  description: t('system_modules.tour.step5_body') }},
    { element: '[data-tour="edit-all"]',      popover: { title: t('system_modules.tour.step6_title'),  description: t('system_modules.tour.step6_body') }},
    { element: '[data-tour="favorites"]',     popover: { title: t('system_modules.tour.step7_title'),  description: t('system_modules.tour.step7_body') }},
    { element: '[data-tour="bulk"]',          popover: { title: t('system_modules.tour.step8_title'),  description: t('system_modules.tour.step8_body') }},
    { element: '[data-tour="trash"]',         popover: { title: t('system_modules.tour.step10_title'), description: t('system_modules.tour.step10_body') }},
    { element: '[data-tour="new-record"]',    popover: { title: t('system_modules.tour.step12_title'), description: t('system_modules.tour.step12_body') }},
    { element: '[data-tour="tour-help"]',     popover: { title: t('system_modules.tour.step9_title'),  description: t('system_modules.tour.step9_body') }},
];