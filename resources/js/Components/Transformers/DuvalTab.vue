<script setup>
import { computed, reactive, ref, watch, nextTick } from 'vue';
import { Empty, Segmented } from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';

/**
 * DuvalTab — método gráfico de Duval (DGA, aceite mineral).
 *
 * Triángulos 1/4/5 y pentágonos 1/2/combinado. Zonas (fondo) desde `geometry`;
 * diagnóstico de cada ensayo en `cromas[].duval`. Visibilidad de T4/T5 según la zona
 * de T1 del último ensayo. Dos vistas: último ensayo o todos (con trayectoria).
 *
 * UI: leyenda de zonas por gráfico, tooltip grande al pasar el mouse (datos del
 * ensayo), y efectos de entrada/pulso suaves (sin costo de performance).
 */
const props = defineProps({
    cromas:   { type: Array,  default: () => [] },
    geometry: { type: Object, default: () => ({ triangles: {}, pentagons: {} }) },
    // Calculador standalone (Tools): fuerza el modo (triangle|pentagon) desde un
    // tab externo y oculta los controles internos (Segmented modo/scope).
    forceMode:    { type: String,  default: null },
    hideControls: { type: Boolean, default: false },
});

const { t } = useI18n();
const mode = ref(props.forceMode || 'triangle');
watch(() => props.forceMode, (m) => { if (m) mode.value = m; });
const scope = ref('last');

const COLORS = {
    PD: '#7E57C2', T1: '#64B5F6', T2: '#9FA8DA', T3: '#D1D9F5', D1: '#B3E5FC', D2: '#2962FF', DT: '#0288D1',
    S: '#4DD0E1', C: '#26A69A', O: '#26C281', ND: '#80CBC4',
    'T1-O': '#A5D6B7', 'T1-C': '#ECEFF1', 'T2-O': '#FFF8E1', 'T2-C': '#90CAF9', 'T3-C': '#E1BEE7', 'T3-H': '#CE93D8',
    // Zonas del Triángulo 2 (conmutador LTC)
    N: '#66BB6A', X1: '#FFB74D', X3: '#FF8A65',
};
const zoneColor = (c) => COLORS[c] ?? '#B0BEC5';

const TRI_VERTS = {
    T1: { apex: 'CH₄', br: 'C₂H₄', bl: 'C₂H₂' },
    T2: { apex: 'CH₄', br: 'C₂H₄', bl: 'C₂H₂' },
    T3: { apex: 'CH₄', br: 'C₂H₄', bl: 'C₂H₂' },
    T4: { apex: 'H₂',  br: 'CH₄',  bl: 'C₂H₆' },
    T5: { apex: 'CH₄', br: 'C₂H₄', bl: 'C₂H₆' },
};
const TRI_ORDER = ['T1', 'T2', 'T3', 'T4', 'T5'];
// Títulos traducibles (se rearman al cambiar de idioma porque t() es reactivo).
const triTitle = (k) => {
    const base = `${t('cromas.duval_tri')} ${k.slice(1)}`;
    return k === 'T2' ? `${base} (LTC)` : base;
};
const pentTitle = (k) => (k === 'combine' ? t('cromas.duval_combined') : `${t('cromas.duval_pent')} ${k.slice(1)}`);
const PENT_VERTS = [
    { t: 'H₂', x: 0, y: 40 }, { t: 'C₂H₂', x: 38, y: 12.4 }, { t: 'C₂H₄', x: 23.5, y: -32.4 },
    { t: 'CH₄', x: -23.5, y: -32.4 }, { t: 'C₂H₆', x: -38, y: 12.4 },
];
const GAS_SUB = { h2: 'H₂', ch4: 'CH₄', c2h4: 'C₂H₄', c2h6: 'C₂H₆', c2h2: 'C₂H₂' };

const triXY = ([x, y]) => ({ x: 30 + x * 340, y: 24 + (0.866 - y) * 346.4 });
const pentXY = ([x, y]) => ({ x: 220 + x * 4.6, y: 210 - y * 4.6 });
const polyStr = (pts, proj) => pts.map((p) => { const q = proj(p); return `${q.x.toFixed(1)},${q.y.toFixed(1)}`; }).join(' ');
const centroid = (pts, proj) => {
    const qs = pts.map(proj);
    return { x: qs.reduce((s, q) => s + q.x, 0) / qs.length, y: qs.reduce((s, q) => s + q.y, 0) / qs.length };
};
const byDate = (arr) => [...arr].sort((a, b) => (a.sample_date < b.sample_date ? -1 : 1));
const pctPairs = (pct) => Object.entries(pct).map(([g, v]) => ({ g: GAS_SUB[g] ?? g, v }));

