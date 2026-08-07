<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import { SearchOutlined, ThunderboltOutlined, TeamOutlined, AppstoreOutlined, LoadingOutlined, CloseOutlined, ClockCircleOutlined, AudioOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import { useVoiceSearch } from '@/Composables/useVoiceSearch';

const { t } = useI18n();

const props = defineProps({
    // Items de navegación visibles: [{ key, label, href }] para buscar módulos.
    navItems: { type: Array, default: () => [] },
    // Últimos vistos del usuario (movidos desde el dropdown de perfil): [{ id, name, module, url }].
    recentViews: { type: Array, default: () => [] },
});

const modalOpen = ref(false);
const q = ref('');
const loading = ref(false);
const remote = ref({ transformers: [], customers: [] });
const inputEl = ref(null);
let debounce = null;

// Búsqueda por voz: el micrófono dicta el texto a `q` (mismo composable que los
// buscadores de cada módulo). Solo aparece si el navegador soporta Web Speech.
const { micSupported, listening, startVoiceSearch } = useVoiceSearch(q);

const openModal = () => {
    modalOpen.value = true;
    document.body.style.overflow = 'hidden';
    nextTick(() => inputEl.value?.focus());
};
const closeModal = () => {
    modalOpen.value = false;
    document.body.style.overflow = '';
    q.value = '';
};

const isEmpty = computed(() => q.value.trim().length === 0);

const navMatches = computed(() => {
    const s = q.value.trim().toLowerCase();
    if (s.length < 1) return [];
    return props.navItems.filter((i) => i.label.toLowerCase().includes(s)).slice(0, 6);
});

const recentItems = computed(() => props.recentViews.slice(0, 6).map((r) => ({
    type: 'recent', href: r.url, label: r.name, sub: r.module,
})));

const searchFlat = computed(() => {
    const out = [];
    navMatches.value.forEach((i) => out.push({ type: 'nav', href: i.href, label: i.label }));
    remote.value.transformers.forEach((tr) => out.push({
        type: 'transformer',
        href: route('business_management.transformers.show', tr.slug),
        label: tr.serial || tr.tag,
        sub: [tr.customer, tr.brand].filter(Boolean).join(' · '),
        color: tr.color, condition: tr.condition,
    }));
    remote.value.customers.forEach((c) => out.push({
        type: 'customer',
        href: route('business_management.customers.show', c.slug),
        label: c.name,
    }));
    return out;
});

const defaultFlat = computed(() => {
    const mods = props.navItems.slice(0, 8).map((i) => ({ type: 'nav', label: i.label, href: i.href }));
    return [...recentItems.value, ...mods];
});

const displayItems = computed(() => (isEmpty.value ? defaultFlat.value : searchFlat.value));
const active = ref(0);
const hasResults = computed(() => displayItems.value.length > 0);

watch(q, () => {
    active.value = 0;
    clearTimeout(debounce);
    const s = q.value.trim();
    if (s.length < 2) { remote.value = { transformers: [], customers: [] }; loading.value = false; return; }
    loading.value = true;
    debounce = setTimeout(async () => {
        try {
            const { data } = await window.axios.get(route('search'), { params: { q: s } });
            remote.value = data;
        } catch (_) { remote.value = { transformers: [], customers: [] }; }
        finally { loading.value = false; }
    }, 220);
});

const go = (item) => {
    if (!item) return;
    closeModal();
    router.visit(item.href);
};
const onKeydown = (e) => {
    const list = displayItems.value;
    if (e.key === 'ArrowDown') { e.preventDefault(); active.value = Math.min(active.value + 1, list.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); active.value = Math.max(active.value - 1, 0); }
    else if (e.key === 'Enter') { e.preventDefault(); go(list[active.value]); }
    else if (e.key === 'Escape') { closeModal(); }
};

// Atajo global: "/" o ⌘K/Ctrl+K abren el palette.
const onGlobalKey = (e) => {
    const isCmdK = (e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K');
    const isSlash = e.key === '/' && !/^(input|textarea|select)$/i.test(e.target.tagName) && !e.target.isContentEditable;
    if (isCmdK || isSlash) { e.preventDefault(); openModal(); }
};
onMounted(() => document.addEventListener('keydown', onGlobalKey));
onBeforeUnmount(() => { document.removeEventListener('keydown', onGlobalKey); document.body.style.overflow = ''; });

const iconFor = (type) => (type === 'transformer' ? ThunderboltOutlined : type === 'customer' ? TeamOutlined : type === 'recent' ? ClockCircleOutlined : AppstoreOutlined);
const HEX = { green: '#1D7044', lime: '#5AA82E', yellow: '#E9A23B', orange: '#E2661E', red: '#C8281D' };
const hex = (c) => HEX[c] ?? '#9aa0a6';

const sectionFor = (type) => {
    if (type === 'recent') return t('search.section_recent');
    if (type === 'nav') return isEmpty.value ? t('search.section_modules') : t('search.section_results');
    return t('search.section_results');
};
const showSection = (items, i) => i === 0 || sectionFor(items[i].type) !== sectionFor(items[i - 1].type);
</script>

<template>
    <div class="gsearch">
        <!-- Disparador en la shell-bar: abre el popup centrado -->
        <button type="button" class="gsearch__trigger" @click="openModal">
            <SearchOutlined class="gsearch__trigger-icon" />
            <span class="gsearch__trigger-text">{{ t('search.placeholder') }}</span>
            <kbd class="gsearch__kbd">⌘K</kbd>
        </button>

        <!-- Popup centrado (command palette) -->
        <Teleport to="body">
            <div v-if="modalOpen" class="gpalette" @click.self="closeModal">
                <div class="gpalette__box">
                    <div class="gpalette__bar">
                        <SearchOutlined class="gpalette__bar-icon" />
                        <input
                            ref="inputEl"
                            v-model="q"
                            class="gpalette__input"
                            :placeholder="t('search.placeholder')"
                            autocomplete="off"
                            spellcheck="false"
                            @keydown="onKeydown"
                        />
                        <LoadingOutlined v-if="loading" class="gpalette__loading" />
                        <button
                            v-if="micSupported"
                            type="button"
                            class="gpalette__mic"
                            :class="{ 'is-listening': listening }"
                            :title="t('global.voice_search')"
                            @click="startVoiceSearch"
                        >
                            <AudioOutlined />
                        </button>
                        <button class="gpalette__close" type="button" @click="closeModal"><CloseOutlined /></button>
                    </div>

                    <div class="gpalette__body">
                        <ul v-if="hasResults" class="gpalette__list">
                            <template v-for="(item, i) in displayItems" :key="item.type + i">
                                <li v-if="showSection(displayItems, i)" class="gpalette__section">{{ sectionFor(item.type) }}</li>
                                <li
                                    class="gpalette__item"
                                    :class="{ 'is-active': i === active }"
                                    @mouseenter="active = i"
                                    @mousedown.prevent="go(item)"
                                >
                                    <component :is="iconFor(item.type)" class="gpalette__item-icon" />
                                    <div class="gpalette__item-body">
                                        <span class="gpalette__item-label">
                                            <span v-if="item.color" class="gpalette__dot" :style="{ background: hex(item.color) }" :title="item.condition"></span>
                                            {{ item.label }}
                                        </span>
                                        <span v-if="item.sub" class="gpalette__item-sub">{{ item.sub }}</span>
                                    </div>
                                    <span class="gpalette__item-type">{{ item.type === 'recent' ? '↵ abrir' : t('search.type_' + item.type) }}</span>
                                </li>
                            </template>
                        </ul>
                        <div v-else-if="loading" class="gpalette__empty">{{ t('search.placeholder') }}…</div>
                        <div v-else class="gpalette__empty">{{ isEmpty ? t('search.start_hint') : t('search.empty') }}</div>
                    </div>

                    <div class="gpalette__foot">
                        <span><kbd>↑↓</kbd> {{ t('search.nav') }}</span>
                        <span><kbd>↵</kbd> {{ t('search.select') }}</span>
                        <span><kbd>esc</kbd> {{ t('search.close') }}</span>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.gsearch { position: relative; flex: 1; max-width: 460px; min-width: 0; }
.gsearch__trigger { display: flex; align-items: center; gap: 8px; width: 100%; background: rgba(255,255,255,0.12); border: 1px solid transparent; border-radius: 8px; padding: 0 10px; height: 34px; cursor: pointer; transition: background .15s; }
.gsearch__trigger:hover { background: rgba(255,255,255,0.18); }
.gsearch__trigger-icon { color: rgba(255,255,255,0.75); font-size: 14px; }
.gsearch__trigger-text { flex: 1; min-width: 0; color: rgba(255,255,255,0.7); font-size: 13px; text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gsearch__kbd { border: 1px solid rgba(255,255,255,0.4); border-radius: 4px; padding: 0 6px; font-size: 11px; color: rgba(255,255,255,0.7); line-height: 16px; flex-shrink: 0; }

/* ── Popup centrado (command palette) ── */
.gpalette { position: fixed; inset: 0; z-index: 2000; background: rgba(15,23,42,0.38); display: flex; align-items: flex-start; justify-content: center; padding: 11vh 16px 16px; backdrop-filter: blur(2px); }
.gpalette__box { width: min(640px, 94vw); max-height: 76vh; background: #fff; border-radius: 14px; box-shadow: 0 24px 60px rgba(15,23,42,0.30); display: flex; flex-direction: column; overflow: hidden; }
.gpalette__bar { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-bottom: 1px solid #eef0f2; }
.gpalette__bar-icon { color: #6A6D70; font-size: 17px; }
.gpalette__input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; font-size: 15px; color: #1f2937; }
.gpalette__input::placeholder { color: #9aa0a6; }
.gpalette__loading { color: #6A6D70; }
.gpalette__mic { border: none; background: transparent; color: #6A6D70; border-radius: 6px; width: 28px; height: 28px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-size: 15px; }
.gpalette__mic:hover { color: var(--color-primary, #0A6ED1); background: #eef4fb; }
.gpalette__mic.is-listening { color: #C8281D; animation: gpalette-mic 1.1s ease-in-out infinite; }
@keyframes gpalette-mic { 0%,100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.16); opacity: .65; } }
.gpalette__close { border: none; background: #f1f3f5; color: #6A6D70; border-radius: 6px; width: 26px; height: 26px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
.gpalette__body { flex: 1; overflow-y: auto; padding: 6px; }
.gpalette__list { list-style: none; margin: 0; padding: 0; }
.gpalette__section { padding: 9px 12px 4px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #9aa0a6; }
.gpalette__item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 8px; cursor: pointer; }
.gpalette__item.is-active { background: #eef4fb; }
.gpalette__item-icon { color: #0A6ED1; font-size: 16px; flex-shrink: 0; }
.gpalette__item-body { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.gpalette__item-label { font-size: 14px; color: #1f2937; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gpalette__dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
.gpalette__item-sub { font-size: 12px; color: #8c8c8c; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gpalette__item-type { font-size: 10.5px; text-transform: uppercase; letter-spacing: .03em; color: #9aa0a6; flex-shrink: 0; }
.gpalette__empty { padding: 28px 16px; text-align: center; color: #9aa0a6; font-size: 13.5px; }
.gpalette__foot { display: flex; gap: 18px; padding: 9px 14px; border-top: 1px solid #eef0f2; font-size: 11px; color: #9aa0a6; }
.gpalette__foot kbd { border: 1px solid #e0e3e7; border-radius: 4px; padding: 0 5px; font-size: 10.5px; color: #6A6D70; margin-right: 4px; }

html[data-theme="dark"] .gpalette__box { background: #1f2426; }
html[data-theme="dark"] .gpalette__bar, html[data-theme="dark"] .gpalette__foot { border-color: #3a4042; }
html[data-theme="dark"] .gpalette__input, html[data-theme="dark"] .gpalette__item-label { color: #e8eaed; }
html[data-theme="dark"] .gpalette__item.is-active { background: #2a3236; }
html[data-theme="dark"] .gpalette__close { background: #2a2f31; color: #c8ccd0; }

@media (max-width: 680px) {
    .gsearch { max-width: none; }
    .gpalette { padding: 8vh 10px 10px; }
    .gpalette__item-type { display: none; }
    /* El badge ⌘K (atajo de teclado) no aplica en touch y quedaba fijo a la
       derecha ocupando espacio — se oculta en pantallas chicas. */
    .gsearch__kbd { display: none; }
}
</style>
