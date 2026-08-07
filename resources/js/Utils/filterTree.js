/**
 * Utilidades del árbol de filtros avanzados (grupos anidados).
 *   regla = clausula { field, op, value }  |  grupo { conj, rules: [regla...] }
 * Las reglas viven en un array plano en el nivel raíz (advanced_where) + el
 * conector raíz (advanced_conjunction); los sub-grupos van como reglas.
 */

const isGroup = (r) => r && Array.isArray(r.rules);

const clauseComplete = (c) => {
    if (!c || !c.field || !c.op) return false;
    if (Array.isArray(c.value)) return c.value.length > 0;
    return c.value !== '' && c.value !== null && c.value !== undefined;
};

/** Cuenta cláusulas COMPLETAS en todo el árbol (recursivo). */
export const countClauses = (rules = []) =>
    (rules ?? []).reduce((n, r) => n + (isGroup(r) ? countClauses(r.rules) : (clauseComplete(r) ? 1 : 0)), 0);

/**
 * Poda recursiva: descarta cláusulas incompletas y grupos que quedan vacíos.
 * Devuelve un array nuevo de reglas listo para enviar al server.
 */
export const pruneRules = (rules = []) => {
    const out = [];
    for (const r of rules ?? []) {
        if (isGroup(r)) {
            const kids = pruneRules(r.rules);
            if (kids.length > 0) out.push({ conj: r.conj === 'or' ? 'or' : 'and', rules: kids });
        } else if (clauseComplete(r)) {
            out.push(r);
        }
    }
    return out;
};