const latest = computed(() => {
    const withDuval = byDate(props.cromas.filter((c) => c.duval));
    return withDuval[withDuval.length - 1] ?? null;
});
const hasPentagons = computed(() => Object.keys(props.geometry.pentagons ?? {}).length > 0);
const triKeys = computed(() => TRI_ORDER.filter((k) => latest.value?.duval.triangles[k]?.visible));
const activeFault = computed(() => !!latest.value?.active_fault);
const hasData = computed(() => latest.value !== null
    && (mode.value === 'triangle' ? triKeys.value.length > 0 : !!latest.value.duval.pentagon));
const scopeSamples = computed(() => {
    const all = byDate(props.cromas.filter((c) => c.duval));
    return scope.value === 'last' ? all.slice(-1) : all;
});

// Leyenda: zonas únicas (en orden) con su etiqueta.
const legendOf = (zones) => {
    const seen = new Set();
    const out = [];
    zones.forEach((z) => { if (!seen.has(z.code)) { seen.add(z.code); out.push(z.code); } });
    return out;
};
// Etiquetas de zona: una por cada pieza GRANDE. Si una zona se dibuja en varias
// piezas (p.ej. T3 del Triángulo 5), etiqueta las que tienen ≥50% del área de la
// pieza mayor de su código → muestra T3 en sus 2 regiones reales, pero descarta
// slivers y duplicados chicos (p.ej. las O finas).
const polyArea = (str) => {
    const pts = str.split(' ').map((p) => p.split(',').map(Number));
    let a = 0;
    for (let i = 0; i < pts.length; i++) {
        const [x1, y1] = pts[i], [x2, y2] = pts[(i + 1) % pts.length];
        a += x1 * y2 - x2 * y1;
    }
    return Math.abs(a) / 2;
};
const labelsOf = (zones) => {
    const maxA = {};
    const withA = zones.map((z) => ({ ...z, area: polyArea(z.str) }));
    withA.forEach((z) => { maxA[z.code] = Math.max(maxA[z.code] ?? 0, z.area); });
    return withA.filter((z) => z.area >= 0.5 * maxA[z.code]);
};

// Ajuste fino de etiquetas de zona en los triángulos, por triángulo+código (px SVG).
const TRI_LABEL_NUDGE = {
    T1: { D2: { x: -20, y: 0 }, DT: { x: 8, y: 0 } },
    T4: { S: { x: -20, y: 0 }, C: { x: 14, y: 0 } },
    T5: { T3: [{ x: 40, y: 55 }, { x: 0, y: 0 }] }, // banda T3 abajo-der; 2º T3 en su sitio
};

const triCharts = computed(() => {
    if (!latest.value) return [];
    return triKeys.value
        .map((k) => {
            const nudge = TRI_LABEL_NUDGE[k] ?? {};
            const cnt = {};
            const zones = (props.geometry.triangles[k] ?? []).map((z) => {
                const cc = centroid(z.pts, triXY);
                // nudge por código; si es array, por ocurrencia (zonas en varias piezas).
                const idx = (cnt[z.code] = (cnt[z.code] ?? -1) + 1);
                let n = nudge[z.code];
                if (Array.isArray(n)) n = n[idx] ?? null;
                return {
                    code: z.code, str: polyStr(z.pts, triXY), cc,
                    c: n ? { x: cc.x + n.x, y: cc.y + n.y } : cc,
                };
            }).sort((a, b) => (a.code === 'PD') - (b.code === 'PD')); // PD al final = clickeable (no lo tapa S)
            const samples = scopeSamples.value
                .filter((c) => c.duval.triangles[k])
                .map((c) => {
                    const d = c.duval.triangles[k];
                    return { pos: triXY(d.point), zone: d.zone, date: c.sample_date, pairs: pctPairs(d.pct) };
                });
            return {
                key: k, title: triTitle(k), verts: TRI_VERTS[k], zones, samples,
                labels: labelsOf(zones), legend: legendOf(zones),
                trajectory: samples.map((s) => `${s.pos.x.toFixed(1)},${s.pos.y.toFixed(1)}`).join(' '),
                diag: latest.value.duval.triangles[k].zone,
            };
        });
});

