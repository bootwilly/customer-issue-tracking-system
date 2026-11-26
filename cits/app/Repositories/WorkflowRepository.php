<?php

namespace App\Repositories;

use App\Models\FieldEditRule;
use App\Models\LookupItem;
use App\Models\SystemSetting;
use App\Models\WorkflowStatus;
use App\Models\WorkflowTransition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

class WorkflowRepository
{
    /** @var array<string, Collection> */
    private array $statusCache = [];

    /** @return Collection<string, WorkflowStatus> keyed by code */
    public function statuses(string $scope): Collection
    {
        return $this->statusCache[$scope] ??= WorkflowStatus::where('scope', $scope)->orderBy('sort')->get()->keyBy('code');
    }

    /** @return Collection<int, WorkflowTransition> */
    public function transitionsFrom(string $scope, string $from): Collection
    {
        return WorkflowTransition::where('scope', $scope)->where('from_code', $from)->get();
    }

    public function transition(string $scope, string $from, string $to): ?WorkflowTransition
    {
        return WorkflowTransition::where(['scope' => $scope, 'from_code' => $from, 'to_code' => $to])->first();
    }

    /** @return Collection<int, FieldEditRule> */
    public function fieldRules(string $field): Collection
    {
        return FieldEditRule::where('field', $field)->get();
    }

    /** @return Collection<int, LookupItem> */
    public function lookups(string $category): Collection
    {
        return LookupItem::where('category', $category)->where('is_active', true)->orderBy('sort')->get();
    }

    /** Every item of a category, disabled ones included (labels for stored values). */
    public function lookupsAll(string $category): Collection
    {
        return LookupItem::where('category', $category)->orderBy('sort')->get();
    }

    public function lookupName(string $category, string $code): ?string
    {
        return LookupItem::where(['category' => $category, 'code' => $code])->value('name');
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        $row = SystemSetting::find($key);
        if (! $row) {
            return $default;
        }

        return $row->is_encrypted && $row->value !== null ? Crypt::decryptString($row->value) : $row->value;
    }
}
