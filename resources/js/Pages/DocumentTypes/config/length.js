/**
 * La longitud del número, dicha en palabras.
 *
 * Dos columnas («7» y «8») no significan nada en una tabla a pleno sol; «de 7 a
 * 8 caracteres» sí. Y un tipo puede declarar sólo el mínimo, sólo el máximo o
 * ninguno de los dos, así que hay cuatro frases y no una.
 *
 * Recordatorio de por qué esto es informativo y no un filtro: las longitudes
 * son ayuda al dar de alta a una persona, NO la condición para buscarla — el
 * buscador de trabajadores va por coincidencia exacta del número porque en el
 * volcado hay dos peruanos con DNI de 7 caracteres.
 */
export const documentTypeLengthLabel = (t, record) => {
    const min = record?.min_length ?? null;
    const max = record?.max_length ?? null;

    if (min && max) return t('document_types.length_range', { min, max });
    if (min)        return t('document_types.length_min_only', { min });
    if (max)        return t('document_types.length_max_only', { max });

    return t('document_types.length_none');
};