const pentCharts = computed(() => {
    if (!latest.value?.duval.pentagon) return [];
    // Ajuste fino de la etiqueta de algunas zonas del pentágono (el centroide las
    // deja un poco corridas vs los papers de Duval). En px del SVG.
    const LABEL_NUDGE = {
        T1: { x: -6, y: -28 },   // P1
        O: { x: -14, y: 6 },     // P2: más a la izquierda
        C: { x: -2, y: 14 },     // P2: más abajo
        'T1-O': { x: -34, y: 0 }, // combinado: más a la izquierda
    };
    return ['P1', 'P2', 'combine'].map((k) => {
        const zones = (props.geometry.pentagons[k] ?? []).map((z) => {
            const cc = centroid(z.pts, pentXY);
            const n = LABEL_NUDGE[z.code];
            return {
                code: z.code, str: polyStr(z.pts, pentXY), cc,
                c: n ? { x: cc.x + n.x, y: cc.y + n.y } : cc,
            };
        }).sort((a, b) => (a.code === 'PD') - (b.code === 'PD')); // PD al final = clickeable (no lo tapa S)
        const samples = scopeSamples.value
            .filter((c) => c.duval.pentagon)
            .map((c) => ({
                pos: pentXY(c.duval.pentagon.point), zone: c.duval.pentagon.zones[k],
                date: c.sample_date, pairs: pctPairs(c.duval.pentagon.pct),
            }));
        return {
            key: k, title: pentTitle(k), zones, samples, labels: labelsOf(zones), legend: legendOf(zones),
            trajectory: samples.map((s) => `${s.pos.x.toFixed(1)},${s.pos.y.toFixed(1)}`).join(' '),
            diag: latest.value.duval.pentagon.zones[k],
        };
    });
});

const pentVert = (v) => pentXY([v.x, v.y]);
// Etiqueta de cada vértice, empujada hacia AFUERA del pentágono según su lado
// (las laterales C₂H₂/C₂H₆ se anclan a los costados; si no, caen dentro).
const pentLabel = (v) => {
    const p = pentXY([v.x, v.y]);
    if (v.y >= 30) return { x: p.x, y: p.y - 9, anchor: 'middle' };   // H₂ (arriba)
    if (v.y <= -30) return { x: p.x, y: p.y + 18, anchor: 'middle' }; // CH₄/C₂H₄ (abajo)
    if (v.x > 0) return { x: p.x + 9, y: p.y + 4, anchor: 'start' };  // C₂H₂ (derecha)
    return { x: p.x - 9, y: p.y + 4, anchor: 'end' };                 // C₂H₆ (izquierda)
};
const charts = computed(() => (mode.value === 'triangle' ? triCharts.value : pentCharts.value));
const modeOptions = computed(() => {
    const opts = [{ label: t('cromas.duval_triangles'), value: 'triangle' }];
    if (hasPentagons.value) opts.push({ label: t('cromas.duval_pentagons'), value: 'pentagon' });
    return opts;
});
// Si el aceite no tiene pentágonos y quedó seleccionado, vuelve a triángulos.
watch(hasPentagons, (has) => { if (!props.forceMode && !has && mode.value === 'pentagon') mode.value = 'triangle'; }, { immediate: true });
const scopeOptions = computed(() => [
    { label: t('cromas.duval_scope_last'), value: 'last' },
    { label: t('cromas.duval_scope_all'), value: 'all' },
]);
const zoneLabel = (code) => (code ? t(`cromas.zone.${code}`) : '—');

