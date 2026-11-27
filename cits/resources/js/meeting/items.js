/**
 * Meeting discussion items: every field autosaves (text after a short pause, selects / dates at once).
 * A blank row is created on the server when its topic is first typed; Enter in a topic adds / jumps to the next row.
 * Pictures: paste / drop into a row or pick files; "標記" opens the self-hosted draw.io with the picture locked as
 * background (an earlier drawing re-opens editable) and the result replaces the picture in place.
 */
import { openDrawing, srcToDataUri } from '../editor/drawing';

const DELAY = 800;

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function send(method, url, body) {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: body ? JSON.stringify(body) : undefined,
    });
    let data = {};
    try {
        data = await res.json();
    } catch {
        // non-JSON error page
    }
    if (!res.ok || data.ok === false) {
        const msg = data.error ?? data.message ?? (res.status === 419 ? '頁面已過期，請重新整理。' : `儲存失敗（${res.status}）`);
        throw new Error(msg);
    }
    return data;
}

/** PNG exported by draw.io as "xmlpng": the diagram sits in a tEXt chunk named mxfile. */
function isDrawingPng(uri) {
    if (!uri.startsWith('data:image/png;base64,')) return false;
    try {
        return atob(uri.slice(uri.indexOf(',') + 1)).includes('tEXtmxfile');
    } catch {
        return false;
    }
}

const AUTO_ANNOTATE_KEY = 'cits:meeting:auto-annotate';
const imageFiles = (list) => [...(list ?? [])].filter((f) => f.type?.startsWith('image/'));

/**
 * Fallback when the clipboard cannot be read directly (no permission, Firefox, iOS refusing): a box the user pastes
 * into (Ctrl+V, or long-press → 貼上 on a phone keyboard, which delivers clipboard pictures to editable areas).
 * @returns {Promise<File[]>} pasted pictures ([] = cancelled)
 */
function pasteDialog(reason) {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4';
        overlay.innerHTML = `
            <div class="w-full max-w-md rounded-lg bg-white p-4 shadow-xl">
                <h2 class="font-semibold">貼上截圖</h2>
                <p class="mt-1 text-xs text-slate-500"></p>
                <div data-zone contenteditable="true" class="mt-3 flex h-32 items-center justify-center rounded border-2 border-dashed border-indigo-300 bg-indigo-50 p-2 text-center text-sm text-indigo-700 outline-none focus:border-indigo-500">
                    在這裡按 Ctrl+V（手機：長按 → 貼上）
                </div>
                <div class="mt-3 text-right"><button type="button" data-cancel class="rounded-md px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100">取消</button></div>
            </div>`;
        overlay.querySelector('p').textContent = reason;
        const zone = overlay.querySelector('[data-zone]');
        const close = (files) => {
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
            resolve(files);
        };
        const onKey = (e) => e.key === 'Escape' && close([]);
        zone.addEventListener('paste', (e) => {
            e.preventDefault();
            const files = imageFiles(e.clipboardData?.files);
            if (files.length) close(files);
            else zone.textContent = '剪貼簿裡沒有圖片：請先截圖或複製圖片，再貼一次。';
        });
        zone.addEventListener('beforeinput', (e) => e.inputType !== 'insertFromPaste' && e.preventDefault()); // no typing in the box
        overlay.querySelector('[data-cancel]').addEventListener('click', () => close([]));
        overlay.addEventListener('click', (e) => e.target === overlay && close([]));
        document.addEventListener('keydown', onKey, true);
        document.body.appendChild(overlay);
        zone.focus();
    });
}

/**
 * Pictures on the clipboard (screenshot / copied image), read straight away where the browser allows it
 * (must run inside the click), otherwise through the paste box.
 * @returns {Promise<File[]>}
 */
async function clipboardImages() {
    if (navigator.clipboard?.read) {
        try {
            const files = [];
            for (const item of await navigator.clipboard.read()) {
                const type = item.types.find((t) => t.startsWith('image/'));
                if (type) files.push(new File([await item.getType(type)], `paste-${Date.now()}.png`, { type }));
            }
            if (files.length) return files;
            return pasteDialog('剪貼簿裡沒有圖片。請先截圖或複製圖片，然後在下面的框貼上。');
        } catch {
            // permission refused / not allowed here: fall through to the paste box
        }
    }

    return pasteDialog('瀏覽器不讓網頁直接讀取剪貼簿，請在下面的框貼上。');
}

