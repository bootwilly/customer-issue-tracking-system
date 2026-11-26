import { Editor, Extension } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import { TextStyle } from '@tiptap/extension-text-style';
import { Color } from '@tiptap/extension-color';
import { TableKit } from '@tiptap/extension-table';
import { NodeSelection } from '@tiptap/pm/state';
import { openDrawing, pickImages, srcToDataUri } from './drawing';

const CHUNK_SIZE_FALLBACK = 5 * 1024 * 1024;
const FONT_SIZES = { small: '0.875em', normal: null, large: '1.25em', xlarge: '1.5em' };
const COLORS = ['#000000', '#dc2626', '#ea580c', '#16a34a', '#2563eb', '#7c3aed'];

const FontSize = Extension.create({
    name: 'fontSize',
    addOptions() {
        return { types: ['textStyle'] };
    },
    addGlobalAttributes() {
        return [
            {
                types: this.options.types,
                attributes: {
                    fontSize: {
                        default: null,
                        parseHTML: (el) => el.style.fontSize || null,
                        renderHTML: (attrs) => (attrs.fontSize ? { style: `font-size: ${attrs.fontSize}` } : {}),
                    },
                },
            },
        ];
    },
    addCommands() {
        return {
            setFontSize:
                (size) =>
                ({ chain }) =>
                    chain().setMark('textStyle', { fontSize: size }).run(),
            unsetFontSize:
                () =>
                ({ chain }) =>
                    chain().setMark('textStyle', { fontSize: null }).removeEmptyTextStyle().run(),
        };
    },
});

/** Image that is a drawing (PNG with an embedded draw.io diagram): marked so it can be re-opened. */
const CitsImage = Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            drawing: {
                default: false,
                parseHTML: (el) => el.getAttribute('data-drawing') === '1',
                renderHTML: (attrs) => (attrs.drawing ? { 'data-drawing': '1' } : {}),
            },
        };
    },
});

const AttachmentLink = Link.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            attachmentId: {
                default: null,
                parseHTML: (el) => el.getAttribute('data-attachment-id'),
                renderHTML: (attrs) => (attrs.attachmentId ? { 'data-attachment-id': attrs.attachmentId } : {}),
            },
        };
    },
});

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
// App may live under a sub-path (e.g. /mescits); all API paths are relative to this base.
const base = () => (document.querySelector('meta[name="app-base"]')?.content ?? '').replace(/\/$/, '');

async function api(url, { method = 'POST', body, json } = {}) {
    const headers = { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (json) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(json);
    }
    const res = await fetch(base() + url, { method, headers, body, credentials: 'same-origin' });
    const data = await res.json().catch(() => null);
    if (!res.ok) {
        const msg = data?.message || (data?.errors && Object.values(data.errors)[0]?.[0]) || `上傳失敗 (${res.status})`;
        throw new Error(msg);
    }
    return data;
}

function fmtSize(n) {
    if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (n >= 1024) return Math.round(n / 1024) + ' KB';
    return n + ' B';
}

const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
const localKey = (ctx) => `cits:draft:${ctx}`;