// ── Zoom (rueda + botones) y resaltado de zona (click) ──────────────────────
// Estado de vista por gráfico (clave estable T1/T4/.../P1/...). NUNCA va al PDF:
// la captura (svgToPng) quita el transform de zoom y el pop-out del clon.
const view = reactive({});
const picked = reactive({});
const vget = (key) => view[key] || (view[key] = { k: 1, x: 0, y: 0 });
const zoomTransform = (key) => { const v = vget(key); return `translate(${v.x},${v.y}) scale(${v.k})`; };
const isZoomed = (key) => vget(key).k > 1.001;
const svgPoint = (svg, e) => {
    const ctm = svg.getScreenCTM();
    if (!ctm) return null;
    const p = new DOMPoint(e.clientX, e.clientY).matrixTransform(ctm.inverse());
    return { x: p.x, y: p.y };
};
// Zoom manteniendo fijo el punto (px,py) en coords del viewBox.
const applyZoom = (key, factor, px, py) => {
    const v = vget(key);
    const k0 = v.k;
    const k1 = Math.min(6, Math.max(1, k0 * factor));
    v.x += (k0 - k1) * px;
    v.y += (k0 - k1) * py;
    v.k = k1;
    if (k1 <= 1.001) { v.k = 1; v.x = 0; v.y = 0; }
};
const onWheel = (key, e) => {
    const p = svgPoint(e.currentTarget, e);
    if (p) applyZoom(key, e.deltaY < 0 ? 1.2 : 1 / 1.2, p.x, p.y);
};
const zoomBtn = (key, dir) => {
    const c = mode.value === 'triangle' ? { x: 200, y: 180 } : { x: 220, y: 190 };
    applyZoom(key, dir > 0 ? 1.25 : 1 / 1.25, c.x, c.y);
};
const resetView = (key) => { const v = vget(key); v.k = 1; v.x = 0; v.y = 0; picked[key] = null; };
const pickZone = (key, code) => { picked[key] = picked[key] === code ? null : code; };

// ── Tooltip grande (sigue al mouse) ────────────────────────────────────────
const tip = ref(null); // { x, y, date, zone, pairs }
const showTip = (s, e) => { tip.value = { x: e.clientX, y: e.clientY, date: s.date, zone: s.zone, pairs: s.pairs }; };
const moveTip = (e) => { if (tip.value) { tip.value.x = e.clientX; tip.value.y = e.clientY; } };
const hideTip = () => { tip.value = null; };
const tipStyle = computed(() => {
    if (!tip.value) return {};
    const off = 16;
    return { left: `${tip.value.x + off}px`, top: `${tip.value.y + off}px` };
});

// ── Captura para el PDF: serializa cada SVG a PNG (los colores son inline). ──
const rootEl = ref(null);
const svgToPng = (svg) => new Promise((resolve) => {
    const vb = (svg.getAttribute('viewBox') || '0 0 400 360').split(' ').map(Number);
    const w = vb[2] || 400, h = vb[3] || 360;
    const clone = svg.cloneNode(true);
    // GARANTÍA PDF: la foto SIEMPRE sale en la vista completa por defecto, sin
    // importar cómo esté el zoom/resaltado en pantalla → se quita del clon.
    clone.querySelectorAll('[data-zoom]').forEach((g) => g.removeAttribute('transform'));
    clone.querySelectorAll('.duval-pop').forEach((e) => e.remove());
    clone.setAttribute('width', w);
    clone.setAttribute('height', h);
    const xml = new XMLSerializer().serializeToString(clone);
    const src = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(xml)));
    const img = new Image();
    img.onload = () => {
        const canvas = document.createElement('canvas');
        canvas.width = w * 2; canvas.height = h * 2;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        resolve(canvas.toDataURL('image/png'));
    };
    img.onerror = () => resolve(null);
    img.src = src;
});

/** Imágenes para el informe: UN solo triángulo (el principal) + UN solo
 *  pentágono, cada uno con su título. Vacío sin falla activa. */
const getReportImages = async () => {
    if (!hasData.value || !activeFault.value) return [];
    const prevMode = mode.value, prevScope = scope.value;
    scope.value = 'last';
    const out = [];
    const modes = hasPentagons.value ? ['triangle', 'pentagon'] : ['triangle'];
    for (const m of modes) {
        mode.value = m;
        await nextTick();
        await new Promise((r) => setTimeout(r, 40));
        // Solo el primer SVG (gráfico principal) de cada modo.
        const svg = rootEl.value?.querySelector('svg.duval__svg');
        if (svg) {
            const png = await svgToPng(svg);
            // zone = código de la zona donde cayó el punto del gráfico principal,
            // para que el PDF muestre un veredicto corto por gráfico.
            if (png) out.push({ label: charts.value[0]?.title || m, dataURL: png, zone: charts.value[0]?.diag ?? null });
        }
    }
    mode.value = prevMode; scope.value = prevScope;
    await nextTick();
    return out;
};
defineExpose({ getReportImages });
</script>

