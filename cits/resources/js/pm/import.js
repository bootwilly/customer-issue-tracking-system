/**
 * PM Excel import: upload the workbook (chunked), preview the rows the server parsed, then for every chosen row
 * compose each picture with the shapes drawn over it (red boxes, arrows, text boxes) — or a group of loose shapes
 * (a flowchart) — into one PNG, upload it as a temp picture of that row, and ask the server to create the issues.
 * Geometry comes from the workbook in EMU (9525 per px at 100 %).
 */
const EMU = 9525;
const EMU_PER_PT = 12700;
const MAX_SIDE = 4000;
const DRAWING_SCALE = 2;
const FONT = '"Microsoft JhengHei", "PingFang TC", "Noto Sans TC", sans-serif';

const root = document.getElementById('issue-import');
const $ = (sel) => root.querySelector(sel);
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const STATE = {
    new: ['新建', 'bg-emerald-100 text-emerald-800'],
    changed: ['內容有變更', 'bg-amber-100 text-amber-800'],
    same: ['已匯入', 'bg-slate-100 text-slate-600'],
    skip: ['略過', 'bg-slate-100 text-slate-500'],
    error: ['錯誤', 'bg-rose-100 text-rose-700'],
};

let fileId = null;
let data = null;

async function api(url, { body, json } = {}) {
    const headers = { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (json) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(json);
    }
    const res = await fetch(url, { method: 'POST', headers, body, credentials: 'same-origin' });
    const out = await res.json().catch(() => null);
    if (!res.ok) {
        throw new Error(out?.message || (out?.errors && Object.values(out.errors)[0]?.[0]) || (res.status === 419 ? '頁面已過期，請重新整理。' : `失敗（${res.status}）`));
    }
    return out;
}

const status = (msg, err = false) => {
    const el = $('[data-status]');
    el.textContent = msg;
    el.className = `mt-2 text-sm ${err ? 'text-rose-700' : 'text-slate-700'}`;
};

async function uploadWorkbook(file) {
    const upload = root.dataset.uploadBase;
    const init = await api(`${upload}/init`, { json: { name: file.name, size: file.size, context: root.dataset.fileContext } });
    const total = Math.ceil(file.size / init.chunk_size);
    for (let i = 0; i < total; i++) {
        const fd = new FormData();
        fd.append('index', i);
        fd.append('chunk', file.slice(i * init.chunk_size, (i + 1) * init.chunk_size), `${i}.part`);
        await api(`${upload}/${init.id}/chunk`, { body: fd });
        status(`上傳中 ${Math.round(((i + 1) / total) * 100)}%`);
    }
    return api(`${upload}/${init.id}/complete`);
}

// ---------- preview ----------

$('[data-preview]').addEventListener('click', async () => {
    const file = $('[data-file]').files[0];
    const source = $('[data-source]').value.trim();
    if (!source) return status('請填來源代碼。', true);
    if (!file) return status('請選擇 Excel 檔。', true);
    $('[data-preview]').disabled = true;
    $('[data-result]').classList.add('hidden');
    try {
        fileId = (await uploadWorkbook(file)).attachment_id;
        status('解析中…');
        data = await api(root.dataset.previewUrl, { json: { file_id: fileId, source } });
        $('[data-provenance]').value = '';
        render();
        status(`解析完成：共 ${data.rows.length} 列。`);
    } catch (e) {
        status(e.message, true);
        $('[data-preview-area]').classList.add('hidden');
    } finally {
        $('[data-preview]').disabled = false;
    }
});

const imageBlocks = (row) => row.blocks.filter((b) => b.type === 'image' || b.type === 'drawing');

function options(list, value, valueKey, labelKey) {
    return ['<option value="">-</option>']
        .concat(list.map((o) => `<option value="${esc(o[valueKey])}" ${String(o[valueKey]) === String(value ?? '') ? 'selected' : ''}>${esc(o[labelKey])}</option>`))
        .join('');
}

