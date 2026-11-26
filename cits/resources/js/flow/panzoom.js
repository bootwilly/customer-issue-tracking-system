/**
 * Minimal pan / zoom for a flow picture (no dependency).
 * viewport: clipping box; target: element that gets the CSS transform (an <img> or <svg>).
 * wheel: 'always' = wheel zooms (dedicated viewer), 'ctrl' = only Ctrl+wheel (inside a scrolling document).
 */
const MIN = 0.1;
const MAX = 10;

export function panzoom(viewport, target, { wheel = 'always', fit = true } = {}) {
    let s = 1;
    let x = 0;
    let y = 0;
    let drag = null;

    viewport.classList.add('cits-zoom');
    target.classList.add('cits-zoom-target');
    target.setAttribute('draggable', 'false');

    const apply = () => {
        target.style.transform = `translate(${x}px, ${y}px) scale(${s})`;
        label.textContent = `${Math.round(s * 100)}%`;
    };
    const size = () => {
        const w = target.naturalWidth || target.viewBox?.baseVal?.width || target.getBBox?.().width || target.offsetWidth;
        const h = target.naturalHeight || target.viewBox?.baseVal?.height || target.getBBox?.().height || target.offsetHeight;
        return { w: w || 1, h: h || 1 };
    };
    const zoomAt = (factor, cx, cy) => {
        const ns = Math.min(MAX, Math.max(MIN, s * factor));
        const f = ns / s;
        x = cx - (cx - x) * f;
        y = cy - (cy - y) * f;
        s = ns;
        apply();
    };
    const center = () => {
        const r = viewport.getBoundingClientRect();
        return [r.width / 2, r.height / 2];
    };
    const fitView = () => {
        const r = viewport.getBoundingClientRect();
        const { w, h } = size();
        s = Math.max(MIN, Math.min(1, (r.width - 24) / w, (r.height - 24) / h));
        x = (r.width - w * s) / 2;
        y = Math.max(12, (r.height - h * s) / 2);
        apply();
    };
    const actual = () => {
        const r = viewport.getBoundingClientRect();
        const { w } = size();
        s = 1;
        x = Math.max(12, (r.width - w) / 2);
        y = 12;
        apply();
    };

    viewport.addEventListener(
        'wheel',
        (e) => {
            if (wheel === 'ctrl' && !e.ctrlKey && !e.metaKey) return;
            e.preventDefault();
            const r = viewport.getBoundingClientRect();
            zoomAt(e.deltaY < 0 ? 1.15 : 1 / 1.15, e.clientX - r.left, e.clientY - r.top);
        },
        { passive: false },
    );
    viewport.addEventListener('pointerdown', (e) => {
        if (e.button !== 0 || e.target.closest('.cits-zoom-bar')) return;
        drag = { id: e.pointerId, sx: e.clientX - x, sy: e.clientY - y };
        viewport.setPointerCapture(e.pointerId);
        viewport.classList.add('is-dragging');
    });
    viewport.addEventListener('pointermove', (e) => {
        if (!drag || drag.id !== e.pointerId) return;
        x = e.clientX - drag.sx;
        y = e.clientY - drag.sy;
        apply();
    });
    const end = (e) => {
        if (!drag || drag.id !== e.pointerId) return;
        drag = null;
        viewport.classList.remove('is-dragging');
    };
    viewport.addEventListener('pointerup', end);
    viewport.addEventListener('pointercancel', end);
    viewport.addEventListener('dblclick', (e) => {
        if (e.target.closest('.cits-zoom-bar')) return;
        const r = viewport.getBoundingClientRect();
        zoomAt(1.5, e.clientX - r.left, e.clientY - r.top);
    });

    const bar = document.createElement('div');
    bar.className = 'cits-zoom-bar';
    const btn = (text, title, fn) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = text;
        b.title = title;
        b.addEventListener('click', fn);
        bar.appendChild(b);
        return b;
    };
    btn('－', '縮小', () => zoomAt(1 / 1.25, ...center()));
    const label = btn('100%', '原始大小', actual);
    btn('＋', '放大', () => zoomAt(1.25, ...center()));
    btn('符合', '符合視窗', fitView);
    viewport.appendChild(bar);

    const hint = document.createElement('div');
    hint.className = 'cits-zoom-hint';
    hint.textContent = wheel === 'ctrl' ? 'Ctrl + 滾輪縮放・拖曳移動・雙擊放大' : '滾輪縮放・拖曳移動・雙擊放大';
    viewport.appendChild(hint);

    // a hidden / not yet laid out viewport has no size: wait for the first real size, then fit once
    let started = false;
    const start = () => {
        const r = viewport.getBoundingClientRect();
        if (started || r.width < 10 || r.height < 10) return;
        started = true;
        fit ? fitView() : actual();
    };
    new ResizeObserver(start).observe(viewport);
    start();
    return { fit: fitView, actual };
}

export const ZOOM_CSS = `
.cits-zoom { position: relative; overflow: hidden; cursor: grab; touch-action: none; user-select: none; background: #fff; }
.cits-zoom.is-dragging { cursor: grabbing; }
.cits-zoom-target { position: absolute; left: 0; top: 0; transform-origin: 0 0; max-width: none !important; max-height: none !important; }
.cits-zoom-bar { position: absolute; right: .5rem; top: .5rem; display: flex; gap: 2px; z-index: 2; background: rgba(255,255,255,.9); border: 1px solid #cbd5e1; border-radius: .375rem; padding: 2px; cursor: default; }
.cits-zoom-bar button { min-width: 2rem; padding: .15rem .45rem; font-size: .75rem; border-radius: .25rem; color: #334155; }
.cits-zoom-bar button:hover { background: #e2e8f0; }
.cits-zoom-hint { position: absolute; left: .5rem; bottom: .4rem; font-size: .7rem; color: #94a3b8; pointer-events: none; }
`;

export function injectZoomCss() {
    if (document.getElementById('cits-zoom-css')) return;
    const st = document.createElement('style');
    st.id = 'cits-zoom-css';
    st.textContent = ZOOM_CSS;
    document.head.appendChild(st);
}
