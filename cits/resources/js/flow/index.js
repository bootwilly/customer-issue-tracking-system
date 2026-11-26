import mermaid from 'mermaid';
import { injectZoomCss, panzoom } from './panzoom';

mermaid.initialize({
    startOnLoad: false,
    securityLevel: 'strict',
    theme: 'default',
    fontFamily: '"Instrument Sans", "Microsoft JhengHei", "PingFang TC", "Noto Sans TC", sans-serif',
    flowchart: { htmlLabels: false },
});

/** Render every .cits-flow block under root; a broken block shows its error and the others still render. */
async function renderAll(root) {
    const errors = [];
    const boxes = root.querySelectorAll('.cits-flow');
    for (const [i, box] of boxes.entries()) {
        const pre = box.querySelector('pre.mermaid');
        if (!pre) continue;
        const src = pre.textContent;
        try {
            await mermaid.parse(src);
            const { svg } = await mermaid.render(`cits-flow-svg-${i}`, src);
            box.innerHTML = svg;
            box.classList.add('is-rendered');
            zoomable(box);
        } catch (e) {
            const msg = String(e?.message ?? e);
            errors.push({ index: i, message: msg });
            box.classList.add('is-error');
            const p = document.createElement('p');
            p.className = 'cits-flow-error';
            p.textContent = `第 ${i + 1} 個流程圖語法錯誤：${msg}`;
            box.appendChild(p);
            document.getElementById(`cits-flow-svg-${i}`)?.remove();
            document.getElementById(`dcits-flow-svg-${i}`)?.remove();
        }
    }
    return { total: boxes.length, errors };
}

/** Fixed-size viewport per diagram; Ctrl+wheel so the page itself still scrolls. */
function zoomable(box) {
    const el = box.querySelector('svg');
    const vb = el?.viewBox?.baseVal;
    if (!el || !vb?.width) return;
    injectZoomCss();
    el.setAttribute('width', vb.width);
    el.setAttribute('height', vb.height);
    el.style.maxWidth = 'none';
    box.style.height = `${Math.min(vb.height + 48, window.innerHeight * 0.7)}px`;
    panzoom(box, el, { wheel: 'ctrl' });
}

async function boot() {
    const root = document.querySelector('[data-flow-doc]');
    if (!root) return;
    const { total, errors } = await renderAll(root);

    const status = document.querySelector('[data-flow-status]');
    const submit = document.querySelector('[data-flow-submit]');
    if (status) {
        status.textContent = errors.length
            ? `語法檢查：${errors.length} / ${total} 個流程圖有錯誤，請修正後重新上傳。`
            : `語法檢查通過（${total} 個流程圖）。`;
        status.classList.add(errors.length ? 'text-red-600' : 'text-emerald-700');
    }
    if (submit) submit.disabled = errors.length > 0;
}

document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', boot) : boot();
