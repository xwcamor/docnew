/**
 * Fechas que NO son instantes: se leen tal cual, sin tocar la zona horaria.
 *
 * En este producto conviven dos clases de fecha y confundirlas cuesta cinco
 * horas de desfase:
 *
 * 1. **Instantes.** Los estampa el servidor con `now()` — `signed_at`,
 *    `submitted_at`, `reopened_at`, `created_at`. Viajan en UTC y explícitos
 *    (`2026-08-12T06:06:00.000000Z`), y **hay que convertirlos al huso del
 *    usuario**: para eso está `useDateFormat()`, que resuelve el huso con
 *    `App\Support\Tz`. NO uses este fichero para ellos.
 *
 * 2. **Fechas de calendario.** Las escribe una persona en un formulario —
 *    `date_start` y `date_end` del plan— y significan lo que dicen: «el trabajo
 *    empieza a las 07:00», la hora del reloj de la obra. Viajan sin zona
 *    (`2026-08-12 07:00`, cast `datetime:Y-m-d H:i`) y pasarlas por un huso las
 *    estropearía: las 07:00 no son «las 12:00 UTC» para quien las escribió.
 *    **Éstas son las de aquí.**
 *
 * La regla corta: si lo escribió una persona, literal. Si lo estampó el
 * servidor, `useDateFormat`.
 *
 * Por eso se corta la cadena a mano en vez de usar `dayjs` o `Date`: cualquiera
 * de los dos aplicaría un huso, que es justo lo que no se quiere.
 */

/** «2026-08-12 07:00» → «12-08-2026». */
export const diaLiteral = (valor) => {
    if (!valor) return '';

    const [y, m, d] = String(valor).slice(0, 10).split('-');

    return d ? `${d}-${m}-${y}` : String(valor);
};

/** «2026-08-12 07:00» → «12-08-2026 07:00». Sin hora, sólo el día. */
export const horaLiteral = (valor) => {
    if (!valor) return '';

    const hora = String(valor).slice(11, 16);

    return hora ? `${diaLiteral(valor)} ${hora}` : diaLiteral(valor);
};
