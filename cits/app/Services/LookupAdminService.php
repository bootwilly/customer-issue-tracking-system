<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\LookupItem;
use App\Repositories\LookupAdminRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * System admin maintenance of lookup categories listed in lookup "lookup_editable" (code = category, name = label).
 * Codes are generated and never change (records store the code); disabling hides an item from pickers only,
 * and deleting is allowed only while no record uses it.
 */
class LookupAdminService
{
    private const NAME_MAX = 50;

    public function __construct(private LookupAdminRepository $repo, private WorkflowService $wf) {}

    /** @return array<string,string> editable category => label */
    public function categories(): array
    {
        return $this->wf->lookupMap('lookup_editable');
    }

    private function assertCategory(string $category): void
    {
        if (! array_key_exists($category, $this->categories())) {
            throw new BusinessException('這個分類不能在這裡管理。');
        }
    }

    /** @return Collection<int, LookupItem> items with ->usage (record count) */
    public function items(string $category): Collection
    {
        $this->assertCategory($category);
        $usage = $this->repo->usage($category);

        return $this->repo->items($category)->each(fn (LookupItem $i) => $i->usage = $usage[$i->code] ?? 0);
    }

    private function cleanName(string $category, string $name, ?int $exceptId = null): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw new BusinessException('請輸入名稱。');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new BusinessException('名稱最多 '.self::NAME_MAX.' 個字。');
        }
        if ($this->repo->nameExists($category, $name, $exceptId)) {
            throw new BusinessException("已經有「{$name}」了。");
        }

        return $name;
    }

    private function item(string $category, int $id): LookupItem
    {
        $this->assertCategory($category);

        return $this->repo->find($category, $id) ?? throw new BusinessException('找不到這個選項。');
    }

    public function create(string $category, string $name): LookupItem
    {
        $this->assertCategory($category);
        $name = $this->cleanName($category, $name);
        do {
            $code = 'c'.base_convert((string) time(), 10, 36).strtolower(Str::random(4)); // <= 30 chars, never reused
        } while ($this->repo->codeExists($category, $code));

        return $this->repo->create(['category' => $category, 'code' => $code, 'name' => $name,
            'sort' => $this->repo->maxSort($category) + 10, 'is_active' => true]);
    }

    public function rename(string $category, int $id, string $name): void
    {
        $item = $this->item($category, $id);
        $this->repo->save($item, ['name' => $this->cleanName($category, $name, $item->id)]);
    }

    public function toggle(string $category, int $id): LookupItem
    {
        $item = $this->item($category, $id);
        if ($item->is_active && $this->items($category)->where('is_active', true)->count() <= 1) {
            throw new BusinessException('至少要保留一個可選的項目。');
        }
        $this->repo->save($item, ['is_active' => ! $item->is_active]);

        return $item;
    }

    /** Swap with the neighbour above (-1) or below (+1); sorts are renumbered 10, 20, 30… */
    public function move(string $category, int $id, int $dir): void
    {
        $items = $this->repo->items($this->item($category, $id)->category)->values();
        $i = $items->search(fn ($x) => $x->id === $id);
        $j = $i + ($dir < 0 ? -1 : 1);
        if ($j < 0 || $j >= $items->count()) {
            return;
        }
        $order = $items->all();
        [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        foreach ($order as $k => $x) {
            if ($x->sort !== ($k + 1) * 10) {
                $this->repo->save($x, ['sort' => ($k + 1) * 10]);
            }
        }
    }

    public function delete(string $category, int $id): void
    {
        $item = $this->item($category, $id);
        $used = $this->repo->usage($category)[$item->code] ?? 0;
        if ($used > 0) {
            throw new BusinessException("還有 {$used} 筆資料使用「{$item->name}」，不能刪除，請改為停用。");
        }
        $this->repo->delete($item);
    }
}
