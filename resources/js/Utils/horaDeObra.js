/**
 * `dd-mm-aaaa hh:mm` — la hora tal y como la escribió la obra.
 *
 * **No se reinterpreta la zona**, y es a propósito: la hora que viaja en un
 * plan es la del sitio donde se firmó, y pasarla por `new Date()` la
 * convertiría a la del navegador de quien mira. Un AST firmado a las 06:12 en
 * Lima no puede leerse «11:12» porque lo abra alguien desde Madrid — la firma
 * dice cuándo se estaba en el andamio, y eso no cambia con quien lo lee.
 *
 * Por eso se corta la cadena a mano en vez de usar `dayjs` o `Date`: el
 * servidor manda «Y-m-d H:i» y aquí sólo se reordena.
 *
 * Vivía copiada en `WorkPlanBoardRow`, `WorkPlanApprovalsCard`, la ficha del
 * plan y su listado —cuatro copias de las mismas cinco líneas—, y ahora que
 * las tarjetas también la necesitan iban a ser seis.
 *
 * @param {?string} valor  «2026-08-12 06:12:33» o ISO
 * @returns {string}       «12-08-2026 06:12», o cadena vacía si no hay nada
 */
export const horaDeObra = (valor) => {
    if (!valor) return '';

    const s = String(valor);
    const [y, m, d] = s.slice(0, 10).split('-');

    return d ? `${d}-${m}-${y} ${s.slice(11, 16)}` : s;
};
