<h1 class="mb-1 text-xl font-semibold">專案管理</h1>
<p class="mb-3 text-xs text-slate-500">只作用在目前選的專案。建立帳號、停用帳號、指派專案管理員請找系統管理員。</p>
<div class="mb-4 flex gap-2 border-b border-slate-200 text-sm">
    @php
        $tabs = array_filter([
            'members' => $canMembers ? ['成員', 'padmin.members'] : null,
            'trash' => $canTrash ? ['回收筒', 'padmin.trash'] : null,
            'events' => ['異動紀錄', 'padmin.events'],
        ]);
    @endphp
    @foreach ($tabs as $key => [$label, $route])
        <a href="{{ route($route) }}" class="-mb-px border-b-2 px-3 py-2 {{ $active === $key ? 'border-indigo-600 font-medium text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}">{{ $label }}</a>
    @endforeach
</div>
