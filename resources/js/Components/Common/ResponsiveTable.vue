<script setup>
import { ref, computed, onMounted, onBeforeUnmount, getCurrentInstance } from 'vue';
import { Table, Pagination, Empty, Checkbox, Button, Spin, Tooltip } from 'ant-design-vue';
import { RightOutlined, LeftOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

/**
 * ResponsiveTable — desktop: Ant Design Table | mobile: card list
 *
 * Each column may declare a `mobile` config:
 *   { mobile: { role: 'title' | 'subtitle' | 'status' | 'meta' | 'actions' | 'hidden' } }
 *
 * Roles:
 *   - title:    big text at the top of the card (typically the entity's name)
 *   - subtitle: secondary line under the title
 *   - status:   small element on the top-right (typically a Tag with state)
 *   - meta:     small label-value rows in the body
 *   - actions:  buttons at the bottom of the card
 *   - hidden:   not rendered on mobile
 *
 * Slots:
 *   bodyCell    — same as Ant Design Table (used for both desktop and mobile)
 */

const props = defineProps({
    columns:     { type: Array, required: true },
    dataSource:  { type: Array, required: true },
    pagination:  { type: [Object, Boolean], default: false },
    rowKey:      { type: String, default: 'id' },
    loading:     { type: Boolean, default: false },
    size:        { type: String, default: 'middle' },
    scroll:      { type: Object, default: () => ({ x: 800 }) },
    rowSelection: { type: Object, default: null }, // pass through Ant Design rowSelection
    rowClassName: { type: Function, default: null }, // (record) => string, para highlight de rows
    // Keys de columnas que el usuario tiene ACTIVAS (orden o filtro). En mobile,
    // si el módulo marcó columnas `mobile.primary`, la card muestra solo esas +
    // las que estén aquí → menos saturado, pero ves la columna que estás
    // consultando. El index las calcula y las pasa.
    activeColumnKeys: { type: Array, default: () => [] },
    // Vista forzada (desktop): 'auto' (tabla desktop / cards mobile, default),
    // 'table' (siempre tabla), 'list' (cards apiladas full-width),
    // 'cards' (cards en grilla). list/cards reutilizan el MISMO render de card
    // mobile (slot bodyCell + mobile.role) → cero código por módulo.
    view: { type: String, default: 'auto' },
});

const emit = defineEmits(['change', 'row-click', 'update:selectedRowKeys']);

// Las filas/cards son interactivas SOLO si el padre ató un handler @row-click.
// Sin eso, no se atan onClick ni se muestra cursor/hover de "clickeable" (evita
// que un click accidental abra el registro). AuditLogs lo usa; el resto no.
const _instance = getCurrentInstance();
const hasRowClick = computed(() => !!_instance?.vnode?.props?.onRowClick);

// Mobile selection: a Set of row keys selected via card-level checkbox.
import { computed as _computed } from 'vue';
const mobileSelectedKeys = _computed(() => props.rowSelection?.selectedRowKeys ?? []);
const toggleMobileSelect = (record) => {
    if (!props.rowSelection) return;
    const key = record[props.rowKey];
    const current = [...mobileSelectedKeys.value];
    const idx = current.indexOf(key);
    if (idx === -1) current.push(key); else current.splice(idx, 1);
    props.rowSelection.onChange?.(current, props.dataSource.filter(r => current.includes(r[props.rowKey])));
};
const isMobileSelected = (record) => mobileSelectedKeys.value.includes(record[props.rowKey]);

// ─── Responsive ──────────────────────────────────────────────────────────
const isMobile = ref(false);
const checkMobile = () => { isMobile.value = window.innerWidth < 768; };
onMounted(() => { checkMobile(); window.addEventListener('resize', checkMobile); });
onBeforeUnmount(() => window.removeEventListener('resize', checkMobile));

// ¿Renderizar como lista de cards? Si el padre pidió 'cards'/'list', siempre.
// Si pidió 'auto', cards solo en pantalla chica (comportamiento responsive por
// defecto). Si pidió 'table' EXPLÍCITO, NUNCA cards: en pantalla chica la tabla
// se ve igual con scroll horizontal (el padre elige la vista, no se le impone).
const showCardList = computed(() =>
    props.view === 'cards' || props.view === 'list' || (props.view === 'auto' && isMobile.value));
// Vistas desktop forzadas (no mobile): lista = filas compactas; cards = grilla.
const desktopList  = computed(() => !isMobile.value && props.view === 'list');
const desktopCards = computed(() => !isMobile.value && props.view === 'cards');
const desktopPager = computed(() => !isMobile.value && (props.view === 'list' || props.view === 'cards'));

// ─── Column role helpers ──────────────────────────────────────────────────
const colsByRole = computed(() => {
    const roles = { title: null, subtitle: null, status: null, pin: null, meta: [], actions: null, hidden: [] };
    props.columns.forEach(c => {
        const role = c.mobile?.role;
        if (role === 'title')    roles.title    = c;
        else if (role === 'subtitle') roles.subtitle = c;
        else if (role === 'status')   roles.status   = c;
        // 'pin': elemento toggle (favorito, etc.) en el top-right del card.
        // Patrón Gmail: la estrella vive arriba a la derecha, siempre visible.
        else if (role === 'pin')      roles.pin      = c;
        else if (role === 'actions')  roles.actions  = c;
        else if (role === 'hidden')   roles.hidden.push(c);
        else if (role === 'meta')     roles.meta.push(c);
        // No mobile config: smart defaults
        else if (!roles.title && c.key !== 'id' && c.key !== 'actions') {
            roles.title = c;
        } else if (c.key !== 'actions' && c.key !== 'id') {
            roles.meta.push(c);
        }
    });
    return roles;
});

// Filas `meta` a mostrar en la card mobile. Si el módulo marcó columnas
// `mobile.primary`, se muestran SOLO esas + las que el usuario tenga activas
// (orden/filtro), para que la card no sature pero igual veas lo que consultás.
// Si NINGUNA es primary (módulo no optó), se muestran todas (comportamiento
// previo) — así no cambia ningún listado sin querer.
const mobileMeta = computed(() => {
    const metas = colsByRole.value.meta;
    const primary = metas.filter(c => c.mobile?.primary);
    if (primary.length === 0) return metas;
    const active = props.activeColumnKeys ?? [];
    const extra = metas.filter(c => !c.mobile?.primary && active.includes(c.key));
    return [...primary, ...extra];
});

// Chips meta visibles PARA UN REGISTRO: oculta los que la columna marcó
// `mobile.hideWhenZero` cuando su valor es 0/vacío (ej. conteos en 0 de
// Customers — evita el ruido "0 0"). El resto se muestra siempre.
const visibleMeta = (record) => mobileMeta.value.filter(col =>
    !(col.mobile?.hideWhenZero && !(Number(record[col.dataIndex]) > 0))
);

// ─── Mobile pagination ───────────────────────────────────────────────────
const onPageChange = (page, pageSize) => {
    emit('change', { current: page, pageSize }, {}, {});
};

// Total de páginas (computed para reactividad). Si pagination es false/null
// o no tiene total, devuelve 0 → la paginación no se muestra.
const totalPages = computed(() => {
    if (!props.pagination || !props.pagination.total) return 0;
    return Math.max(1, Math.ceil(props.pagination.total / props.pagination.pageSize));
});

const goToPrevPage = () => {
    if (!props.pagination) return;
    const target = Math.max(1, props.pagination.current - 1);
    onPageChange(target, props.pagination.pageSize);
};
const goToNextPage = () => {
    if (!props.pagination) return;
    const target = Math.min(totalPages.value, props.pagination.current + 1);
    onPageChange(target, props.pagination.pageSize);
};

// ─── Click on card → row-click event ─────────────────────────────────────
// Mirrors the desktop onRowClick logic: skip when the click came from a
// button, link, or Ant Design dropdown trigger inside the card. Avoids
// having the row-click fire when the user interacts with action buttons.
const onCardClick = (event, record) => {
    if (!hasRowClick.value) return;
    const skipSelectors = 'button, a, .ant-dropdown-trigger';
    if (event.target.closest(skipSelectors)) return;
    emit('row-click', record);
};

// ─── Desktop row click + keyboard nav → row-click event ────────────────
// Ant Design Table necesita customRow para inyectar handlers. Skip si el
// evento vino del checkbox de selección o de un button/link adentro de la
// fila (esos siguen funcionando sin disparar el drawer).
// Accesibilidad: cada fila es focuseable (tabindex=0) y responde a Enter
// y Space — patrón WCAG estándar para filas tappeables.
const onRowClick = (record) => ({
    tabindex: 0,
    onClick: (event) => {
        const skipSelectors = '.ant-table-selection-column, button, a';
        if (event.target.closest(skipSelectors)) return;
        emit('row-click', record);
    },
    onKeydown: (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const skipSelectors = '.ant-table-selection-column, button, a, input, textarea, select';
        if (event.target.closest(skipSelectors)) return;
        event.preventDefault();
        emit('row-click', record);
    },
});
</script>

<template>
    <!-- DESKTOP: Ant Design Table.
         `sticky` prop nativo de antd: el thead queda fijo al scrollear.
         offsetHeader = altura de la .shell-bar (44px) que SÍ es sticky en
         AppLayout — sin offset, el thead quedaría tapado por la shell-bar. -->
    <Table
        v-if="!showCardList"
        :columns="props.columns"
        :dataSource="props.dataSource"
        :pagination="props.pagination"
        :rowKey="props.rowKey"
        :loading="props.loading"
        :size="props.size"
        :scroll="props.scroll"
        :row-selection="props.rowSelection"
        :row-class-name="props.rowClassName ?? undefined"
        :custom-row="hasRowClick ? onRowClick : undefined"
        :sticky="{ offsetHeader: 44 }"
        @change="(...args) => emit('change', ...args)"
    >
        <template #bodyCell="slotProps">
            <!-- compact = la tabla se renderiza en pantalla chica (view='table'
                 forzado por el padre): permite a las celdas compactar (ej. kebab
                 de acciones). En desktop es false. -->
            <slot name="bodyCell" v-bind="slotProps" :isMobile="false" :compact="isMobile" />
        </template>
        <template #emptyText>
            <slot name="empty">
                <Empty description="Sin resultados" />
            </slot>
        </template>
    </Table>

    <!-- MOBILE: card list -->
    <div v-else class="rt-mobile-wrap" :class="{ 'rt--clickable': hasRowClick }">
      <!-- Spin de carga (efecto de búsqueda) para list/cards, igual que la tabla. -->
      <Spin :spinning="props.loading">
        <div
            v-if="props.dataSource.length === 0 && !props.loading"
            class="rt-empty"
        >
            <slot name="empty">
                <Empty description="Sin resultados" />
            </slot>
        </div>

        <!-- LISTA desktop: filas horizontales compactas (estilo Transformers).
             Reusa los mismos slots (title/subtitle/meta/status/pin/actions). -->
        <div v-else-if="desktopList" class="rt-rows">
            <div
                v-for="record in props.dataSource"
                :key="record[props.rowKey]"
                class="rt-row"
                :class="{ 'rt-row--selected': isMobileSelected(record) }"
                @click="onCardClick($event, record)"
            >
                <Checkbox
                    v-if="props.rowSelection"
                    :checked="isMobileSelected(record)"
                    @click.stop
                    @change="toggleMobileSelect(record)"
                    class="rt-row__check"
                />
                <div class="rt-row__id">
                    <span v-if="colsByRole.title" class="rt-row__title">
                        <slot name="bodyCell" :column="colsByRole.title" :record="record" :index="0" :text="record[colsByRole.title.dataIndex]" :isMobile="true">{{ record[colsByRole.title.dataIndex] }}</slot>
                    </span>
                    <span v-if="colsByRole.subtitle" class="rt-row__subtitle">
                        <slot name="bodyCell" :column="colsByRole.subtitle" :record="record" :index="0" :text="record[colsByRole.subtitle.dataIndex]" :isMobile="true">{{ record[colsByRole.subtitle.dataIndex] }}</slot>
                    </span>
                </div>
                <div class="rt-row__meta">
                    <template v-for="col in visibleMeta(record)" :key="col.key">
                        <Tooltip v-if="col.mobile?.icon" :title="col.title">
                            <span class="rt-row__chip rt-row__chip--ico">
                                <component :is="col.mobile.icon" class="rt-row__chip-ico" />
                                <span class="rt-row__chip-val">
                                    <slot name="bodyCell" :column="col" :record="record" :index="0" :text="record[col.dataIndex]" :isMobile="true">{{ record[col.dataIndex] }}</slot>
                                </span>
                            </span>
                        </Tooltip>
                        <span v-else class="rt-row__chip">
                            <span class="rt-row__chip-label">{{ col.title }}</span>
                            <span class="rt-row__chip-val">
                                <slot name="bodyCell" :column="col" :record="record" :index="0" :text="record[col.dataIndex]" :isMobile="true">{{ record[col.dataIndex] }}</slot>
                            </span>
                        </span>
                    </template>
                </div>
                <div v-if="colsByRole.status" class="rt-row__status" @click.stop>
                    <slot name="bodyCell" :column="colsByRole.status" :record="record" :index="0" :text="record[colsByRole.status.dataIndex]" :isMobile="true" />
                </div>
                <span v-if="colsByRole.pin" class="rt-row__pin" @click.stop>
                    <slot name="bodyCell" :column="colsByRole.pin" :record="record" :index="0" :text="record[colsByRole.pin.dataIndex]" :isMobile="true" />
                </span>
                <div v-if="colsByRole.actions" class="rt-row__actions" @click.stop>
                    <slot name="bodyCell" :column="colsByRole.actions" :record="record" :index="0" :isMobile="true" />
                </div>
            </div>
        </div>

        <!-- Lista unificada con dividers internos. La paginación va FUERA
             de este contenedor para no quedar encerrada por el border-radius
             y overflow:hidden. -->
        <div v-else-if="props.dataSource.length > 0" class="rt-mobile" :class="{ 'rt-mobile--grid': desktopCards }">
        <div
            v-for="record in props.dataSource"
            :key="record[props.rowKey]"
            class="rt-card"
            :class="{ 'rt-card--selected': isMobileSelected(record) }"
            @click="onCardClick($event, record)"
        >
            <!-- Barra superior: solo el check (la estrella va abajo, en el pie). -->
            <div v-if="props.rowSelection" class="rt-card__bar">
                <Checkbox
                    :checked="isMobileSelected(record)"
                    @click.stop
                    @change="toggleMobileSelect(record)"
                    class="rt-card__check"
                />
            </div>

            <!-- Cabecera: título/subtítulo (izq) + estado (der). -->
            <div class="rt-card__top">
                <div class="rt-card__title-block">
                    <div v-if="colsByRole.title" class="rt-card__title">
                        <slot
                            name="bodyCell"
                            :column="colsByRole.title"
                            :record="record"
                            :index="0"
                            :text="record[colsByRole.title.dataIndex]"
                            :isMobile="true"
                        >
                            {{ record[colsByRole.title.dataIndex] }}
                        </slot>
                    </div>
                    <div v-if="colsByRole.subtitle" class="rt-card__subtitle">
                        <slot
                            name="bodyCell"
                            :column="colsByRole.subtitle"
                            :record="record"
                            :index="0"
                            :text="record[colsByRole.subtitle.dataIndex]"
                            :isMobile="true"
                        >
                            {{ record[colsByRole.subtitle.dataIndex] }}
                        </slot>
                    </div>
                </div>
                <div v-if="colsByRole.status" class="rt-card__status" @click.stop>
                    <slot
                        name="bodyCell"
                        :column="colsByRole.status"
                        :record="record"
                        :index="0"
                        :text="record[colsByRole.status.dataIndex]"
                        :isMobile="true"
                    />
                </div>
            </div>

            <!-- Datos: en mobile = filas etiqueta/valor; en desktop (cards) =
                 chips grises que wrapean (estilo Transformers). -->
            <div v-if="visibleMeta(record).length > 0" class="rt-card__meta" :class="{ 'rt-card__meta--chips': !isMobile }">
                <template v-for="col in visibleMeta(record)" :key="col.key">
                    <Tooltip v-if="!isMobile && col.mobile?.icon" :title="col.title">
                        <span class="rt-card__chip rt-card__chip--ico">
                            <component :is="col.mobile.icon" class="rt-card__chip-ico" />
                            <span class="rt-card__chip-val">
                                <slot name="bodyCell" :column="col" :record="record" :index="0" :text="record[col.dataIndex]" :isMobile="true">{{ record[col.dataIndex] }}</slot>
                            </span>
                        </span>
                    </Tooltip>
                    <span v-else-if="!isMobile" class="rt-card__chip">
                        <span class="rt-card__chip-label">{{ col.title }}</span>
                        <span class="rt-card__chip-val">
                            <slot name="bodyCell" :column="col" :record="record" :index="0" :text="record[col.dataIndex]" :isMobile="true">{{ record[col.dataIndex] }}</slot>
                        </span>
                    </span>
                    <div v-else class="rt-card__meta-row">
                        <span class="rt-card__meta-label">{{ col.title }}</span>
                        <span class="rt-card__meta-value">
                            <slot name="bodyCell" :column="col" :record="record" :index="0" :text="record[col.dataIndex]" :isMobile="true">{{ record[col.dataIndex] }}</slot>
                        </span>
                    </div>
                </template>
            </div>

            <!-- Pie: línea + estrella (favorito) + acciones (grises → notorias al hover). -->
            <div v-if="colsByRole.actions || colsByRole.pin" class="rt-card__foot" @click.stop>
                <span v-if="colsByRole.pin" class="rt-card__foot-pin">
                    <slot
                        name="bodyCell"
                        :column="colsByRole.pin"
                        :record="record"
                        :index="0"
                        :text="record[colsByRole.pin.dataIndex]"
                        :isMobile="true"
                    />
                </span>
                <div v-if="colsByRole.actions" class="rt-card__foot-actions">
                    <slot
                        name="bodyCell"
                        :column="colsByRole.actions"
                        :record="record"
                        :index="0"
                        :isMobile="true"
                    />
                </div>
            </div>
        </div>
        </div>
      </Spin>

        <!-- Paginación DESKTOP (list/cards): Ant Pagination, igual que la tabla. -->
        <div v-if="desktopPager && props.pagination && props.pagination.total > 0" class="rt-pager-desktop">
            <Pagination
                :current="props.pagination.current"
                :page-size="props.pagination.pageSize"
                :total="props.pagination.total"
                :show-size-changer="true"
                :page-size-options="['10', '25', '50', '100']"
                @change="(p, ps) => emit('change', { current: p, pageSize: ps }, {}, {})"
            />
        </div>

        <!-- Mobile pagination — controles custom claros y grandes.
             El modo `simple` de Ant Design renderiza una UI minimal que
             pasa desapercibida; preferimos botones explícitos prev/next +
             indicador "Página X de Y" + total como contexto. -->
        <div v-else-if="props.pagination && props.pagination.total > 0" class="rt-pagination">
            <Button
                :disabled="props.pagination.current <= 1"
                class="rt-pagination__btn"
                @click="goToPrevPage"
            >
                <LeftOutlined />
                <span class="rt-pagination__btn-label">{{ $t('global.previous') }}</span>
            </Button>

            <div class="rt-pagination__info">
                <span class="rt-pagination__page">
                    {{ $t('global.page') }} <strong>{{ props.pagination.current }}</strong> {{ $t('global.of') }} <strong>{{ totalPages }}</strong>
                </span>
                <span class="rt-pagination__total">
                    {{ props.pagination.total }} {{ props.pagination.total === 1 ? $t('global.record') : $t('global.records') }}
                </span>
            </div>

            <Button
                :disabled="props.pagination.current >= totalPages"
                class="rt-pagination__btn"
                @click="goToNextPage"
            >
                <span class="rt-pagination__btn-label">{{ $t('global.next') }}</span>
                <RightOutlined />
            </Button>
        </div>
    </div>
</template>

<style scoped>
/* Wrapper exterior: lista + paginación. Sin border ni radius — los aplica
   solo el card interno para mantener el look "single card with dividers". */
.rt-mobile-wrap {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 0;
    max-width: 100%;
}

/* Mobile cards container — cards SEPARADAS con espacio entre cada una
   (flotantes), no una lista pegada. Cada registro es su propia tarjeta. */
.rt-mobile {
    display: flex;
    flex-direction: column;
    gap: 14px;
    background: transparent;
    border: 0;
    border-radius: 0;
    overflow: visible;
}

/* Vista 'cards' en desktop: grilla responsiva de cards (mismo card que mobile). */
.rt-mobile--grid {
    display: grid;
    /* Máximo 5 columnas (parejo para 10/página → 5+5), pero responsive: en
       pantallas chicas baja solo a 4, 3, 2, 1. El min de cada track es el mayor
       entre 300px y el ancho que tendrían 5 columnas; así nunca caben 6. */
    grid-template-columns: repeat(auto-fill, minmax(max(300px, (100% - 4 * 14px) / 5), 1fr));
    gap: 14px;
    align-items: start;
}
/* Hover de las cards en grilla desktop (lift sutil) — SOLO si son clickeables
   (hay @row-click). Sin eso no hay lift ni cursor (no engaña con clickabilidad). */
.rt--clickable .rt-mobile--grid .rt-card:hover {
    box-shadow: 0 6px 18px rgba(16,24,40,0.10);
    border-color: var(--color-border-strong) !important;
    transform: translateY(-2px);
}

/* ── Vista LISTA desktop: filas horizontales compactas ──────────────────── */
.rt-rows { display: flex; flex-direction: column; gap: 10px; }
.rt-row {
    display: flex; align-items: center; gap: 16px;
    padding: 11px 16px;
    background: var(--color-surface, #fff);
    border: 1px solid var(--color-border-strong, #e8eaed) !important;
    border-radius: 12px;
    box-shadow: 0 1px 2px rgba(16,24,40,0.04);
    transition: box-shadow .15s ease, border-color .15s ease, transform .15s ease;
}
.rt--clickable .rt-row { cursor: pointer; }
.rt--clickable .rt-row:hover { box-shadow: 0 4px 16px rgba(16,24,40,0.10); transform: translateY(-1px); }
.rt-row--selected { box-shadow: inset 4px 0 0 0 var(--color-primary); }
.rt-row__check { flex-shrink: 0; }
.rt-row__id { flex: 0 1 240px; min-width: 150px; display: flex; flex-direction: column; line-height: 1.3; min-width: 0; }
/* Aire extra entre el bloque de título y los chips (no quedan pegados al nombre). */
.rt-row__meta { margin-left: 12px; }
.rt-row__title { font-weight: 600; color: var(--color-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rt-row__subtitle { font-size: 0.78rem; color: var(--color-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rt-row__meta { flex: 1 1 auto; display: flex; flex-wrap: wrap; gap: 6px; min-width: 0; }
.rt-row__chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px; border-radius: 7px;
    background: var(--color-surface-alt, #f3f5f7);
    border: 1px solid var(--color-border, #e7eaee);
    font-size: 0.74rem; line-height: 1.2; white-space: nowrap;
    max-width: 240px; overflow: hidden;
}
.rt-row__chip-label { color: var(--color-text-muted); font-weight: 500; }
.rt-row__chip-val { color: var(--color-text); overflow: hidden; text-overflow: ellipsis; display: inline-flex; align-items: center; }
/* Valores que ya son Tag/pill: se aplanan dentro del chip (sin badge anidado),
   igual que en la vista de tarjetas. */
.rt-row__chip-val :deep(.ant-tag),
.rt-row__chip-val :deep(.pill),
.rt-row__chip-val :deep(.count-pill) {
    background: transparent !important; border: 0 !important;
    padding: 0 !important; margin: 0 !important; height: auto; line-height: 1.2;
}
/* Chip estilo trafos: ícono (acento primary) + valor, sin etiqueta de texto. */
.rt-row__chip--ico .rt-row__chip-val { font-weight: 500; }
.rt-row__chip-ico { font-size: 0.74rem; color: var(--color-primary, #2f6df6); opacity: 0.85; flex-shrink: 0; }
.rt-row__status { flex-shrink: 0; }
.rt-row__pin { flex-shrink: 0; display: inline-flex; align-items: center; }
.rt-row__actions { flex-shrink: 0; display: inline-flex; align-items: center; }
/* Vista lista: acciones grises por defecto, con color al hover de la fila. */
@media (hover: hover) {
    .rt-row__actions { opacity: 0.5; transition: opacity 0.15s ease; }
    .rt-row:hover .rt-row__actions { opacity: 1; }
}

/* Paginación desktop para list/cards (Ant Pagination alineada a la derecha). */
/* Datos como CHIPS grises en desktop (cards) — estilo Transformers.
   Doble clase para ganar especificidad sobre la regla base .rt-card__meta
   (flex-column/stretch), que si no estiraba cada chip a full width. */
.rt-card__meta.rt-card__meta--chips { flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 6px; }
.rt-card__chip {
    display: inline-flex; align-items: center; gap: 5px;
    flex: 0 0 auto; align-self: flex-start;
    padding: 3px 9px; border-radius: 7px;
    background: var(--color-surface-alt, #f3f5f7);
    border: 1px solid var(--color-border, #e7eaee);
    font-size: 0.74rem; line-height: 1.2; white-space: nowrap;
    max-width: 100%; overflow: hidden;
}
.rt-card__chip-label { color: var(--color-text-muted); font-weight: 500; }
.rt-card__chip-val { color: var(--color-text); overflow: hidden; text-overflow: ellipsis; display: inline-flex; align-items: center; }
/* Chip estilo trafos: ícono (acento primary) + valor, sin etiqueta de texto. */
.rt-card__chip--ico .rt-card__chip-val { font-weight: 500; }
.rt-card__chip-ico { font-size: 0.74rem; color: var(--color-primary, #2f6df6); opacity: 0.85; flex-shrink: 0; }
/* Valores que ya son Tag/pill: se aplanan dentro del chip (sin doble fondo). */
.rt-card__chip-val :deep(.ant-tag),
.rt-card__chip-val :deep(.pill),
.rt-card__chip-val :deep(.count-pill) {
    background: transparent !important; border: 0 !important;
    padding: 0 !important; margin: 0 !important; height: auto; line-height: 1.2;
}

.rt-pager-desktop { display: flex; justify-content: flex-end; margin-top: 16px; padding: 0 2px; }

.rt-empty { padding: 32px 16px; }

/* Single row inside the unified card. UNA línea separadora entre registros,
   sin bordes internos para meta/actions. */
.rt-card {
    position: relative;
    background: var(--color-surface);
    /* `!important` vence la preflight de Tailwind v4 (border-color: currentColor). */
    border: 1px solid var(--color-border-strong) !important;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    padding: 12px 14px;
    transition: background 0.15s ease, box-shadow 0.15s ease;
    display: flex;
    flex-direction: column;
    gap: 8px;
    /* min-width: 0 + overflow: hidden defienden de hijos que intentan
       expandirse mas alla del ancho del card. Combinado con word-break
       en title/subtitle, nada se escapa a la derecha. */
    min-width: 0;
    overflow: hidden;
}
.rt--clickable .rt-card { cursor: pointer; }
.rt--clickable .rt-card:active { background: var(--color-surface-hover); }

/* Selección con stripe lateral en lugar de fondo intenso.
   - Fondo blanco preservado (texto legible, tags no chocan con tinte).
   - Stripe de 4px a la izquierda con el color primary (señal clara).
   - Tinte sutil de fondo (~7% opacidad) que no compromete contraste.
   Patrón Gmail/Outlook/Linear: el accent comunica selección sin gritar. */
.rt-card--selected {
    background: var(--color-surface);
    box-shadow: inset 4px 0 0 0 var(--color-primary);
}
.rt-card--selected::before {
    content: '';
    position: absolute;
    inset: 0;
    background: var(--color-primary);
    opacity: 0.06;
    pointer-events: none;
}
.rt-card--selected:active { background: var(--color-surface-hover); }
.rt-card__check { padding-top: 2px; position: relative; z-index: 1; }

/* Top row: title + status. min-width: 0 evita que un hijo (status tag con
   texto largo) empuje el row mas alla del padre. overflow: hidden recorta
   por si algo se escapa (defense in depth). */
.rt-card__top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    position: relative;
    z-index: 1;
    min-width: 0;
    overflow: hidden;
}
.rt-card__title-block { flex: 1; min-width: 0; }
.rt-card__title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--color-text);
    line-height: 1.3;
    min-width: 0;
    /* Strings largos (nombres, emails sin espacios) WRAPEAN a multi-linea
       en lugar de truncarse con "...". Patron Android/iOS list cell:
       toda la info visible. `overflow-wrap: anywhere` rompe cualquier
       caracter si no hay espacios (mejor que break-word para emails). */
    word-break: break-word;
    overflow-wrap: anywhere;
}
.rt-card__subtitle {
    font-size: 0.8125rem;
    color: var(--color-text-muted);
    margin-top: 2px;
    min-width: 0;
    word-break: break-word;
    overflow-wrap: anywhere;
}
.rt-card__status { flex-shrink: 0; }
/* Barra superior: check a la izquierda, estrella a la derecha. */
.rt-card__bar { display: flex; align-items: center; gap: 8px; min-height: 22px; position: relative; z-index: 1; }
.rt-card__pin {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    margin-left: auto;
}

/* Chevron — sutil, gris claro. Indica navegación sin gritar. */
.rt-card__chevron {
    color: var(--color-icon-mute);
    font-size: 0.75rem;
    flex-shrink: 0;
    margin-top: 4px;
    transition: color 0.12s ease, transform 0.12s ease;
}
.rt-card:active .rt-card__chevron {
    color: var(--color-primary);
    transform: translateX(2px);
}

/* Meta rows — un poco separadas entre sí para que cada dato respire. */
.rt-card__meta {
    display: flex;
    flex-direction: column;
    gap: 10px;
    position: relative;
    z-index: 1;
}
.rt-card__meta-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 12px;
    font-size: 0.8125rem;
    min-width: 0;
    flex-wrap: wrap;
}
.rt-card__meta-label {
    color: var(--color-text-muted);
    font-weight: 500;
    white-space: nowrap;
}
.rt-card__meta-value {
    color: var(--color-text);
    text-align: right;
    word-break: break-word;
    overflow-wrap: anywhere;
    min-width: 0;
}

/* Actions — estrella + iconos, centrados en la card. */
/* Pie: línea separadora arriba + iconos de acción a la derecha. */
.rt-card__foot {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    position: relative;
    z-index: 1;
    margin-top: 2px;
    padding-top: 10px;
    border-top: 1px solid var(--color-border);
}
.rt-card__foot-pin { flex-shrink: 0; display: inline-flex; align-items: center; }
.rt-card__foot-actions { display: inline-flex; align-items: center; gap: 8px; }
.rt-card__foot-actions :deep(.ant-space) { width: auto; }
.rt-card__foot-actions :deep(.ant-space-item) { flex: 0 0 auto; }
.rt-card__foot-actions :deep(.ant-btn) { width: auto; }
/* Acciones sutiles (grises) por defecto; con color al hover de la card. La
   estrella NO se atenúa (siempre visible). Solo en dispositivos con hover. */
@media (hover: hover) {
    .rt-card__foot-actions { opacity: 0.5; transition: opacity 0.15s ease; }
    .rt-card:hover .rt-card__foot-actions { opacity: 1; }
}

/* Pagination — vive fuera de la card unificada. Layout 3-zonas en mobile:
   [Anterior] [info centrado] [Siguiente]. Botones grandes touch-friendly. */
.rt-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    padding: 12px 14px;
    background: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    border-radius: 6px;
    font-size: 0.8125rem;
}
.rt-pagination__btn {
    height: 36px !important;
    display: inline-flex !important;
    align-items: center;
    gap: 6px;
}
.rt-pagination__btn-label { font-size: 0.8125rem; }
.rt-pagination__info {
    display: flex;
    flex-direction: column;
    align-items: center;
    line-height: 1.2;
    flex: 1;
    text-align: center;
}
.rt-pagination__page {
    color: var(--color-text);
    font-size: 0.8125rem;
}
.rt-pagination__page strong { color: var(--color-primary); font-weight: 600; }
.rt-pagination__total {
    color: var(--color-text-muted);
    font-size: 0.7rem;
    margin-top: 2px;
}

/* Pantallas muy chicas: ocultar texto de los botones, dejar solo iconos */
@media (max-width: 380px) {
    .rt-pagination__btn-label { display: none; }
    .rt-pagination__btn { width: 36px; padding: 0 !important; justify-content: center; }
}

/* (Antes había aquí media queries para landscape phone con grid de 2 columnas.
   Se removió porque RotatePortraitOverlay impide ese caso — los celulares en
   landscape ven el overlay "rotá tu celu", nunca esta vista.) */
</style>