function render() {
    const c = data.counts;
    $('[data-counts]').innerHTML = Object.entries(STATE)
        .map(([k, [label, cls]]) => `<span class="rounded px-2 py-0.5 ${cls}">${label} ${c[k] ?? 0}</span>`)
        .join('');
    const sel = 'rounded border border-slate-300 px-1 py-0.5 text-xs';
    $('[data-rows]').innerHTML = data.rows
        .map((r, i) => {
            const [label, cls] = STATE[r.state];
            const pickable = r.state === 'new' || r.state === 'changed';
            const editable = r.state === 'new';
            const notes = [
                ...r.errors.map((m) => `<li class="text-rose-700">${esc(m)}</li>`),
                ...r.warnings.map((m) => `<li class="text-amber-700">${esc(m)}</li>`),
                ...(r.existing ? [`<li>已匯入為 <a class="text-indigo-700 hover:underline" target="_blank" href="${esc(r.existing.url)}">${esc(r.existing.issue_no)}</a>${r.state === 'changed' ? '，勾選後會把新內容寫成補充說明' : ''}</li>`] : []),
                ...r.similar.map((s) => `<li>相似：<a class="text-indigo-700 hover:underline" target="_blank" href="${esc(s.url)}">${esc(s.no)} ${esc(s.title)}</a></li>`),
            ].join('');
            return `<tr class="border-t align-top" data-i="${i}">
                <td class="px-2 py-2">${pickable ? `<input type="checkbox" data-pick ${r.state === 'new' ? 'checked' : ''} title="${r.state === 'new' ? '建立內部單' : '寫成補充說明'}">` : ''}</td>
                <td class="px-2 py-2">${esc(r.no)}</td>
                <td class="px-2 py-2"><span class="whitespace-nowrap rounded px-1.5 py-0.5 text-xs ${cls}">${label}</span><div class="text-xs text-slate-500">${esc(r.status_label)}</div></td>
                <td class="px-2 py-2"><div class="font-medium">${esc(r.title)}</div><div class="text-xs text-slate-500">${esc(r.func)}</div></td>
                <td class="px-2 py-2">${editable ? `<select data-pt class="${sel}">${options(data.process_types, r.process_type, 'code', 'name')}</select>` : ''}</td>
                <td class="px-2 py-2">${editable ? `<select data-page class="${sel} max-w-[10rem]">${options(data.mes_pages, r.mes_page_id, 'id', 'name')}</select>` : ''}</td>
                <td class="px-2 py-2">${esc(r.severity)}</td>
                <td class="px-2 py-2">${imageBlocks(r).length || ''}</td>
                <td class="px-2 py-2"><ul class="space-y-0.5 text-xs">${notes}</ul></td>
                <td class="px-2 py-2">${r.state === 'error' ? '' : '<button type="button" data-open class="whitespace-nowrap text-xs text-indigo-700 hover:underline">內容</button>'}</td>
            </tr><tr class="hidden" data-detail="${i}"><td></td><td colspan="9" class="px-2 pb-4"></td></tr>`;
        })
        .join('');
    $('[data-preview-area]').classList.remove('hidden');
    $('[data-toggle-all]').checked = true;
}

$('[data-rows]').addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-open]');
    if (!btn) return;
    const i = btn.closest('tr').dataset.i;
    const tr = $(`[data-detail="${i}"]`);
    tr.classList.toggle('hidden');
    if (tr.classList.contains('hidden') || tr.dataset.loaded) return;
    tr.dataset.loaded = '1';
    const cell = tr.lastElementChild;
    const r = data.rows[i];
    cell.innerHTML = `<div class="space-y-2 rounded border border-slate-200 bg-slate-50 p-3 text-sm">
        <p class="whitespace-pre-wrap">${esc(r.content)}</p>
        ${r.blocks
            .map((b, k) => {
                if (b.type === 'text') return `<p class="whitespace-pre-wrap">${esc(b.text)}</p>`;
                if (b.type === 'table')
                    return `<table class="border text-xs">${b.rows.map((row) => `<tr>${row.map((v) => `<td class="border px-1">${esc(v)}</td>`).join('')}</tr>`).join('')}</table>`;
                return `<div data-canvas="${k}" class="text-xs text-slate-500">圖片產生中…</div>`;
            })
            .join('')}
        ${r.notes.length ? `<div class="border-t pt-2">${r.notes.map((n) => `<p><strong>${esc(n.label)}</strong>：<span class="whitespace-pre-wrap">${esc(n.value)}</span></p>`).join('')}</div>` : ''}
    </div>`;
    for (const [k, b] of r.blocks.entries()) {
        if (b.type !== 'image' && b.type !== 'drawing') continue;
        const holder = cell.querySelector(`[data-canvas="${k}"]`);
        try {
            const canvas = await compose(b);
            canvas.className = 'max-w-full rounded border bg-white';
            canvas.style.maxHeight = '480px';
            holder.replaceWith(canvas);
        } catch (err) {
            holder.textContent = `圖片無法產生：${err.message}`;
        }
    }
});

