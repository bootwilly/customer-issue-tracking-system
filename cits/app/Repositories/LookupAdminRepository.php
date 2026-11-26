<?php

namespace App\Repositories;

use App\Models\LookupItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Maintenance of admin-editable lookup categories (data access only). */
class LookupAdminRepository
{
    /**
     * Where each editable category is stored as a code (table, column). Schema knowledge belongs here;
     * a category without an entry is treated as unused by records.
     */
    private const USAGE = [
        'process_type' => ['issues', 'process_type'],
        'meeting_type' => ['meetings', 'type'],
        'issue_tag' => ['issue_tags', 'tag_code'],
    ];

    /** @return Collection<int, LookupItem> */
    public function items(string $category): Collection
    {
        return LookupItem::where('category', $category)->orderBy('sort')->orderBy('id')->get();
    }

    public function find(string $category, int $id): ?LookupItem
    {
        return LookupItem::where('category', $category)->find($id);
    }

    public function codeExists(string $category, string $code): bool
    {
        return LookupItem::where(['category' => $category, 'code' => $code])->exists();
    }

    public function nameExists(string $category, string $name, ?int $exceptId = null): bool
    {
        return LookupItem::where('category', $category)->where('name', $name)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
    }

    public function maxSort(string $category): int
    {
        return (int) LookupItem::where('category', $category)->max('sort');
    }

    public function create(array $data): LookupItem
    {
        return LookupItem::create($data);
    }

    public function save(LookupItem $item, array $data): void
    {
        $item->fill($data)->save();
    }

    public function delete(LookupItem $item): void
    {
        $item->delete();
    }

    /** @return array<string,int> code => number of records using it */
    public function usage(string $category): array
    {
        [$table, $column] = self::USAGE[$category] ?? [null, null];
        if (! $table) {
            return [];
        }

        return DB::table($table)->whereNotNull($column)->groupBy($column)
            ->selectRaw("$column AS code, COUNT(*) AS n")->pluck('n', 'code')->map(fn ($n) => (int) $n)->all();
    }
}
