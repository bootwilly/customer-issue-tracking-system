<?php

namespace App\Repositories;

use App\Models\SavedQuery;
use Illuminate\Support\Collection;

class SavedQueryRepository
{
    /** @return Collection<int, SavedQuery> */
    public function list(int $userId, int $projectId, string $scope): Collection
    {
        return SavedQuery::where(['user_id' => $userId, 'project_id' => $projectId, 'scope' => $scope])->orderBy('sort')->orderBy('id')->get();
    }

    public function find(int $id): ?SavedQuery
    {
        return SavedQuery::find($id);
    }

    public function count(int $userId, int $projectId, string $scope): int
    {
        return SavedQuery::where(['user_id' => $userId, 'project_id' => $projectId, 'scope' => $scope])->count();
    }

    public function maxSort(int $userId, int $projectId, string $scope): int
    {
        return (int) SavedQuery::where(['user_id' => $userId, 'project_id' => $projectId, 'scope' => $scope])->max('sort');
    }

    public function create(array $data): SavedQuery
    {
        return SavedQuery::create($data);
    }

    public function save(SavedQuery $q, array $data): SavedQuery
    {
        $q->fill($data)->save();

        return $q;
    }

    public function delete(SavedQuery $q): void
    {
        $q->delete();
    }
}