$('[data-toggle-all]').addEventListener('change', (e) => {
    root.querySelectorAll('[data-rows] tr[data-i]').forEach((tr) => {
        const box = tr.querySelector('[data-pick]');
        if (box && data.rows[tr.dataset.i].state === 'new') box.checked = e.target.checked;
    });
});

// ---------- apply ----------

$('[data-apply]').addEventListener('click', async () => {
    const picked = [...root.querySelectorAll('[data-rows] tr[data-i]')].filter((tr) => tr.querySelector('[data-pick]')?.checked);
    if (!picked.length) return status('請勾選要匯入的列。', true);
    const nNew = picked.filter((tr) => data.rows[tr.dataset.i].state === 'new').length;
    const nAdd = picked.length - nNew;
    const provenance = $('[data-provenance]').value;
    if (nNew && !provenance) return status('請選擇本批新單的問題出處。', true);
    if (!confirm(`將建立 ${nNew} 張內部單${nAdd ? `、新增 ${nAdd} 筆補充說明` : ''}。建立後無法刪除，只能取消。確定？`)) return;
    $('[data-apply]').disabled = true;
    try {
        const totalImages = picked.reduce((n, tr) => n + imageBlocks(data.rows[tr.dataset.i]).length, 0);
        let doneImages = 0;
        const rows = [];
        for (const tr of picked) {
            const r = data.rows[tr.dataset.i];
            const images = [];
            for (const b of imageBlocks(r)) {
                status(`產生並上傳圖片 ${++doneImages}/${totalImages}（序號 ${r.no}）`);
                const blob = await toBlob(await compose(b));
                images.push(await uploadPicture(blob, r.no, images.length));
            }
            const row = { no: r.no, action: r.state === 'new' ? 'create' : 'addendum', images };
            if (r.state === 'new') {
                row.process_type = tr.querySelector('[data-pt]')?.value || null;
                row.mes_page_id = tr.querySelector('[data-page]')?.value ? Number(tr.querySelector('[data-page]').value) : null;
            }
            rows.push(row);
        }
        status('建立中…');
        const res = await api(root.dataset.applyUrl, { json: { file_id: fileId, source: data.source, provenance: provenance || null, rows } });
        const box = $('[data-result]');
        box.innerHTML = `<p class="mb-2 font-medium">完成：${res.done.filter((d) => d.action === 'create').length} 張內部單已建立${res.done.some((d) => d.action === 'addendum') ? `，${res.done.filter((d) => d.action === 'addendum').length} 筆補充說明` : ''}。</p>
            <ul class="grid gap-1 md:grid-cols-3">${res.done.map((d) => `<li>#${esc(d.no)} → <a class="text-indigo-700 hover:underline" target="_blank" href="${esc(d.url)}">${esc(d.issue_no)}</a>${d.action === 'addendum' ? '（補充說明）' : ''}</li>`).join('')}</ul>`;
        box.classList.remove('hidden');
        $('[data-preview-area]').classList.add('hidden');
        status('匯入完成。要再匯入，請重新上傳並預覽。');
    } catch (e) {
        status(e.message, true);
    } finally {
        $('[data-apply]').disabled = false;
    }
});

async function uploadPicture(blob, no, k) {
    const fd = new FormData();
    fd.append('file', blob, `import-${no}-${k + 1}.png`);
    fd.append('context', `${root.dataset.fileContext}:${fileId}:${no}`);
    return (await api(`${root.dataset.uploadBase}/image`, { body: fd })).attachment_id;
}

const toBlob = (canvas) => new Promise((ok, fail) => canvas.toBlob((b) => (b ? ok(b) : fail(new Error('圖片產生失敗'))), 'image/png'));

// ---------- composing ----------

const bitmaps = new Map();

async function bitmap(media) {
    if (!bitmaps.has(media)) {
        bitmaps.set(
            media,
            (async () => {
                const url = root.dataset.mediaUrl.replace('__FILE__', fileId) + '?p=' + encodeURIComponent(media);
                const res = await fetch(url, { credentials: 'same-origin' });
                if (!res.ok) throw new Error(`讀取圖片失敗（${res.status}）`);
                return createImageBitmap(await res.blob());
            })(),
        );
    }
    return bitmaps.get(media);
}

