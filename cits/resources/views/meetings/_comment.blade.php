<div id="c{{ $c->id }}" class="text-sm">
    <div class="mb-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
        <b class="text-slate-700">{{ $c->author?->name }}</b>
        @if ($c->author?->isClient())<span class="rounded bg-sky-100 px-1 text-sky-800">客戶</span>@endif
        @if ($c->is_internal)<span class="rounded bg-slate-200 px-1 text-slate-600">僅內部</span>@endif
        <span>{{ tw($c->created_at) }}</span>
        @if ($c->edited_at && ! $c->deleted_at)<span>（已編輯）</span>@endif
    </div>
    @if ($c->deleted_at)
        <p class="text-slate-400">此留言已刪除。</p>
        @if ($c->can_see_deleted)
            <p class="mt-1 whitespace-pre-line rounded bg-slate-50 p-2 text-xs text-slate-400">原內容（只有專案管理員看得到）：{{ $c->body }}</p>
        @endif
    @else
        <p class="whitespace-pre-line break-words">{{ $c->body }}</p>
        @if ($c->user_id === $me)
            <div class="mt-1 flex gap-3 text-xs">
                <details>
                    <summary class="cursor-pointer text-slate-500 hover:text-indigo-700">編輯</summary>
                    <form method="POST" action="{{ route('meetings.comments.update', [$m->id, $c->id]) }}" class="mt-2 space-y-2">
                        @csrf @method('PUT')
                        <textarea name="body" rows="2" maxlength="2000" required class="{{ $ta }}">{{ $c->body }}</textarea>
                        <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-700">儲存</button>
                    </form>
                </details>
                <form method="POST" action="{{ route('meetings.comments.destroy', [$m->id, $c->id]) }}" onsubmit="return confirm('刪除這則留言？')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-slate-500 hover:text-red-600">刪除</button>
                </form>
            </div>
        @endif
    @endif
</div>
