<script setup>
import { onMounted, onBeforeUnmount, watch, ref } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import {
    BoldOutlined, ItalicOutlined, UndoOutlined, RedoOutlined,
    UnorderedListOutlined, OrderedListOutlined, LinkOutlined,
} from '@ant-design/icons-vue';

/**
 * RichTextEditor — wrapper sobre TipTap que expone v-model con HTML string.
 *
 * Soporta: bold, italic, h2, h3, listas (unordered/ordered), link, undo/redo.
 * El usuario hace setLink() con un prompt nativo — simple y sin dependencias.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    minHeight:  { type: String, default: '180px' },
});
const emit = defineEmits(['update:modelValue']);

const editor = useEditor({
    content: props.modelValue || '',
    extensions: [
        StarterKit.configure({
            heading: { levels: [2, 3] },
        }),
        Link.configure({
            openOnClick: false,
            autolink: true,
            HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: '_blank' },
        }),
    ],
    editorProps: {
        attributes: {
            class: 'rich-editor__content',
        },
    },
    onUpdate: ({ editor }) => {
        const html = editor.getHTML();
        // TipTap manda "<p></p>" cuando esta vacio; lo normalizamos a "" para
        // que required de Laravel rechace el form.
        emit('update:modelValue', html === '<p></p>' ? '' : html);
    },
});

// Sync externo -> editor (cuando el padre cambia modelValue programaticamente).
watch(() => props.modelValue, (val) => {
    if (!editor.value) return;
    const current = editor.value.getHTML();
    if (val !== current) {
        editor.value.commands.setContent(val || '', false);
    }
});

onBeforeUnmount(() => {
    editor.value?.destroy();
});

const setLink = () => {
    if (!editor.value) return;
    const previousUrl = editor.value.getAttributes('link').href;
    const url = window.prompt('URL', previousUrl || 'https://');
    if (url === null) return;
    if (url === '') {
        editor.value.chain().focus().extendMarkRange('link').unsetLink().run();
        return;
    }
    editor.value.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
};

const isActive = (name, attrs = undefined) => {
    if (!editor.value) return false;
    return editor.value.isActive(name, attrs);
};

const cmd = (fn) => () => fn(editor.value?.chain().focus()).run();
</script>

<template>
    <div class="rich-editor" :style="{ minHeight }">
        <div class="rich-editor__toolbar">
            <button type="button" class="rich-editor__btn" :class="{ 'is-active': isActive('bold') }"   :title="'Bold'"   @click="cmd(c => c.toggleBold())"><BoldOutlined /></button>
            <button type="button" class="rich-editor__btn" :class="{ 'is-active': isActive('italic') }" :title="'Italic'" @click="cmd(c => c.toggleItalic())"><ItalicOutlined /></button>
            <span class="rich-editor__sep" />
            <button type="button" class="rich-editor__btn rich-editor__btn--text" :class="{ 'is-active': isActive('heading', { level: 2 }) }" :title="'Heading 2'" @click="cmd(c => c.toggleHeading({ level: 2 }))">H2</button>
            <button type="button" class="rich-editor__btn rich-editor__btn--text" :class="{ 'is-active': isActive('heading', { level: 3 }) }" :title="'Heading 3'" @click="cmd(c => c.toggleHeading({ level: 3 }))">H3</button>
            <span class="rich-editor__sep" />
            <button type="button" class="rich-editor__btn" :class="{ 'is-active': isActive('bulletList') }"  :title="'Bullet list'"  @click="cmd(c => c.toggleBulletList())"><UnorderedListOutlined /></button>
            <button type="button" class="rich-editor__btn" :class="{ 'is-active': isActive('orderedList') }" :title="'Ordered list'" @click="cmd(c => c.toggleOrderedList())"><OrderedListOutlined /></button>
            <span class="rich-editor__sep" />
            <button type="button" class="rich-editor__btn" :class="{ 'is-active': isActive('link') }" :title="'Link'" @click="setLink"><LinkOutlined /></button>
            <span class="rich-editor__sep" />
            <button type="button" class="rich-editor__btn" :title="'Undo'" @click="cmd(c => c.undo())"><UndoOutlined /></button>
            <button type="button" class="rich-editor__btn" :title="'Redo'" @click="cmd(c => c.redo())"><RedoOutlined /></button>
        </div>
        <EditorContent :editor="editor" class="rich-editor__editor" />
    </div>
</template>

<style>
.rich-editor {
    border: 1px solid var(--color-border, #d9d9d9);
    border-radius: 6px;
    background: var(--color-surface, #fff);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.rich-editor__toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    padding: 6px 8px;
    border-bottom: 1px solid var(--color-border-soft, #e5e5e5);
    background: var(--color-surface-alt, #fafafa);
}
.rich-editor__btn {
    background: transparent;
    border: 1px solid transparent;
    border-radius: 4px;
    cursor: pointer;
    padding: 4px 8px;
    color: var(--color-text, #32363a);
    transition: background 0.12s, border-color 0.12s, color 0.12s;
    font-size: 0.85rem;
    line-height: 1;
    min-width: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.rich-editor__btn:hover {
    background: var(--color-brand-soft, #e6f1fb);
    color: var(--color-primary, #0a6ed1);
}
.rich-editor__btn.is-active {
    background: var(--color-brand-soft, #e6f1fb);
    color: var(--color-primary, #0a6ed1);
    border-color: var(--color-primary, #0a6ed1);
}
.rich-editor__btn--text { font-weight: 600; }
.rich-editor__sep {
    width: 1px;
    background: var(--color-border-soft, #e5e5e5);
    margin: 0 4px;
}
.rich-editor__editor { padding: 0; flex: 1; min-width: 0; overflow: hidden; }
.rich-editor__content {
    padding: 12px 14px;
    min-height: 140px;
    outline: none;
    color: var(--color-text, #32363a);
    line-height: 1.55;
    /* Strings sin espacios (URLs, slugs) NO empujan el editor. */
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-word;
}
.rich-editor__content p { margin: 0 0 0.5em; }
.rich-editor__content h2 { font-size: 1.2rem; margin: 0.6em 0 0.3em; }
.rich-editor__content h3 { font-size: 1.05rem; margin: 0.5em 0 0.25em; }
.rich-editor__content ul,
.rich-editor__content ol { padding-left: 1.4em; margin: 0.4em 0; }
.rich-editor__content a { color: var(--color-primary, #0a6ed1); text-decoration: underline; word-break: break-all; }
.rich-editor__content img,
.rich-editor__content table { max-width: 100%; height: auto; }
.rich-editor__content pre { white-space: pre-wrap; word-break: break-word; }
.rich-editor__content:focus { outline: none; }

/* Modo oscuro (token shim) */
html[data-theme="dark"] .rich-editor { background: #1f2227; border-color: #2c3036; }
html[data-theme="dark"] .rich-editor__toolbar { background: #181b1f; border-bottom-color: #2c3036; }
html[data-theme="dark"] .rich-editor__btn { color: #d4d6d8; }
html[data-theme="dark"] .rich-editor__btn:hover,
html[data-theme="dark"] .rich-editor__btn.is-active { background: rgba(77,182,232,0.15); color: #4db6e8; border-color: #4db6e8; }
html[data-theme="dark"] .rich-editor__sep { background: #2c3036; }
html[data-theme="dark"] .rich-editor__content { color: #e5e6e7; }
</style>
