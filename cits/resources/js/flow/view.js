import { injectZoomCss, panzoom } from './panzoom';

/** drawio flow page: the stored SVG is shown as <img> (scripts never run) inside a pan/zoom viewport. */
function boot() {
    const box = document.querySelector('[data-flow-zoom]');
    const img = box?.querySelector('img');
    if (!img) return;
    injectZoomCss();
    const start = () => panzoom(box, img, { wheel: 'always' });
    img.complete && img.naturalWidth ? start() : img.addEventListener('load', start, { once: true });
    img.addEventListener('error', () => {
        box.textContent = '流程圖載入失敗，請重新整理。';
    });
}

document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', boot) : boot();
