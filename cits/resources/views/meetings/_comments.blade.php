@php
    $ta = 'w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
    $me = auth()->id();
@endphp
<section id="comments" class="rounded-lg bg-white p-4 shadow-sm">
    <h2 class="mb-2 text-base font-semibold">留言</h2>
    <div class="space-y-3">
        @forelse ($comments as $c)
            <div class="rounded border border-slate-100 p-3">
                @include('meetings._comment', ['c' => $c])
                @if ($c->replies->isNotEmpty())
                    <div class="mt-2 space-y-2 border-l-2 border-slate-100 pl-4">
                        @foreach ($c->replies as $r)
                            @include('meetings._comment', ['c' => $r])
                        @endforeach
                    </div>
                @endif
                @if ($canComment && ! $c->deleted_at)
                    <details class="mt-2 pl-4">
                        <summary class="cursor-pointer text-xs text-indigo-700">回覆</summary>
                        <form method="POST" action="{{ route('meetings.comments.store', $m->id) }}" class="mt-2 space-y-2">
                            @csrf
                            <input type="hidden" name="parent_id" value="{{ $c->id }}">
                            <textarea name="body" rows="2" maxlength="2000" required class="{{ $ta }}"></textarea>
                            <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-700">送出回覆</button>
                            @if ($c->is_internal)<span class="text-xs text-slate-500">（內部討論串，回覆也只有內部看得到）</span>@endif
                        </form>
                    </details>
                @endif
            </div>
        @empty
            <p class="text-sm text-slate-400">還沒有留言。</p>
        @endforelse
    </div>

    @if ($canComment)
        <form method="POST" action="{{ route('meetings.comments.store', $m->id) }}" class="mt-3 space-y-2">
            @csrf
            <textarea name="body" rows="3" maxlength="2000" required placeholder="留言或提問…" class="{{ $ta }}">{{ old('parent_id') ? '' : old('body') }}</textarea>
            <div class="flex items-center gap-3">
                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">送出留言</button>
                @if (! $isClient && $m->customer_visible)
                    <label class="flex items-center gap-1 text-sm text-slate-600"><input type="checkbox" name="is_internal" value="1" class="rounded border-slate-300"> 僅內部（客戶看不到）</label>
                @endif
            </div>
        </form>
    @endif
</section>
