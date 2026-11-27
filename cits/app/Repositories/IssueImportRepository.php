<?php

namespace App\Repositories;

use App\Models\IssueImportRow;
use Illuminate\Support\Collection;

class IssueImportRepository
{
    /** @return Collection<string, IssueImportRow> source_no => row (with issue id / number / status) */
    public function bySource(int $projectId, string $source): Collection
    {
        return IssueImportRow::query()->where('project_id', $projectId)->where('source', $source)
            ->with('issue:id,issue_no,title,status')->get()->keyBy('source_no');
    }

    /** Row locked for the apply transaction (null if the line was never imported). */
    public function lockOne(int $projectId, string $source, string $sourceNo): ?IssueImportRow
    {
        return IssueImportRow::query()->where(['project_id' => $projectId, 'source' => $source, 'source_no' => $sourceNo])
            ->lockForUpdate()->first();
    }

    public function create(array $data): IssueImportRow
    {
        return IssueImportRow::create($data);
    }

    public function saveHash(IssueImportRow $row, string $hash): void
    {
        $row->fill(['content_hash' => $hash])->save();
    }

    /** @return string[] import source codes used in the project, most recent first */
    public function sources(int $projectId, int $limit = 20): array
    {
        return IssueImportRow::query()->where('project_id', $projectId)
            ->selectRaw('source, MAX(id) AS last_id')->groupBy('source')->orderByDesc('last_id')
            ->limit($limit)->pluck('source')->all();
    }
}
