{{-- Duplicate hint under a title input (same form). Vars: $url (JSON endpoint), $except (id to leave out, optional) --}}
<div data-similar-box data-url="{{ $url }}" data-except="{{ $except ?? '' }}" class="mt-1 hidden rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900"></div>
@once
    @push('scripts')
        <script>
            (() => {
                for (const box of document.querySelectorAll('[data-similar-box]')) {
                    const input = box.closest('form')?.querySelector('input[name=title]');
                    if (!input) continue;
                    let timer = null;
                    let last = '';
                    const render = (items) => {
                        box.replaceChildren();
                        if (!items.length) { box.classList.add('hidden'); return; }
                        const head = document.createElement('div');
                        head.className = 'mb-1 font-medium';
                        head.textContent = '可能是重複的問題（點開確認，若相同可改在原單留言補充）：';
                        box.append(head);
                        for (const it of items) {
                            const a = document.createElement('a');
                            a.href = it.url;
                            a.target = '_blank';
                            a.rel = 'noopener';
                            a.className = 'block truncate hover:underline';
                            a.textContent = `${it.no}　${it.title}（${it.status}・${it.created}）`;
                            box.append(a);
                        }
                        box.classList.remove('hidden');
                    };
                    const run = async () => {
                        const q = input.value.trim();
                        if (q === last) return;
                        last = q;
                        if ([...q].length < 2) { render([]); return; }
                        const u = new URL(box.dataset.url, location.href);
                        u.searchParams.set('title', q);
                        if (box.dataset.except) u.searchParams.set('except', box.dataset.except);
                        try {
                            const r = await fetch(u, { headers: { Accept: 'application/json' } });
                            if (r.ok) render((await r.json()).items || []);
                        } catch { /* hint only */ }
                    };
                    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 500); });
                    input.addEventListener('blur', run);
                }
            })();
        </script>
    @endpush
@endonce
