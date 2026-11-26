<div class="mb-4 flex gap-2 border-b border-slate-200 text-sm">
    @foreach (['users' => ['使用者', 'admin.users.index'], 'projects' => ['專案', 'admin.projects.index'], 'lookups' => ['選項管理', 'admin.lookups.index']] as $key => [$label, $route])
        <a href="{{ route($route) }}" class="-mb-px border-b-2 px-3 py-2 {{ $active === $key ? 'border-indigo-600 font-medium text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-700' }}">{{ $label }}</a>
    @endforeach
</div>
