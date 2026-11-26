/**
 * Drawing area for the rich-text editor: the self-hosted draw.io opens in a full-screen overlay.
 * Start from images (stacked top to bottom, locked as background) or from an earlier drawing.
 * Result: a PNG with the diagram embedded (draw.io "xmlpng"), so it can be re-opened and edited.
 */
const MAX_W = 1600; // px a screenshot keeps in the canvas (bigger ones are scaled down)
const GAP = 40;
const RED = '#dc2626';

const base = () => (document.querySelector('meta[name="app-base"]')?.content ?? '').replace(/\/$/, '');
const escAttr = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

function blobToDataUri(blob) {
    return new Promise((resolve, reject) => {
        const r = new FileReader();
        r.onload = () => resolve(r.result);
        r.onerror = () => reject(r.error);
        r.readAsDataURL(blob);
    });
}

export async function srcToDataUri(src) {
    const res = await fetch(src, { credentials: 'same-origin' });
    if (!res.ok) throw new Error(`圖片讀取失敗（${res.status}）`);
    return blobToDataUri(await res.blob());
}

function naturalSize(dataUri) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve({ w: img.naturalWidth, h: img.naturalHeight });
        img.onerror = () => reject(new Error('圖片無法解析'));
        img.src = dataUri;
    });
}

/** mxGraphModel with the images stacked vertically, locked so drawing on top never drags them. */
async function modelFromImages(srcs) {
    let y = 0;
    const cells = [];
    for (const [i, src] of srcs.entries()) {
        const uri = await srcToDataUri(src);
        const { w, h } = await naturalSize(uri);
        const k = Math.min(1, MAX_W / w);
        const [cw, ch] = [Math.round(w * k), Math.round(h * k)];
        // draw.io image style: "data:<mime>,<base64>" (no ";base64" because ";" separates style entries)
        const img = uri.replace(/^data:([^;,]+);base64,/, 'data:$1,');
        const style = `shape=image;image=${img};imageAspect=0;aspect=fixed;locked=1;editable=0;strokeColor=none;fillColor=none;`;
        cells.push(`<mxCell id="img${i}" value="" style="${escAttr(style)}" vertex="1" parent="1"><mxGeometry x="0" y="${y}" width="${cw}" height="${ch}" as="geometry"/></mxCell>`);
        y += ch + GAP;
    }

    return `<mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>${cells.join('')}</root></mxGraphModel>`;
}

function dataUriToBlob(uri) {
    const [head, body] = uri.split(',', 2);
    const mime = head.match(/^data:([^;]+)/)?.[1] ?? 'image/png';
    const bin = head.includes(';base64') ? atob(body) : decodeURIComponent(body);
    return new Blob([Uint8Array.from(bin, (c) => c.charCodeAt(0))], { type: mime });
}

/**
 * Open the drawing overlay.
 * @param {{images?: string[], png?: string}} start  image URLs to stack, or a data URI of an earlier drawing
 * @returns {Promise<Blob|null>} PNG (with embedded diagram) or null when cancelled
 */
export function openDrawing({ images = [], png = null } = {}) {
    return new Promise((resolve) => {
        const params = new URLSearchParams({
            embed: '1', proto: 'json', spin: '1', configure: '1', saveAndExit: '1', noSaveBtn: '1',
            pages: '0', lang: 'zh-tw', libs: 'general',
        });
        const src = `${base()}/drawio/index.html?${params}`;
        const origin = new URL(src, location.href).origin;

        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-50 flex flex-col bg-white';
        overlay.innerHTML = `
            <div class="flex items-center gap-3 border-b border-slate-200 px-4 py-2 text-sm">
                <strong>繪圖</strong>
                <span class="text-xs text-slate-500">左側拖出框、圓、箭頭；截圖已鎖定為底圖（右鍵可解鎖）。可直接 Ctrl+V 再貼圖。畫好按右上「儲存並退出」。</span>
                <span data-drawing-status class="ml-auto text-xs text-slate-500">載入中…</span>
            </div>
            <iframe title="繪圖" class="w-full flex-1 border-0"></iframe>`;
        const frame = overlay.querySelector('iframe');
        const status = overlay.querySelector('[data-drawing-status]');
        const post = (msg) => frame.contentWindow.postMessage(JSON.stringify(msg), origin);
        let saving = false;

        const close = (result) => {
            window.removeEventListener('message', onMessage);
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
            document.body.style.overflow = '';
            resolve(result);
        };
        const onKey = (e) => {
            // Esc inside the parent page would otherwise do nothing; ask before dropping work
            if (e.key === 'Escape' && confirm('放棄這次繪圖？')) close(null);
        };

        async function onMessage(e) {
            if (e.source !== frame.contentWindow || e.origin !== origin || typeof e.data !== 'string') return;
            let m;
            try {
                m = JSON.parse(e.data);
            } catch {
                return;
            }
            switch (m.event) {
                case 'configure':
                    post({
                        action: 'configure',
                        config: {
                            defaultFonts: ['Microsoft JhengHei', 'PingFang TC', 'Noto Sans TC'],
                            // annotation look: red outline, no fill, straight red arrows
                            defaultVertexStyle: { strokeColor: RED, fillColor: 'none', strokeWidth: '3', fontColor: RED, fontSize: '16' },
                            defaultEdgeStyle: { edgeStyle: 'none', rounded: '0', strokeColor: RED, strokeWidth: '3', endArrow: 'classic', endFill: '1', html: '1' },
                        },
                    });
                    break;
                case 'init':
                    try {
                        post(png ? { action: 'load', xmlpng: png, autosave: 0 } : { action: 'load', xml: images.length ? await modelFromImages(images) : '', autosave: 0 });
                        status.textContent = '';
                    } catch (err) {
                        status.textContent = `載入失敗：${err.message}`;
                    }
                    break;
                case 'save':
                    if (saving) break;
                    saving = true;
                    status.textContent = '產生圖片中…';
                    post({ action: 'export', format: 'xmlpng', spinKey: 'saving', scale: 1, border: 10 });
                    break;
                case 'export':
                    if (saving) close(dataUriToBlob(m.data));
                    break;
                case 'exit':
                    if (!m.modified || confirm('有未儲存的繪圖，確定放棄？')) close(null);
                    break;
            }
        }

        window.addEventListener('message', onMessage);
        document.addEventListener('keydown', onKey, true);
        document.body.style.overflow = 'hidden';
        document.body.appendChild(overlay);
        frame.src = src;
    });
}