function init(root) {
    if (root.dataset.editorReady) return;
    root.dataset.editorReady = '1';

    const context = root.dataset.context || '';
    const input = root.querySelector('[data-editor-input]');
    const area = root.querySelector('[data-editor-area]');
    const status = root.querySelector('[data-editor-status]');
    const draftBar = root.querySelector('[data-editor-draftbar]');
    const initial = input.value || '';
    let saveTimer = null;
    let suppress = false;

    const setStatus = (msg, isErr = false) => {
        status.textContent = msg;
        status.className = 'text-xs ' + (isErr ? 'text-red-600' : 'text-gray-500');
    };

    async function postImage(file) {
        const fd = new FormData();
        fd.append('file', file, file.name || 'image.png');
        fd.append('context', context);

        return api('/uploads/image', { body: fd });
    }

    async function uploadImage(file) {
        setStatus('圖片上傳中…');
        try {
            const r = await postImage(file);
            editor.chain().focus().setImage({ src: r.url, alt: r.name }).run();
            setStatus('');
        } catch (e) {
            setStatus(e.message, true);
        }
    }

    /**
     * Drawing: a double-clicked / selected drawing is re-opened. Otherwise a picker lists every image of the content
     * (images inside the current selection pre-ticked); the ticked ones are stacked on a new canvas (none = blank canvas).
     * Picking exactly one drawing re-opens it instead. On save the PNG is uploaded and replaces the picked images at the
     * first one's place; text in between stays. The original screenshots remain attachments of the record.
     */
    async function drawing(target = null) {
        const sel = editor.state.selection;
        // target = {from, node} from a double click; otherwise a selected drawing node (duck-typed: no instanceof across bundles)
        const isDrawing = (n) => n?.type?.name === 'image' && n.attrs.drawing;
        let editing = target && isDrawing(target.node) ? target : isDrawing(sel.node) ? { from: sel.from, node: sel.node } : null;
        let picked = [];
        if (!editing) {
            const all = [];
            editor.state.doc.descendants((node, pos) => {
                if (node.type.name === 'image') {
                    all.push({ pos, node, src: node.attrs.src, drawing: !!node.attrs.drawing, preselected: pos >= sel.from && pos < sel.to });
                }
            });
            if (all.length) {
                const choice = await pickImages(all);
                if (choice === null) return;
                if (choice.length === 1 && choice[0].drawing) {
                    editing = { from: choice[0].pos, node: choice[0].node };
                } else {
                    picked = choice;
                }
            }
        }
        let blob;
        try {
            setStatus('開啟繪圖…');
            blob = editing
                ? await openDrawing({ png: await srcToDataUri(editing.node.attrs.src) })
                : await openDrawing({ images: picked.map((p) => p.src) });
        } catch (e) {
            setStatus(e.message, true);
            return;
        }
        if (!blob) {
            setStatus('');
            return;
        }
        setStatus('繪圖上傳中…');
        let r;
        try {
            r = await postImage(new File([blob], 'drawing.png', { type: 'image/png' }));
        } catch (e) {
            setStatus(e.message, true);
            return;
        }
        const attrs = { src: r.url, alt: '繪圖', drawing: true };
        editor
            .chain()
            .focus()
            .command(({ tr, state }) => {
                const type = state.schema.nodes.image;
                if (editing) {
                    const node = tr.doc.nodeAt(editing.from);
                    if (node?.type === type) {
                        tr.setNodeMarkup(editing.from, undefined, { ...node.attrs, ...attrs });
                        return true;
                    }
                }
                // remove picked images last-to-first (still checking each is the same image), insert at the first one
                const still = picked.filter((p) => tr.doc.nodeAt(p.pos)?.type === type && tr.doc.nodeAt(p.pos).attrs.src === p.src);
                const at = still.length ? still[0].pos : state.selection.to;
                [...still].reverse().forEach((p) => tr.delete(p.pos, p.pos + tr.doc.nodeAt(p.pos).nodeSize));
                tr.insert(tr.mapping.map(at, -1), type.create(attrs));
                return true;
            })
            .run();
        setStatus('');
    }

    async function uploadChunks(file) {
        const init = await api('/uploads/init', { json: { name: file.name, size: file.size, context } });
        const cs = init.chunk_size || CHUNK_SIZE_FALLBACK;
        const total = Math.ceil(file.size / cs);
        for (let i = 0; i < total; i++) {
            const fd = new FormData();
            fd.append('index', i);
            fd.append('chunk', file.slice(i * cs, (i + 1) * cs), `${i}.part`);
            await api(`/uploads/${init.id}/chunk`, { body: fd });
            setStatus(`上傳中 ${Math.round(((i + 1) / total) * 100)}%`);
        }
        return api(`/uploads/${init.id}/complete`);
    }

    async function uploadFile(file) {
        try {
            setStatus('準備上傳 0%');
            if (/^meeting:(notes|internal):/.test(context) && /\.md$/i.test(file.name)) {
                throw new Error('請在「Markdown 文件」區塊上傳 .md 檔。');
            }
            const r = await uploadChunks(file);
            editor
                .chain()
                .focus()
                .insertContent(
                    `<a href="${r.url}" data-attachment-id="${r.attachment_id}">${esc(r.name)} (${fmtSize(r.size)})</a> `,
                )
                .run();
            setStatus('');
        } catch (e) {
            setStatus(e.message, true);
            return file.name + '：' + e.message;
        }
    }

    const imageFiles = (list) => Array.from(list || []).filter((f) => f.type.startsWith('image/'));

    const editor = new Editor({
        element: area,
        content: initial,
        extensions: [
            StarterKit.configure({ link: false, heading: { levels: [2, 3] } }),
            AttachmentLink.configure({ openOnClick: false, autolink: false }),
            CitsImage,
            TextStyle,
            Color,
            FontSize,
            // no column resizing: keeps the saved HTML free of width styles (the sanitizer drops them anyway)
            TableKit.configure({ table: { resizable: false } }),
        ],
        editorProps: {
            attributes: { class: 'prose max-w-none min-h-[10rem] p-3 focus:outline-none' },
            handlePaste(view, event) {
                const files = imageFiles(event.clipboardData?.files);
                if (!files.length) return false;
                event.preventDefault();
                files.forEach(uploadImage);
                return true;
            },
            handleDoubleClickOn(view, pos, node) {
                if (node.type.name !== 'image' || !node.attrs.drawing) return false;
                view.dispatch(view.state.tr.setSelection(NodeSelection.create(view.state.doc, pos)));
                drawing({ from: pos, node });
                return true;
            },
            handleDrop(view, event) {
                const files = imageFiles(event.dataTransfer?.files);
                if (!files.length) return false;
                event.preventDefault();
                files.forEach(uploadImage);
                return true;
            },
        },
        onUpdate({ editor }) {
            if (suppress) return;
            const html = editor.isEmpty ? '' : editor.getHTML();
            input.value = html;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            try {
                localStorage.setItem(localKey(context), JSON.stringify({ html, at: Date.now() }));
            } catch (_) {}
            clearTimeout(saveTimer);
            if (context) {
                saveTimer = setTimeout(() => api('/drafts', { json: { context, html } }).catch(() => {}), 3000);
            }
            refreshToolbar();
        },
        onSelectionUpdate: () => refreshToolbar(),
    });
    root._editor = editor;

    // ---- toolbar ----
    const btn = (name) => root.querySelector(`[data-cmd="${name}"]`);
    const run = {
        bold: () => editor.chain().focus().toggleBold().run(),
        italic: () => editor.chain().focus().toggleItalic().run(),
        bulletList: () => editor.chain().focus().toggleBulletList().run(),
        orderedList: () => editor.chain().focus().toggleOrderedList().run(),
        undo: () => editor.chain().focus().undo().run(),
        redo: () => editor.chain().focus().redo().run(),
        image: () => root.querySelector('[data-image-file]').click(),
        attach: () => root.querySelector('[data-attach-file]').click(),
        drawing: () => drawing(),
    };
    root.querySelectorAll('[data-cmd]').forEach((b) =>
        b.addEventListener('click', (e) => {
            e.preventDefault();
            run[b.dataset.cmd]?.();
        }),
    );
    // table menu: value = command; resets to the placeholder after each action
    const TABLE_CMDS = {
        insert: (c) => c.insertTable({ rows: 3, cols: 3, withHeaderRow: true }),
        rowBefore: (c) => c.addRowBefore(),
        rowAfter: (c) => c.addRowAfter(),
        rowDelete: (c) => c.deleteRow(),
        colBefore: (c) => c.addColumnBefore(),
        colAfter: (c) => c.addColumnAfter(),
        colDelete: (c) => c.deleteColumn(),
        headerRow: (c) => c.toggleHeaderRow(),
        merge: (c) => c.mergeCells(),
        split: (c) => c.splitCell(),
        tableDelete: (c) => c.deleteTable(),
    };
    const tableSel = root.querySelector('[data-table-cmd]');
    tableSel?.addEventListener('change', (e) => {
        const fn = TABLE_CMDS[e.target.value];
        if (fn && !fn(editor.chain().focus()).run()) setStatus('游標要先放在表格裡（合併需先選取多格）。', true);
        e.target.value = '';
    });

    root.querySelector('[data-font-size]').addEventListener('change', (e) => {
        const v = FONT_SIZES[e.target.value];
        v ? editor.chain().focus().setFontSize(v).run() : editor.chain().focus().unsetFontSize().run();
    });
    root.querySelectorAll('[data-color]').forEach((b) =>
        b.addEventListener('click', (e) => {
            e.preventDefault();
            editor.chain().focus().setColor(b.dataset.color).run();
        }),
    );
    root.querySelector('[data-image-file]').addEventListener('change', (e) => {
        imageFiles(e.target.files).forEach(uploadImage);
        e.target.value = '';
    });
    root.querySelector('[data-attach-file]').addEventListener('change', async (e) => {
        const files = Array.from(e.target.files);
        const form = root.closest('form');
        const errors = [];
        const waitForUpload = (event) => {
            event.preventDefault();
            setStatus('附件上傳中，請稍候再儲存。', true);
        };
        form?.addEventListener('submit', waitForUpload);
        try {
            for (const file of files) {
                const error = await uploadFile(file);
                if (error) errors.push(error);
            }
            if (errors.length) setStatus(errors.join('；'), true);
        } finally {
            form?.removeEventListener('submit', waitForUpload);
            e.target.value = '';
        }
    });

    function refreshToolbar() {
        if (tableSel) {
            const inTable = editor.isActive('table');
            tableSel.querySelectorAll('option[data-in-table]').forEach((o) => (o.disabled = !inTable));
        }
        [
            ['bold', 'bold'],
            ['italic', 'italic'],
            ['bulletList', 'bulletList'],
            ['orderedList', 'orderedList'],
        ].forEach(([cmd, mark]) => btn(cmd)?.classList.toggle('bg-gray-200', editor.isActive(mark)));
    }

    // ---- drafts ----
    async function checkDraft() {
        if (!context) return;
        let server = null;
        let local = null;
        try {
            server = await api(`/drafts?context=${encodeURIComponent(context)}`, { method: 'GET' });
        } catch (_) {}
        try {
            local = JSON.parse(localStorage.getItem(localKey(context)) || 'null');
        } catch (_) {}
        const sTime = server?.updated_at ? Date.parse(server.updated_at) : 0;
        const cand = local && local.at >= sTime ? { html: local.html, at: local.at } : server ? { html: server.html, at: sTime } : null;
        if (!cand || !cand.html || cand.html === initial) return;

        draftBar.classList.remove('hidden');
        draftBar.querySelector('[data-draft-time]').textContent = new Date(cand.at).toLocaleString('zh-TW', { hour12: false });
        draftBar.querySelector('[data-draft-restore]').onclick = () => {
            editor.commands.setContent(cand.html, { emitUpdate: true });
            draftBar.classList.add('hidden');
        };
        draftBar.querySelector('[data-draft-discard]').onclick = () => {
            window.citsEditorClear(context);
            draftBar.classList.add('hidden');
        };
    }
    checkDraft();

    // 取消按鈕：data-editor-cancel 清草稿
    root.closest('form')?.querySelectorAll('[data-editor-cancel]').forEach((b) => b.addEventListener('click', () => window.citsEditorClear(context)));
}

window.citsEditorClear = function (context) {
    try {
        localStorage.removeItem(localKey(context));
    } catch (_) {}
    return api(`/drafts?context=${encodeURIComponent(context)}`, { method: 'DELETE' }).catch(() => {});
};

function scan() {
    document.querySelectorAll('[data-editor]').forEach(init);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
else scan();

export { COLORS };