function viewImage(src) {
    const overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 z-50 flex cursor-zoom-out items-center justify-center bg-slate-900/80 p-4';
    const img = document.createElement('img');
    img.src = src;
    img.alt = '附圖';
    img.className = 'max-h-full max-w-full rounded bg-white';
    overlay.appendChild(img);
    const onKey = (e) => e.key === 'Escape' && close();
    const close = () => {
        document.removeEventListener('keydown', onKey, true);
        overlay.remove();
    };
    overlay.addEventListener('click', close);
    document.addEventListener('keydown', onKey, true);
    document.body.appendChild(overlay);
}

function init(list) {
    const base = list.dataset.url;
    const template = list.parentElement.querySelector('template[data-item-template]');
    const imageTemplate = list.parentElement.querySelector('template[data-image-template]');
    const imagesMax = Number(list.dataset.imagesMax) || 0;
    const autoBox = list.parentElement.querySelector('[data-auto-annotate]');
    if (autoBox) {
        try {
            autoBox.checked = localStorage.getItem(AUTO_ANNOTATE_KEY) === '1';
            autoBox.addEventListener('change', () => localStorage.setItem(AUTO_ANNOTATE_KEY, autoBox.checked ? '1' : '0'));
        } catch {
            // storage blocked: the box still works for this page
        }
    }

    /** POST /uploads/image: the picture becomes the user's unsaved attachment; returns its id. */
    async function uploadPicture(file) {
        const body = new FormData();
        body.append('file', file, file.name || 'image.png');
        body.append('context', list.dataset.uploadContext);
        const res = await fetch(list.dataset.uploadUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(data.message ?? (data.errors && Object.values(data.errors)[0]?.[0]) ?? `圖片上傳失敗（${res.status}）`);
        }
        return data.attachment_id;
    }
    const addBtn = list.parentElement.querySelector('[data-item-add]');

    const rows = () => [...list.querySelectorAll('[data-item]')];
    const flushers = new Set();
    const renumber = () => rows().forEach((r, i) => (r.querySelector('[data-item-no]').textContent = `${i + 1}.`));

    function setState(row, text, error = false) {
        const el = row.querySelector('[data-item-state]');
        el.textContent = text;
        el.classList.toggle('text-red-600', error);
        el.classList.toggle('text-slate-400', !error);
    }

    function applyItem(row, item) {
        const link = row.querySelector('[data-issue-link]');
        if (link) {
            link.classList.toggle('hidden', !item.issue_url);
            if (item.issue_url) link.href = item.issue_url;
            link.textContent = item.issue_url ? item.issue_label || `開啟 ${item.issue_no}` : '';
        }
        row.querySelector('[data-issue-closed]')?.classList.toggle('hidden', !item.issue_closed);
        const convert = row.querySelector('[data-issue-convert]');
        if (convert) {
            convert.href = `${base}/${item.id}/issue`;
            convert.classList.toggle('hidden', !!item.issue_url);
        }
        const status = row.querySelector('[data-field="status"]');
        status.disabled = false;
        status.value = item.status;
    }

    function bind(row) {
        const pending = {};
        let timer = null;
        let busy = Promise.resolve();

        const value = (el) => el.value;

        function flush() {
            clearTimeout(timer);
            const fields = { ...pending };
            Object.keys(pending).forEach((k) => delete pending[k]);
            if (!Object.keys(fields).length) return busy;
            busy = busy.then(async () => {
                const id = row.dataset.itemId;
                if (!id && !(fields.topic ?? row.querySelector('[data-field="topic"]').value).trim()) {
                    Object.assign(pending, fields); // keep until the row gets a topic
                    return;
                }
                if (!id) fields.topic ??= row.querySelector('[data-field="topic"]').value;
                setState(row, '儲存中…');
                try {
                    const data = id ? await send('PUT', `${base}/${id}`, fields) : await send('POST', base, { topic: '', ...fields });
                    if (!id) {
                        row.dataset.itemId = data.item.id;
                        row.id = `item-${data.item.id}`;
                    }
                    applyItem(row, data.item);
                    setState(row, '已儲存');
                } catch (err) {
                    setState(row, err.message, true);
                }
            });
            return busy;
        }

        flushers.add(flush);
        row.querySelectorAll('[data-field]').forEach((el) => {
            const field = el.dataset.field;
            const instant = el.tagName === 'SELECT' || el.type === 'date';
            el.addEventListener(instant ? 'change' : 'input', () => {
                pending[field] = value(el);
                setState(row, '尚未儲存');
                clearTimeout(timer);
                if (instant) flush();
                else timer = setTimeout(flush, DELAY);
            });
            if (!instant) el.addEventListener('blur', flush);
            if (field === 'issue_no') {
                // resolve the issue number when leaving the field, not on every keystroke
                el.addEventListener('keydown', (e) => e.key === 'Enter' && (e.preventDefault(), flush()));
            }
        });

        bindImages(row, flush);

        const topic = row.querySelector('[data-field="topic"]');
        topic.addEventListener('keydown', async (e) => {
            if (e.key !== 'Enter' || e.isComposing) return;
            e.preventDefault();
            await flush();
            const next = rows()[rows().indexOf(row) + 1];
            (next ?? addRow()).querySelector('[data-field="topic"]').focus();
        });

        row.querySelector('[data-item-delete]').addEventListener('click', async () => {
            const id = row.dataset.itemId;
            if (!id) {
                row.remove();
                renumber();
                return;
            }
            if (!confirm(`刪除「${topic.value || '這個事項'}」？`)) return;
            try {
                await busy;
                await send('DELETE', `${base}/${id}`);
                flushers.delete(flush);
                row.remove();
                renumber();
            } catch (err) {
                setState(row, err.message, true);
            }
        });

        row.querySelectorAll('[data-item-move]').forEach((btn) =>
            btn.addEventListener('click', async () => {
                const up = btn.dataset.itemMove === 'up';
                const sibling = up ? row.previousElementSibling : row.nextElementSibling;
                if (!sibling) return;
                const id = row.dataset.itemId;
                try {
                    await flush();
                    if (id) await send('POST', `${base}/${id}/move`, { dir: up ? 'up' : 'down' });
                    up ? list.insertBefore(row, sibling) : list.insertBefore(sibling, row);
                    renumber();
                } catch (err) {
                    setState(row, err.message, true);
                }
            }),
        );
    }

    function bindImages(row, flush) {
        const box = row.querySelector('[data-item-images]');
        if (!box || !imageTemplate) return;
        const holder = box.querySelector('[data-image-list]');
        const count = () => holder.querySelectorAll('[data-image-id]').length;

        function render(images) {
            holder.replaceChildren(
                ...images.map((p) => {
                    const fig = imageTemplate.content.firstElementChild.cloneNode(true);
                    fig.dataset.imageId = p.id;
                    fig.querySelector('img').src = p.url;
                    return fig;
                }),
            );
        }

        async function itemId() {
            await flush();
            if (!row.dataset.itemId) throw new Error('請先輸入問題點，再加圖片。');
            return row.dataset.itemId;
        }

        /** @param {boolean} pasted  a screenshot from the clipboard: may go straight into 標記 */
        async function addFiles(files, pasted = false) {
            const pics = imageFiles(files);
            if (!pics.length) return;
            try {
                const id = await itemId();
                if (imagesMax && count() + pics.length > imagesMax) throw new Error(`每個討論事項最多 ${imagesMax} 張圖片。`);
                for (const [n, file] of pics.entries()) {
                    setState(row, pics.length > 1 ? `圖片上傳中 ${n + 1}/${pics.length}…` : '圖片上傳中…');
                    const attachment = await uploadPicture(file);
                    render((await send('POST', `${base}/${id}/images`, { attachment_id: attachment })).images);
                }
                setState(row, '已儲存');
            } catch (err) {
                setState(row, err.message, true);
                return;
            }
            // one pasted screenshot + "貼上後直接標記": open it (the newest picture is the last one)
            const figs = holder.querySelectorAll('[data-image-id]');
            if (pasted && pics.length === 1 && autoBox?.checked && figs.length) annotate(figs[figs.length - 1]);
        }

        async function annotate(fig) {
            const src = fig.querySelector('img').src;
            try {
                const id = await itemId();
                setState(row, '開啟繪圖…');
                const uri = await srcToDataUri(src);
                const blob = isDrawingPng(uri) ? await openDrawing({ png: uri }) : await openDrawing({ images: [src] });
                if (!blob) {
                    setState(row, '');
                    return;
                }
                setState(row, '繪圖上傳中…');
                const attachment = await uploadPicture(new File([blob], 'drawing.png', { type: 'image/png' }));
                render((await send('PUT', `${base}/${id}/images/${fig.dataset.imageId}`, { attachment_id: attachment })).images);
                setState(row, '已儲存');
            } catch (err) {
                setState(row, err.message, true);
            }
        }

        async function remove(fig) {
            if (!confirm('移除這張圖片？')) return;
            try {
                const id = await itemId();
                render((await send('DELETE', `${base}/${id}/images/${fig.dataset.imageId}`)).images);
                setState(row, '已儲存');
            } catch (err) {
                setState(row, err.message, true);
            }
        }

        box.querySelector('[data-image-paste]')?.addEventListener('click', async () => {
            const files = await clipboardImages();
            if (files.length) addFiles(files, true);
        });
        box.querySelector('[data-image-input]')?.addEventListener('change', (e) => {
            addFiles(e.target.files);
            e.target.value = '';
        });
        holder.addEventListener('click', (e) => {
            const fig = e.target.closest('[data-image-id]');
            if (!fig) return;
            if (e.target.closest('[data-image-view]')) viewImage(fig.querySelector('img').src);
            else if (e.target.closest('[data-image-draw]')) annotate(fig);
            else if (e.target.closest('[data-image-remove]')) remove(fig);
        });
        // screenshots pasted into any field of the row, or dropped anywhere on it
        row.addEventListener('paste', (e) => {
            const files = imageFiles(e.clipboardData?.files);
            if (!files.length) return;
            e.preventDefault();
            addFiles(files, true);
        });
        row.addEventListener('dragover', (e) => {
            if ([...(e.dataTransfer?.types ?? [])].includes('Files')) e.preventDefault();
        });
        row.addEventListener('drop', (e) => {
            if (!e.dataTransfer?.files?.length) return;
            e.preventDefault();
            addFiles(e.dataTransfer.files);
        });
    }

    function addRow() {
        const row = template.content.firstElementChild.cloneNode(true);
        list.appendChild(row);
        bind(row);
        renumber();
        return row;
    }

    rows().forEach(bind);
    renumber();
    addBtn?.addEventListener('click', () => addRow().querySelector('[data-field="topic"]').focus());
    if (!rows().length) addRow();

    const pickBtn = list.parentElement.querySelector('[data-issue-pick]');
    pickBtn?.addEventListener('click', async () => {
        const ids = await pickIssues(list.dataset.issuesUrl, JSON.parse(list.dataset.issueStages || '[]'));
        if (!ids?.length) return;
        try {
            await Promise.all([...flushers].map((f) => f())); // keep what is being typed
            await send('POST', list.dataset.issuesAddUrl, { issue_ids: ids });
            location.hash = 'items';
            location.reload();
        } catch (err) {
            alert(err.message);
        }
    });
}

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