/** @returns {Promise<HTMLCanvasElement>} */
async function compose(b) {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const dw = Math.max(1, b.w / EMU);
    const dh = Math.max(1, b.h / EMU);
    let s;
    if (b.type === 'image') {
        const img = await bitmap(b.media);
        const sx = img.width * b.crop.l;
        const sy = img.height * b.crop.t;
        const sw = Math.max(1, img.width * (1 - b.crop.l - b.crop.r));
        const sh = Math.max(1, img.height * (1 - b.crop.t - b.crop.b));
        s = Math.max(1, sw / dw);
        s = Math.min(s, MAX_SIDE / dw, MAX_SIDE / dh);
        canvas.width = Math.round(dw * s);
        canvas.height = Math.round(dh * s);
        ctx.save();
        ctx.translate(canvas.width / 2, canvas.height / 2);
        ctx.rotate(((b.rot || 0) * Math.PI) / 180);
        ctx.scale(b.flipH ? -1 : 1, b.flipV ? -1 : 1);
        ctx.drawImage(img, sx, sy, sw, sh, -canvas.width / 2, -canvas.height / 2, canvas.width, canvas.height);
        ctx.restore();
    } else {
        s = Math.min(DRAWING_SCALE, MAX_SIDE / dw, MAX_SIDE / dh);
        canvas.width = Math.round(dw * s);
        canvas.height = Math.round(dh * s);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
    }
    for (const o of b.overlays) drawShape(ctx, o, s / EMU);
    return canvas;
}

function rgba(hex, a = 1) {
    const n = parseInt(hex.slice(1), 16);
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
}

const LINES = ['line', 'straightConnector1', 'bentConnector2', 'bentConnector3', 'bentConnector4', 'bentConnector5', 'curvedConnector2', 'curvedConnector3', 'curvedConnector4', 'curvedConnector5'];

function drawShape(ctx, o, k) {
    const w = o.w * k;
    const h = o.h * k;
    ctx.save();
    ctx.translate(o.x * k + w / 2, o.y * k + h / 2);
    ctx.rotate(((o.rot || 0) * Math.PI) / 180);
    ctx.save();
    ctx.scale(o.flipH ? -1 : 1, o.flipV ? -1 : 1);
    const isLine = LINES.includes(o.geom);
    const lw = o.stroke ? Math.max(1, o.stroke.width * k) : 0;
    const p = new Path2D();
    const x0 = -w / 2;
    const y0 = -h / 2;
    const pts = [];
    switch (o.geom) {
        case 'roundRect':
        case 'flowChartAlternateProcess':
            p.roundRect(x0, y0, w, h, Math.min(w, h) * 0.1667);
            break;
        case 'flowChartTerminator':
            p.roundRect(x0, y0, w, h, Math.min(h / 2, w / 2));
            break;
        case 'ellipse':
        case 'flowChartConnector':
            p.ellipse(0, 0, w / 2, h / 2, 0, 0, Math.PI * 2);
            break;
        case 'diamond':
        case 'flowChartDecision':
            p.moveTo(0, y0);
            p.lineTo(w / 2, 0);
            p.lineTo(0, h / 2);
            p.lineTo(x0, 0);
            p.closePath();
            break;
        case 'triangle':
            p.moveTo(0, y0);
            p.lineTo(w / 2, h / 2);
            p.lineTo(x0, h / 2);
            p.closePath();
            break;
        case 'rightArrow':
        case 'leftArrow':
        case 'upArrow':
        case 'downArrow': {
            const vertical = o.geom === 'upArrow' || o.geom === 'downArrow';
            const L = vertical ? h : w;
            const T = vertical ? w : h;
            const head = Math.min(L, T) * 0.5;
            const poly = [[-L / 2, -T / 4], [L / 2 - head, -T / 4], [L / 2 - head, -T / 2], [L / 2, 0], [L / 2 - head, T / 2], [L / 2 - head, T / 4], [-L / 2, T / 4]];
            const turn = { rightArrow: 0, downArrow: Math.PI / 2, leftArrow: Math.PI, upArrow: -Math.PI / 2 }[o.geom];
            poly.forEach(([px, py], i) => {
                const rx = px * Math.cos(turn) - py * Math.sin(turn);
                const ry = px * Math.sin(turn) + py * Math.cos(turn);
                i ? p.lineTo(rx, ry) : p.moveTo(rx, ry);
            });
            p.closePath();
            break;
        }
        default:
            if (isLine) {
                if (o.geom === 'bentConnector3') pts.push([x0, y0], [0, y0], [0, h / 2], [w / 2, h / 2]);
                else if (o.geom === 'bentConnector2') pts.push([x0, y0], [w / 2, y0], [w / 2, h / 2]);
                else pts.push([x0, y0], [w / 2, h / 2]);
                pts.forEach(([px, py], i) => (i ? p.lineTo(px, py) : p.moveTo(px, py)));
            } else {
                p.rect(x0, y0, w, h);
            }
    }
    if (!isLine && o.fill) {
        ctx.fillStyle = rgba(o.fill.color, o.fill.alpha);
        ctx.fill(p);
    }
    if (o.stroke) {
        ctx.strokeStyle = rgba(o.stroke.color, o.stroke.alpha);
        ctx.lineWidth = lw;
        ctx.lineJoin = 'round';
        const dash = { dash: [4, 3], lgDash: [8, 3], sysDash: [3, 1], sysDot: [1, 1], dot: [1, 2], dashDot: [4, 3, 1, 3] }[o.stroke.dash];
        ctx.setLineDash(dash ? dash.map((d) => d * lw) : []);
        ctx.stroke(p);
        ctx.setLineDash([]);
        if (o.geom === 'flowChartPredefinedProcess') {
            const inset = w / 8;
            ctx.beginPath();
            ctx.moveTo(x0 + inset, y0);
            ctx.lineTo(x0 + inset, h / 2);
            ctx.moveTo(w / 2 - inset, y0);
            ctx.lineTo(w / 2 - inset, h / 2);
            ctx.stroke();
        }
        if (isLine && pts.length > 1) {
            ctx.fillStyle = ctx.strokeStyle;
            arrowHead(ctx, o.stroke.head, pts[1], pts[0], lw);
            arrowHead(ctx, o.stroke.tail, pts[pts.length - 2], pts[pts.length - 1], lw);
        }
    }
    ctx.restore(); // text is never mirrored
    if (o.text) drawText(ctx, o.text, w, h, k);
    ctx.restore();
}