/**
 * Picker: thumbnails of every image in the content, in document order; tick the ones to draw on together.
 * @param {{src: string, drawing: boolean, preselected: boolean}[]} items
 * @returns {Promise<object[]|null>} ticked items in document order ([] = blank canvas), null = cancelled
 */
export function pickImages(items) {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4';
        overlay.innerHTML = `
            <div class="flex max-h-full w-full max-w-3xl flex-col rounded-lg bg-white shadow-xl">
                <div class="border-b border-slate-200 px-5 py-3">
                    <h2 class="font-semibold">選擇要一起繪圖的圖片</h2>
                    <p class="text-xs text-slate-500">勾選的圖片會依內文順序由上往下排在同一張畫布，畫完合成一張圖。只勾一張「繪圖」＝重新編輯它。</p>
                </div>
                <div data-pick-list class="grid flex-1 grid-cols-2 gap-3 overflow-y-auto overflow-x-hidden p-5 sm:grid-cols-3"></div>
                <div class="flex items-center gap-2 border-t border-slate-200 px-5 py-3">
                    <span data-pick-count class="text-sm text-slate-600"></span>
                    <button type="button" data-pick-blank class="ml-auto rounded-md px-3 py-1.5 text-sm text-slate-600 ring-1 ring-slate-300 hover:bg-slate-50">空白畫布</button>
                    <button type="button" data-pick-cancel class="rounded-md px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100">取消</button>
                    <button type="button" data-pick-ok class="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">開始繪圖</button>
                </div>
            </div>`;
        const list = overlay.querySelector('[data-pick-list]');
        const count = overlay.querySelector('[data-pick-count]');
        const ok = overlay.querySelector('[data-pick-ok]');
        const picked = new Set(items.map((it, i) => (it.preselected ? i : -1)).filter((i) => i >= 0));

        items.forEach((it, i) => {
            const card = document.createElement('label');
            card.className = 'relative block min-w-0 cursor-pointer rounded-md border-2 p-1';
            card.innerHTML = `
                <input type="checkbox" class="absolute left-2 top-2 h-5 w-5 rounded border-slate-300">
                <span data-order class="absolute right-2 top-2 hidden rounded-full bg-indigo-600 px-2 text-xs font-medium text-white"></span>
                <img alt="" class="h-32 w-full rounded object-contain bg-slate-50">
                <span class="mt-1 block text-center text-xs text-slate-500">第 ${i + 1} 張${it.drawing ? '（繪圖）' : ''}</span>`;
            card.querySelector('img').src = it.src;
            const box = card.querySelector('input');
            box.checked = picked.has(i);
            box.addEventListener('change', () => {
                box.checked ? picked.add(i) : picked.delete(i);
                refresh();
            });
            list.appendChild(card);
        });

        function refresh() {
            const order = [...picked].sort((a, b) => a - b);
            list.querySelectorAll('label').forEach((card, i) => {
                const n = order.indexOf(i);
                card.classList.toggle('border-indigo-500', n >= 0);
                card.classList.toggle('border-transparent', n < 0);
                const badge = card.querySelector('[data-order]');
                badge.classList.toggle('hidden', n < 0);
                badge.textContent = n >= 0 ? String(n + 1) : '';
            });
            const one = order.length === 1 && items[order[0]].drawing;
            count.textContent = order.length ? `已選 ${order.length} 張${one ? '（重新編輯此繪圖）' : ''}` : '請勾選圖片，或按「空白畫布」';
            ok.disabled = order.length === 0;
        }

        const close = (result) => {
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
            resolve(result);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') close(null);
        };
        overlay.querySelector('[data-pick-cancel]').addEventListener('click', () => close(null));
        overlay.querySelector('[data-pick-blank]').addEventListener('click', () => close([]));
        ok.addEventListener('click', () => close([...picked].sort((a, b) => a - b).map((i) => items[i])));
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) close(null);
        });
        document.addEventListener('keydown', onKey, true);
        document.body.appendChild(overlay);
        refresh();
    });
}
