/**
 * Self-hosted draw.io in embed mode (JSON postMessage protocol).
 * load -> (autosave -> localStorage draft) -> save -> export xmlsvg -> POST to CITS -> redirect.
 */
const root = document.querySelector('[data-flow-editor]');

if (root) {
    const frame = root.querySelector('iframe');
    const form = root.querySelector('form');
    const titleInput = form.querySelector('[name=title]');
    const status = root.querySelector('[data-editor-status]');
    const origin = new URL(frame.dataset.src, location.href).origin;
    const draftKey = root.dataset.draftKey;
    let dirty = false;
    let saving = false;

    const setStatus = (text, tone = 'muted') => {
        status.textContent = text;
        status.dataset.tone = tone;
    };
    const post = (msg) => frame.contentWindow.postMessage(JSON.stringify(msg), origin);
    const svgFromDataUri = (data) => {
        const [head, body] = data.split(',', 2);
        if (!head.includes(';base64')) return decodeURIComponent(body);
        const bin = atob(body);
        return new TextDecoder().decode(Uint8Array.from(bin, (c) => c.charCodeAt(0)));
    };
    const readDraft = () => {
        try {
            return JSON.parse(localStorage.getItem(draftKey) || 'null');
        } catch {
            return null;
        }
    };
    const writeDraft = (xml) => {
        try {
            localStorage.setItem(draftKey, JSON.stringify({ xml, title: titleInput.value, at: Date.now() }));
        } catch {
            // quota full: editing still works, only the draft is lost
        }
    };

    async function initialXml() {
        const draft = readDraft();
        if (draft?.xml && confirm(`有 ${new Date(draft.at).toLocaleString()} 未儲存的草稿，要還原嗎？\n（取消＝載入目前版本，草稿會被捨棄）`)) {
            if (draft.title) titleInput.value = draft.title;
            dirty = true;
            return draft.xml;
        }
        localStorage.removeItem(draftKey);
        if (!root.dataset.sourceUrl) return '';
        const res = await fetch(root.dataset.sourceUrl, { credentials: 'same-origin' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.text();
    }

    async function submit(svg) {
        if (!titleInput.value.trim()) {
            setStatus('請先輸入標題。', 'error');
            titleInput.focus();
            post({ action: 'spinner', show: false });
            saving = false;
            return;
        }
        setStatus('儲存中…');
        const body = new FormData(form);
        body.set('source_md', svg);
        try {
            const res = await fetch(form.action, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json().catch(() => ({}));
            if (res.ok && json.ok) {
                dirty = false;
                localStorage.removeItem(draftKey);
                location.href = json.redirect;
                return;
            }
            const msg = json.error || json.message || Object.values(json.errors || {})[0]?.[0] || `儲存失敗（HTTP ${res.status}）`;
            setStatus(msg, 'error');
        } catch (e) {
            setStatus(`儲存失敗：${e.message}（草稿已保留）`, 'error');
        }
        post({ action: 'spinner', show: false });
        saving = false;
    }

    window.addEventListener('message', async (e) => {
        if (e.source !== frame.contentWindow || e.origin !== origin || typeof e.data !== 'string') return;
        let m;
        try {
            m = JSON.parse(e.data);
        } catch {
            return;
        }
        switch (m.event) {
            case 'configure':
                post({ action: 'configure', config: { defaultFonts: ['Microsoft JhengHei', 'PingFang TC', 'Noto Sans TC'] } });
                break;
            case 'init':
                try {
                    post({ action: 'load', xml: await initialXml(), autosave: 1, title: titleInput.value || '流程圖' });
                    setStatus(root.dataset.sourceUrl ? '已載入，修改後按右上「儲存並退出」。' : '新流程圖，畫好後按右上「儲存並退出」。');
                } catch (err) {
                    setStatus(`載入失敗：${err.message}`, 'error');
                }
                break;
            case 'autosave':
                dirty = true;
                writeDraft(m.xml);
                setStatus('有未儲存的修改（草稿已暫存在這台電腦）。', 'warn');
                break;
            case 'save':
                if (saving) break;
                saving = true;
                writeDraft(m.xml);
                post({ action: 'export', format: 'xmlsvg', spinKey: 'saving' });
                break;
            case 'export':
                if (saving) submit(svgFromDataUri(m.data));
                break;
            case 'exit':
                if (m.modified && !confirm('有未儲存的修改，確定離開？（草稿會保留在這台電腦）')) break;
                dirty = false;
                location.href = root.dataset.backUrl;
                break;
        }
    });

    titleInput.addEventListener('input', () => {
        dirty = true;
    });
    window.addEventListener('beforeunload', (e) => {
        if (dirty) e.preventDefault();
    });

    frame.src = frame.dataset.src;
}