function arrowHead(ctx, type, from, to, lw) {
    if (!type || type === 'none') return;
    const size = Math.max(6, lw * 3);
    const a = Math.atan2(to[1] - from[1], to[0] - from[0]);
    ctx.save();
    ctx.translate(to[0], to[1]);
    ctx.rotate(a);
    ctx.beginPath();
    if (type === 'oval') {
        ctx.arc(0, 0, size / 2, 0, Math.PI * 2);
    } else if (type === 'diamond') {
        ctx.moveTo(size / 2, 0);
        ctx.lineTo(0, size / 2);
        ctx.lineTo(-size / 2, 0);
        ctx.lineTo(0, -size / 2);
    } else {
        ctx.moveTo(0, 0);
        ctx.lineTo(-size, size / 2);
        ctx.lineTo(-size, -size / 2);
    }
    ctx.closePath();
    ctx.fill();
    ctx.restore();
}

function drawText(ctx, t, w, h, k) {
    const px = Math.max(6, t.size * EMU_PER_PT * k);
    const [li, ti, ri, bi] = t.ins.map((v) => v * k);
    const maxW = Math.max(px, w - li - ri);
    ctx.font = `${t.bold ? 'bold ' : ''}${px}px ${FONT}`;
    ctx.fillStyle = t.color;
    ctx.textBaseline = 'top';
    const lines = [];
    for (const para of t.paras) {
        for (const raw of para.text.split('\n')) {
            let line = '';
            for (const ch of raw) {
                if (line && ctx.measureText(line + ch).width > maxW) {
                    lines.push([line, para.align]);
                    line = ch;
                } else {
                    line += ch;
                }
            }
            lines.push([line, para.align]);
        }
    }
    const lh = px * 1.2;
    const total = lines.length * lh;
    let y = t.anchor === 'ctr' ? -total / 2 : t.anchor === 'b' ? h / 2 - bi - total : -h / 2 + ti;
    for (const [line, align] of lines) {
        ctx.textAlign = align === 'ctr' ? 'center' : align === 'r' ? 'right' : 'left';
        const x = align === 'ctr' ? (li - ri) / 2 : align === 'r' ? w / 2 - ri : -w / 2 + li;
        ctx.fillText(line, x, y);
        y += lh;
    }
}
