/**
 * La longitud del número, dicha en palabras.
 *
 * Dos columnas («7» y «8») no significan nada en una tabla a pleno sol; «de 7 a
 * 8 caracteres» sí. Y un tipo puede declarar sólo el mínimo, sólo el máximo o
 * ninguno de los dos, así que hay cuatro frases y no una.
 *
 * Recordatorio de por qué esto se aplica al dar de alta y no al buscar: el
 * buscador de trabajadores va por coincidencia exacta del número, así que
 * alguien ya migrado con un documento raro se sigue encontrando aunque hoy no
 * se pudiera dar de alta.
 */
export const documentTypeLengthLabel = (t, record) => {
    const min = record?.min_length ?? null;
    const max = record?.max_length ?? null;

    if (min && max) return t('document_types.length_range', { min, max });
    if (min)        return t('document_types.length_min_only', { min });
    if (max)        return t('document_types.length_max_only', { max });

    return t('document_types.length_none');
};

/**
 * A quién pertenece el documento: «Persona» o «Empresa».
 *
 * Vive aquí junto al resto de etiquetas del catálogo porque la pintan tres
 * pantallas —el listado, la ficha y la papelera— y escrita tres veces se
 * separa en cuanto alguien cambia una. Lo que llega del backend es `person` /
 * `company`, que es lo que hay en la columna; eso no se enseña nunca.
 */
export const documentTypeScopeLabel = (t, record) => ({
    person:  t('document_types.scope_person'),
    company: t('document_types.scope_company'),
}[record?.scope] ?? t('document_types.scope_person'));

/** Qué admite el número: «Solo números», «Números y letras» o sin restricción. */
export const documentTypeCharsLabel = (t, record) => ({
    digits:       t('document_types.allowed_chars_digits'),
    alphanumeric: t('document_types.allowed_chars_alphanumeric'),
}[record?.allowed_chars] ?? t('document_types.allowed_chars_any'));
