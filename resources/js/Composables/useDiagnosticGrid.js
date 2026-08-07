import { ref, computed, nextTick, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * useDiagnosticGrid — lógica de la grilla editable estilo Excel compartida por
 * las pruebas de diagnóstico (cromas, furanos, fiquis, fpot). Maneja:
 *   - filas draft + fila fantasma para agregar,
 *   - detección de cambios (dirty) vs el server,
 *   - navegación con teclado (Tab/Enter/flechas) y pegado masivo (TSV),
 *   - guardado por lote (un request, un recálculo del HI),
 *   - live preview del diagnóstico (corre el motor sin persistir, con debounce).
 *
 * El componente solo dibuja; toda la mecánica vive aquí. Genérico sobre las
 * columnas numéricas de cada prueba (`numericKeys`); la fecha es la columna 0.
 *
 * @param {object}   o
 * @param {string[]} o.numericKeys     columnas numéricas editables (orden de tabla)
 * @param {string[]} o.requiredKeys    claves que debe traer una fila para guardarse
 * @param {() => any} o.source         getter de las filas del server (props.samples)
 * @param {string}   o.transformerSlug
 * @param {string}   o.batchRoute      nombre de ruta del guardado por lote
 * @param {string}   o.previewRoute    nombre de ruta del preview en vivo
 * @param {(row:any) => any} o.seedDiag  server row → objeto _diag inicial
 * @param {boolean}  o.canEdit
 */
export function useDiagnosticGrid(o) {
    const {
        numericKeys, requiredKeys = [], requireAnyOf = [], source, transformerSlug,
        batchRoute, previewRoute, seedDiag = () => null, canEdit = true,
    } = o;

    // "Al menos uno de" — acepta UN grupo (['h2','ch4',…]: cromas pide al menos
    // un gas de falla) o VARIOS ([['rig','rig877'],['pot','pot100']]: fiquis pide
    // uno de cada par de métodos, porque con cualquiera de los dos la propiedad
    // ya entra al diagnóstico).
    const anyOfGroups = Array.isArray(requireAnyOf[0])
        ? requireAnyOf
        : (requireAnyOf.length ? [requireAnyOf] : []);
    const grupoVacio = (r, g) => g.every((k) => r[k] === '' || r[k] == null);

    /** La fila incumple algún grupo. */
    const missesAnyOf = (r) => anyOfGroups.some((g) => grupoVacio(r, g));
    /** Incumple el grupo al que pertenece esta columna (para marcar la celda). */
    const missesAnyOfKey = (r, key) =>
        anyOfGroups.some((g) => g.includes(key) && grupoVacio(r, g));

    const COLS = ['sample_date', ...numericKeys]; // columnas editables (índices de nav)

    let uid = 0;
    const nextKey = () => `r${++uid}`;

    const makeRow = (src = null) => ({
        key: nextKey(),
        id: src?.id ?? null,
        sample_date: src?.sample_date ?? '',
        report_number: src?.report_number ?? '',
        laboratory_id: src?.laboratory_id ?? null,
        ...Object.fromEntries(numericKeys.map((k) => [k, src && src[k] != null ? String(src[k]) : ''])),
        _deleted: false,
        _diag: src ? seedDiag(src) : null,
        comments_count: src?.comments_count ?? 0,
    });

    const rows = ref([]);
    let originalById = {};

    const rowIsEmpty = (r) => !r.sample_date && numericKeys.every((k) => r[k] === '' || r[k] == null);

    const snapshot = (r) => JSON.stringify({
        sample_date: r.sample_date || '',
        report_number: r.report_number || '',
        laboratory_id: r.laboratory_id ?? '',
        ...Object.fromEntries(numericKeys.map((k) => [k, r[k] === '' || r[k] == null ? '' : String(r[k])])),
    });

    const init = () => {
        originalById = {};
        const list = (source() || []).map((c) => {
            const row = makeRow(c);
            originalById[c.id] = snapshot(row);
            return row;
        });
        if (canEdit) list.push(makeRow());
        rows.value = list;
        applySort(); // por defecto: fecha más reciente primero (DESC)
    };

    // ── Orden de columnas (la fila fantasma siempre queda al final) ───────
    const sortKey = ref('sample_date');
    const sortDir = ref('desc');

    // Severidad del semáforo para ordenar la columna Estado (verde<amarillo<rojo).
    const severity = (c) => (c === 'green' ? 1 : c === 'yellow' ? 2 : c === 'red' ? 3 : null);

    const compareRows = (a, b) => {
        const aGhost = a.id == null && rowIsEmpty(a);
        const bGhost = b.id == null && rowIsEmpty(b);
        if (aGhost !== bGhost) return aGhost ? 1 : -1; // fantasma al final
        if (aGhost && bGhost) return 0;

        const k = sortKey.value;
        let av;
        let bv;
        if (k === 'sample_date') {
            av = a[k] || null; bv = b[k] || null;
        } else if (k === 'report_number') {
            // N° de informe: alfanumérico → comparación de texto (no numérica).
            av = (a[k] === '' || a[k] == null) ? null : String(a[k]);
            bv = (b[k] === '' || b[k] == null) ? null : String(b[k]);
        } else if (k === '_diag.dp') {
            // Columna de diagnóstico DP (vive en row._diag, numérica).
            av = a._diag?.dp ?? null; bv = b._diag?.dp ?? null;
        } else if (k === '_diag.state') {
            // Estado: por severidad del semáforo, no alfabético.
            av = severity(a._diag?.color); bv = severity(b._diag?.color);
        } else {
            av = (a[k] === '' || a[k] == null) ? null : Number(a[k]);
            bv = (b[k] === '' || b[k] == null) ? null : Number(b[k]);
        }

        if (av === null && bv === null) return 0;
        if (av === null) return 1; // vacíos al final
        if (bv === null) return -1;
        const r = (k === 'sample_date')
            ? (av < bv ? -1 : (av > bv ? 1 : 0))
            : (k === 'report_number')
                ? String(av).localeCompare(String(bv), undefined, { numeric: true })
                : (av - bv);
        return sortDir.value === 'desc' ? -r : r;
    };
    const applySort = () => { rows.value = [...rows.value].sort(compareRows); };
    const sortBy = (key) => {
        if (sortKey.value === key) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        else { sortKey.value = key; sortDir.value = key === 'sample_date' ? 'desc' : 'asc'; }
        applySort();
    };

    init();
    // Tras guardar, Inertia recarga la vista → re-armamos la grilla limpia.
    watch(source, init);

    // ── Cambios pendientes ───────────────────────────────────────────────
    const upserts = computed(() => rows.value.filter((r) =>
        !r._deleted && !rowIsEmpty(r) && (r.id == null || snapshot(r) !== originalById[r.id]),
    ));
    const deletes = computed(() => rows.value.filter((r) => r._deleted && r.id != null).map((r) => r.id));
    const dirtyCount = computed(() => upserts.value.length + deletes.value.length);
    const isDirtyRow = (r) => (r.id == null ? !rowIsEmpty(r) : snapshot(r) !== originalById[r.id]);

    // ── Fila fantasma + preview tras cada cambio ─────────────────────────
    const ensureGhost = () => {
        if (!canEdit) return;
        const last = rows.value[rows.value.length - 1];
        if (!last || last.id != null || !rowIsEmpty(last)) rows.value.push(makeRow());
    };
    const touched = () => { ensureGhost(); schedulePreview(); };

    // ── Navegación entre celdas ──────────────────────────────────────────
    const cellEls = {};
    const cellId = (r, c) => `${r}:${c}`;
    const setCell = (el, r, c) => { if (el) cellEls[cellId(r, c)] = el; else delete cellEls[cellId(r, c)]; };
    const focusCell = (r, c) => {
        const rr = Math.max(0, Math.min(rows.value.length - 1, r));
        const cc = Math.max(0, Math.min(COLS.length - 1, c));
        nextTick(() => { const el = cellEls[cellId(rr, cc)]; if (el) { el.focus(); el.select?.(); } });
    };

    const onKeydown = (e, r, c) => {
        const el = e.target;
        if (e.key === 'Enter') { e.preventDefault(); ensureGhost(); focusCell(r + 1, c); }
        else if (e.key === 'Tab') {
            e.preventDefault();
            if (e.shiftKey) (c > 0 ? focusCell(r, c - 1) : focusCell(r - 1, COLS.length - 1));
            else { ensureGhost(); (c < COLS.length - 1 ? focusCell(r, c + 1) : focusCell(r + 1, 0)); }
        }
        else if (e.key === 'ArrowDown') { e.preventDefault(); ensureGhost(); focusCell(r + 1, c); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); focusCell(r - 1, c); }
        else if (e.key === 'ArrowRight' && el.selectionStart === el.value.length) { e.preventDefault(); focusCell(r, c + 1); }
        else if (e.key === 'ArrowLeft' && el.selectionStart === 0) { e.preventDefault(); focusCell(r, c - 1); }
    };

    // ── Entrada de datos ─────────────────────────────────────────────────
    const sanitizeNum = (raw) => {
        let v = (raw ?? '').toString().replace(',', '.').replace(/[^0-9.]/g, '');
        const parts = v.split('.');
        if (parts.length > 2) v = parts[0] + '.' + parts.slice(1).join('');
        return v;
    };
    const setCellValue = (row, col, raw) => {
        row[col] = col === 'sample_date' ? (raw ?? '').trim() : sanitizeNum(raw);
    };
    const onInput = (e, r, c) => {
        const row = rows.value[r];
        const col = COLS[c];
        if (col === 'sample_date') { row[col] = e.target.value; }
        else { const v = sanitizeNum(e.target.value); row[col] = v; if (e.target.value !== v) e.target.value = v; }
        touched();
    };

    // Pegado masivo desde Excel (TSV: tabs = columnas, saltos = filas).
    const onPaste = (e, r, c) => {
        const text = (e.clipboardData || window.clipboardData)?.getData('text') ?? '';
        if (!text || !/[\t\n]/.test(text)) return;
        e.preventDefault();
        const matrix = text.replace(/\r/g, '').replace(/\n+$/, '').split('\n').map((l) => l.split('\t'));
        matrix.forEach((line, i) => {
            while (r + i > rows.value.length - 1) rows.value.push(makeRow());
            const row = rows.value[r + i];
            line.forEach((cell, j) => {
                const ci = c + j;
                if (ci < COLS.length) setCellValue(row, COLS[ci], cell);
            });
        });
        touched();
    };

    // ── Preview en vivo (debounce) ───────────────────────────────────────
    let previewTimer = null;
    const schedulePreview = () => { clearTimeout(previewTimer); previewTimer = setTimeout(runPreview, 400); };
    const numericPayload = (r) => Object.fromEntries(numericKeys.map((k) => [k, r[k] === '' ? null : Number(r[k])]));
    const runPreview = async () => {
        const payloadRows = rows.value
            .filter((r) => !r._deleted && !rowIsEmpty(r))
            .map((r) => ({ key: r.key, sample_date: r.sample_date || null, ...numericPayload(r) }));
        if (!payloadRows.length) return;
        try {
            const { data } = await window.axios.post(route(previewRoute, transformerSlug), { rows: payloadRows });
            const byKey = Object.fromEntries((data.rows || []).map((d) => [d.key, d]));
            rows.value.forEach((r) => { if (byKey[r.key]) r._diag = byKey[r.key]; });
        } catch (_) { /* best-effort */ }
    };

    // ── Borrar / restaurar fila ──────────────────────────────────────────
    const toggleDelete = (r) => {
        if (r.id == null) { rows.value = rows.value.filter((x) => x !== r); ensureGhost(); }
        else { r._deleted = !r._deleted; }
        schedulePreview();
    };

    // ── Guardar / descartar ──────────────────────────────────────────────
    const saving = ref(false);
    const saveAll = ({ onInvalid, onError } = {}) => {
        if (dirtyCount.value === 0) return;
        const invalid = upserts.value.some((r) =>
            !r.sample_date
            || requiredKeys.some((k) => r[k] === '' || r[k] == null)
            || missesAnyOf(r),
        );
        if (invalid) { onInvalid?.(); return; }
        saving.value = true;
        const payload = {
            upserts: upserts.value.map((r) => ({ id: r.id, sample_date: r.sample_date, report_number: r.report_number || null, laboratory_id: r.laboratory_id || null, ...numericPayload(r) })),
            deletes: deletes.value,
        };
        router.post(route(batchRoute, transformerSlug), payload, {
            preserveScroll: true,
            onError: (errors) => { onError?.(errors); },
            onFinish: () => { saving.value = false; },
        });
    };
    const discard = () => init();

    return {
        rows, COLS, numericKeys,
        upserts, deletes, dirtyCount, isDirtyRow, rowIsEmpty, missesAnyOf, missesAnyOfKey,
        setCell, onKeydown, onInput, onPaste,
        toggleDelete, saving, saveAll, discard,
        sortKey, sortDir, sortBy,
    };
}