<template>
    <div class="duval" ref="rootEl">
        <div v-if="!hideControls" class="duval__head">
            <Segmented v-model:value="mode" :options="modeOptions" />
            <Segmented v-model:value="scope" :options="scopeOptions" />
        </div>

        <div v-if="!hasData" class="duval__empty">
            <Empty :description="$t('cromas.duval_no_data')" />
        </div>

        <!-- Sin falla activa: gases normales → el Duval no aplica. No se dibuja el
             triángulo/pentágono (sería ruido); se muestra el veredicto, como Gas Clave. -->
        <div v-else-if="!activeFault" class="duval__nofault-box">
            <span class="duval__nofault-dot"></span>
            <div>
                <strong>{{ $t('cromas.duval_current') }}</strong>
                <p>{{ $t('diagnostics.duval_no_fault') }}</p>
            </div>
        </div>

        <div v-else class="duval__grid">
            <div v-for="(ch, ci) in charts" :key="ch.key" class="duval__card" :style="{ animationDelay: ci * 90 + 'ms' }">
                <div class="duval__title">{{ ch.title }}</div>

                <!-- Controles de zoom (no van al PDF: son HTML, fuera del SVG) -->
                <div class="duval__zoom">
                    <button class="duval__zbtn" @click="zoomBtn(ch.key, 1)" title="Zoom +">+</button>
                    <button class="duval__zbtn" @click="zoomBtn(ch.key, -1)" title="Zoom −">−</button>
                    <button class="duval__zbtn" :class="{ 'is-on': isZoomed(ch.key) || picked[ch.key] }"
                        @click="resetView(ch.key)" title="Reset">⟲</button>
                </div>

                <svg
                    :viewBox="mode === 'triangle' ? '0 0 400 360' : '0 0 440 380'"
                    preserveAspectRatio="xMidYMid meet" role="img" class="duval__svg"
                    @mousemove="moveTip" @wheel.prevent="onWheel(ch.key, $event)"
                >
                  <g data-zoom :transform="zoomTransform(ch.key)">
                    <!-- Zonas (click = resaltar) -->
                    <polygon
                        v-for="(z, i) in ch.zones" :key="'z' + i"
                        :points="z.str" :fill="zoneColor(z.code)"
                        :fill-opacity="picked[ch.key] && picked[ch.key] !== z.code ? 0.18 : 0.5"
                        stroke="#888" stroke-width="0.5" class="zone"
                        :style="{ animationDelay: i * 35 + 'ms' }"
                        @click="pickZone(ch.key, z.code)"
                    />
                    <text
                        v-for="(z, i) in ch.labels" :key="'zl' + i"
                        :x="z.c.x" :y="z.c.y + 3" text-anchor="middle" class="zlabel"
                        :opacity="picked[ch.key] && picked[ch.key] !== z.code ? 0.3 : 1"
                    >{{ z.code }}</text>

                    <!-- Vértices -->
                    <template v-if="mode === 'triangle'">
                        <text x="200" y="16" text-anchor="middle" class="vlabel">{{ ch.verts.apex }}</text>
                        <text x="22" y="350" text-anchor="middle" class="vlabel">{{ ch.verts.bl }}</text>
                        <text x="378" y="350" text-anchor="middle" class="vlabel">{{ ch.verts.br }}</text>
                    </template>
                    <template v-else>
                        <text
                            v-for="v in PENT_VERTS" :key="'pv' + v.t"
                            :x="pentLabel(v).x" :y="pentLabel(v).y"
                            :text-anchor="pentLabel(v).anchor" class="vlabel"
                        >{{ v.t }}</text>
                    </template>

                    <!-- Trayectoria (se "dibuja" con efecto) -->
                    <polyline
                        v-if="ch.samples.length > 1"
                        :points="ch.trajectory" fill="none"
                        stroke="#32363a" stroke-width="1.4" stroke-dasharray="4 3" opacity="0.45" class="traj"
                    />
                    <!-- Puntos -->
                    <g v-for="(s, i) in ch.samples" :key="'p' + i">
                        <circle
                            v-if="i === ch.samples.length - 1"
                            :cx="s.pos.x" :cy="s.pos.y" :r="9" :fill="zoneColor(s.zone)"
                            opacity="0.5" class="pulse"
                        />
                        <circle
                            :cx="s.pos.x" :cy="s.pos.y" :r="i === ch.samples.length - 1 ? 6 : 3.8"
                            :fill="i === ch.samples.length - 1 ? '#111' : '#555'"
                            stroke="#fff" stroke-width="1.6" class="pt"
                            @mouseenter="showTip(s, $event)" @mouseleave="hideTip"
                        />
                    </g>

                    <!-- Pop-out: la zona elegida se agranda y sobresale (NO va al PDF) -->
                    <g v-if="picked[ch.key]" class="duval-pop">
                        <polygon
                            v-for="(z, i) in ch.zones.filter((z) => z.code === picked[ch.key])" :key="'pop' + i"
                            :points="z.str" :fill="zoneColor(z.code)" fill-opacity="0.95"
                            stroke="#1a1a1a" stroke-width="1.4"
                            :transform="`translate(${z.cc.x},${z.cc.y}) scale(1.14) translate(${-z.cc.x},${-z.cc.y})`"
                        />
                        <text
                            v-for="(z, i) in ch.zones.filter((z) => z.code === picked[ch.key])" :key="'popl' + i"
                            :x="z.c.x" :y="z.c.y + 4" text-anchor="middle" class="zlabel zlabel--pop"
                        >{{ z.code }}</text>
                    </g>
                  </g>
                </svg>

                <!-- Diagnóstico actual: solo válido si hay falla activa; con gases
                     normales las relaciones de Duval son ruido → "sin falla activa". -->
                <div class="duval__diag">
                    <template v-if="activeFault">
                        <span class="duval__dot" :style="{ background: zoneColor(ch.diag) }"></span>
                        <strong>{{ zoneLabel(ch.diag) }}</strong>
                    </template>
                    <span v-else class="duval__nofault">{{ $t('diagnostics.duval_no_fault') }}</span>
                </div>

                <!-- Leyenda de zonas -->
                <ul class="duval__legend">
                    <li v-for="code in ch.legend" :key="code">
                        <span class="duval__sw" :style="{ background: zoneColor(code) }"></span>
                        <span class="duval__lgtxt">{{ zoneLabel(code) }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Tooltip grande -->
        <Teleport to="body">
            <transition name="tipfade">
                <div v-if="tip" class="duvaltip" :style="tipStyle">
                    <div class="duvaltip__head">
                        <span class="duvaltip__zone" :style="{ background: zoneColor(tip.zone) }">{{ tip.zone }}</span>
                        <span class="duvaltip__date">{{ tip.date }}</span>
                    </div>
                    <div class="duvaltip__cond">{{ zoneLabel(tip.zone) }}</div>
                    <table class="duvaltip__tbl">
                        <tr v-for="p in tip.pairs" :key="p.g">
                            <td class="duvaltip__g">{{ p.g }}</td>
                            <td class="duvaltip__v">{{ p.v }}%</td>
                            <td class="duvaltip__bar">
                                <span :style="{ width: Math.min(p.v, 100) + '%', background: zoneColor(tip.zone) }"></span>
                            </td>
                        </tr>
                    </table>
                </div>
            </transition>
        </Teleport>
    </div>
</template>

<style scoped>
.duval__head { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.duval__empty { padding: 24px 0; }
.duval__nofault-box { display: flex; align-items: center; gap: 12px; padding: 16px 18px; border: 1px solid #1D7044; border-left-width: 4px; border-radius: 10px; max-width: 460px; }
.duval__nofault-box strong { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.4px; color: var(--color-text-muted, #6A6D70); }
.duval__nofault-box p { margin: 4px 0 0; font-size: 0.9rem; color: var(--color-text, #32363a); }
.duval__nofault-dot { width: 12px; height: 12px; border-radius: 50%; background: #1D7044; flex-shrink: 0; }
.duval__grid { display: flex; gap: 18px; flex-wrap: wrap; }
.duval__card {
    position: relative;
    flex: 1 1 300px; min-width: 280px; max-width: 380px;
    border: 1px solid var(--color-border, #e2e6ea); border-radius: 12px; padding: 14px;
    background: var(--color-surface, #fff);
    box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
    opacity: 0; transform: translateY(10px); animation: cardIn 0.5s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}
/* Controles de zoom: HTML sobre la tarjeta (nunca entran al PDF) */
.duval__zoom { position: absolute; top: 10px; right: 10px; display: flex; flex-direction: column; gap: 4px; z-index: 2; }
.duval__zbtn {
    width: 24px; height: 24px; border: 1px solid var(--color-border, #d8dde2); border-radius: 6px;
    background: rgba(255, 255, 255, 0.9); color: #32363a; font-size: 15px; line-height: 1; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0;
    transition: background 0.15s, border-color 0.15s;
}
.duval__zbtn:hover { background: #fff; border-color: #b0b6bc; }
.duval__zbtn.is-on { border-color: #0A6ED1; color: #0A6ED1; }
.zlabel--pop { font-size: 14px; fill: #111; }
.duval-pop polygon { filter: drop-shadow(0 2px 3px rgba(0, 0, 0, 0.28)); }
@keyframes cardIn { to { opacity: 1; transform: none; } }
.duval__svg { width: 100%; height: auto; display: block; }
.duval__title { font-size: 0.82rem; font-weight: 700; color: var(--color-text, #32363a); margin-bottom: 8px; text-align: center; }
.zlabel { font-size: 11px; font-weight: 700; fill: #1a1a1a; pointer-events: none; }
.vlabel { font-size: 12px; font-weight: 700; fill: var(--color-text, #32363a); pointer-events: none; }

/* Efectos suaves (solo opacity/transform → no afectan performance) */
.zone { opacity: 0; animation: zoneIn 0.45s ease forwards; transition: fill-opacity 0.2s; cursor: pointer; }
.zone:hover { fill-opacity: 0.72; }
@keyframes zoneIn { to { opacity: 1; } }
.traj { stroke-dasharray: 6 4; animation: dash 0.9s linear; }
@keyframes dash { from { stroke-dashoffset: 60; } to { stroke-dashoffset: 0; } }
.pt { cursor: pointer; transition: r 0.12s ease; }
.pt:hover { r: 8; }
.pulse { transform-box: fill-box; transform-origin: center; animation: pulse 2.2s ease-out infinite; }
@keyframes pulse { 0% { transform: scale(0.7); opacity: 0.55; } 70% { transform: scale(1.9); opacity: 0; } 100% { opacity: 0; } }

.duval__diag { display: flex; align-items: center; gap: 8px; justify-content: center; margin: 8px 0; font-size: 0.92rem; }
.duval__nofault { color: var(--color-text-muted, #6A6D70); font-size: 0.85rem; text-align: center; }
.duval__dot { width: 11px; height: 11px; border-radius: 3px; display: inline-block; flex: none; }
.duval__legend { list-style: none; margin: 8px 0 0; padding: 8px 4px 0; border-top: 1px solid var(--color-border, #eef0f2); display: flex; flex-direction: column; gap: 4px; }
.duval__legend li { display: flex; align-items: center; gap: 8px; font-size: 0.76rem; color: var(--color-text, #4a4f55); }
.duval__sw { width: 12px; height: 12px; border-radius: 3px; flex: none; }
.duval__lgtxt { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

html[data-theme="dark"] .duval__card { border-color: #3f4448; background: #24282c; }
html[data-theme="dark"] .zlabel { fill: #0c0c0c; }
</style>

<style>
/* Tooltip (teleport a body, sin scope) */
.duvaltip {
    position: fixed; z-index: 3000; pointer-events: none;
    min-width: 200px; max-width: 260px;
    background: rgba(20, 24, 28, 0.96); color: #f3f5f7;
    border-radius: 10px; padding: 11px 13px;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(3px);
    font-size: 0.8rem;
}
.duvaltip__head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 4px; }
.duvaltip__zone { font-weight: 800; font-size: 0.78rem; padding: 1px 8px; border-radius: 6px; color: #10141a; }
.duvaltip__date { color: #aab2bd; font-size: 0.72rem; }
.duvaltip__cond { color: #dfe4ea; font-size: 0.76rem; margin-bottom: 8px; }
.duvaltip__tbl { width: 100%; border-collapse: collapse; }
.duvaltip__tbl td { padding: 2px 0; vertical-align: middle; }
.duvaltip__g { color: #c6ccd4; width: 38px; }
.duvaltip__v { text-align: right; width: 42px; font-variant-numeric: tabular-nums; padding-right: 8px; }
.duvaltip__bar { width: 70px; }
.duvaltip__bar span { display: block; height: 6px; border-radius: 3px; min-width: 2px; transition: width 0.2s; }
.tipfade-enter-active, .tipfade-leave-active { transition: opacity 0.12s ease; }
.tipfade-enter-from, .tipfade-leave-to { opacity: 0; }
</style>
