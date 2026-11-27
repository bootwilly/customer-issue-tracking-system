<?php

namespace App\Repositories;

use App\Models\MesPage;
use Illuminate\Support\Collection;

class MesPageRepository
{
    /** @return Collection<int, MesPage> */
    public function all(int $projectId): Collection
    {
        return MesPage::where('project_id', $projectId)->orderBy('sort')->orderBy('id')->get();
    }

    /** Active pages for issue/ticket dropdowns. */
    public function activePages(int $projectId): Collection
    {
        return MesPage::where(['project_id' => $projectId, 'node_type' => 'page', 'is_active' => true])
            ->orderBy('name')->get(['id', 'name', 'page_path']);
    }

    /** Whether the id is one of activePages($projectId): an active page of that project. */
    public function isActivePage(int $projectId, int $id): bool
    {
        return $this->activePages($projectId)->contains('id', $id);
    }

    public function find(int $id): ?MesPage
    {
        return MesPage::find($id);
    }

    public function findByPath(int $projectId, string $path): ?MesPage
    {
        return MesPage::where(['project_id' => $projectId, 'page_path' => $path])->first();
    }

    public function create(array $data): MesPage
    {
        return MesPage::create($data);
    }

    public function save(MesPage $p, array $data): MesPage
    {
        $p->fill($data)->save();

        return $p;
    }
}