/**
 * Issue picker (問題管理): search by number / title, open-only and stage filters, tick several.
 * @returns {Promise<number[]|null>} ticked issue ids, null = cancelled
 */
function pickIssues(url, stages) {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4';
        overlay.innerHTML = `
            <div class="flex max-h-full w-full max-w-4xl flex-col rounded-lg bg-white shadow-xl">
                <div class="space-y-2 border-b border-slate-200 px-5 py-3">
                    <h2 class="font-semibold">從問題管理加入討論事項</h2>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <input type="search" data-q placeholder="單號或標題" maxlength="100" class="w-56 rounded-md border border-slate-300 px-3 py-1.5">
                        <select data-stage class="rounded-md border border-slate-300 py-1.5 pl-2 pr-8">
                            <option value="">全部階段</option>
                            ${stages.map((s) => `<option value="${esc(s)}">${esc(s)}</option>`).join('')}
                        </select>
                        <label class="flex items-center gap-1"><input type="checkbox" data-open checked class="rounded border-slate-300"> 只看未結案</label>
                        <span data-msg class="text-xs text-slate-500"></span>
                    </div>
                </div>
                <div class="min-h-40 flex-1 overflow-y-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="sticky top-0 bg-slate-50 text-left text-xs text-slate-500">
                            <tr><th class="w-8 px-3 py-2"><input type="checkbox" data-all class="rounded border-slate-300" title="全選"></th>
                                <th class="px-3 py-2">單號</th><th class="px-3 py-2">標題</th><th class="px-3 py-2">階段</th><th class="px-3 py-2">狀態</th><th class="px-3 py-2">DEV</th></tr>
                        </thead>
                        <tbody data-rows class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
                <div class="flex items-center gap-2 border-t border-slate-200 px-5 py-3">
                    <span data-count class="min-w-0 truncate text-sm text-slate-600"></span>
                    <button type="button" data-cancel class="ml-auto rounded-md px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100">取消</button>
                    <button type="button" data-ok class="shrink-0 rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">加入</button>
                </div>
            </div>`;
        const $ = (sel) => overlay.querySelector(sel);
        const picked = new Map(); // id -> issue_no, kept across searches
        let seq = 0;
        let timer = null;

        function refresh() {
            $('[data-count]').textContent = picked.size ? `已選 ${picked.size} 張：${[...picked.values()].join('、')}` : '勾選要加入的問題單';
            $('[data-ok]').disabled = picked.size === 0;
            $('[data-ok]').textContent = picked.size ? `加入 ${picked.size} 張` : '加入';
        }

        const row = (i) => `
            <tr class="${i.linked ? 'text-slate-400' : 'cursor-pointer hover:bg-indigo-50'}" data-id="${i.id}" data-no="${esc(i.issue_no)}">
                <td class="px-3 py-2"><input type="checkbox" class="rounded border-slate-300" ${i.linked ? 'disabled' : ''} ${picked.has(i.id) ? 'checked' : ''}></td>
                <td class="whitespace-nowrap px-3 py-2">${esc(i.issue_no)}</td>
                <td class="px-3 py-2">${esc(i.title)}${i.linked ? ' <span class="rounded bg-slate-100 px-1 text-xs">已在討論事項</span>' : ''}</td>
                <td class="px-3 py-2 text-xs">${esc(i.stage)}</td>
                <td class="whitespace-nowrap px-3 py-2 text-xs">${esc(i.status)}</td>
                <td class="whitespace-nowrap px-3 py-2 text-xs">${esc(i.dev)}</td>
            </tr>`;

        async function load() {
            const n = ++seq;
            const params = new URLSearchParams({ q: $('[data-q]').value.trim(), stage: $('[data-stage]').value, open: $('[data-open]').checked ? '1' : '0' });
            $('[data-msg]').textContent = '搜尋中…';
            try {
                const res = await fetch(`${url}?${params}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const data = await res.json();
                if (n !== seq) return; // a newer search is on its way
                if (!res.ok || !data.ok) throw new Error(data.error ?? data.message ?? `讀取失敗（${res.status}）`);
                $('[data-msg]').textContent = data.issues.length >= 100 ? '只顯示最近更新的 100 筆，請用關鍵字縮小' : `${data.issues.length} 筆`;
                $('[data-rows]').innerHTML = data.issues.length
                    ? data.issues.map(row).join('')
                    : '<tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">找不到符合的問題單。</td></tr>';
                $('[data-all]').checked = false;
            } catch (err) {
                $('[data-msg]').textContent = err.message;
            }
        }

        const toggle = (box, on) => {
            const tr = box.closest('tr');
            box.checked = on;
            on ? picked.set(Number(tr.dataset.id), tr.dataset.no) : picked.delete(Number(tr.dataset.id));
        };
        $('[data-rows]').addEventListener('click', (e) => {
            const box = e.target.closest('tr[data-id]')?.querySelector('input');
            if (!box || box.disabled) return;
            toggle(box, e.target === box ? box.checked : !box.checked);
            refresh();
        });
        $('[data-all]').addEventListener('change', (e) => {
            overlay.querySelectorAll('tr[data-id] input:not(:disabled)').forEach((box) => toggle(box, e.target.checked));
            refresh();
        });
        $('[data-q]').addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(load, 300);
        });
        $('[data-q]').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                load();
            }
        });
        $('[data-stage]').addEventListener('change', load);
        $('[data-open]').addEventListener('change', load);

        const close = (result) => {
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
            resolve(result);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') close(null);
        };
        $('[data-cancel]').addEventListener('click', () => close(null));
        $('[data-ok]').addEventListener('click', () => close([...picked.keys()]));
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) close(null);
        });
        document.addEventListener('keydown', onKey, true);
        document.body.appendChild(overlay);
        refresh();
        load();
        $('[data-q]').focus();
    });
}

document.querySelectorAll('[data-items]').forEach(init);

document.querySelector('[data-document-upload]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = form.querySelector('fieldset');
    if (fields.disabled) return;
    const body = new FormData(form);
    const status = form.querySelector('[data-upload-status]');
    const limit = Number(form.dataset.maxFiles);
    if (limit > 0 && body.getAll('files[]').length > limit) {
        status.textContent = '一次最多可上傳 ' + limit + ' 份文件，請分批上傳。';
        return;
    }
    fields.disabled = true;
    status.textContent = '上傳中…';
    try {
        const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || '文件上傳失敗。');
        form.parentElement.querySelector('[data-document-list]').innerHTML = result.list;
        form.reset();
        status.textContent = '文件已上傳。';
    } catch (error) {
        status.textContent = error.message;
    } finally {
        fields.disabled = false;
    }
});
