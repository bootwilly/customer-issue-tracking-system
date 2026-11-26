<?php

namespace App\Repositories;

use App\Models\SystemSetting;
use App\Models\WorkflowStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SettingRepository
{
    public function get(string $key, ?string $default = null): ?string
    {
        $row = SystemSetting::find($key);
        if (! $row || $row->value === null) {
            return $default;
        }

        return $row->is_encrypted ? Crypt::decryptString($row->value) : $row->value;
    }

    public function int(string $key, int $default): int
    {
        $v = $this->get($key);

        return $v === null || ! is_numeric($v) ? $default : (int) $v;
    }

    public function set(string $key, ?string $value, bool $encrypted = false): void
    {
        SystemSetting::updateOrCreate(['key' => $key], [
            'value' => $value !== null && $encrypted ? Crypt::encryptString($value) : $value,
            'is_encrypted' => $encrypted,
        ]);
    }

    /** Cached status metadata for badges: ['scope:code' => row array]. */
    public function workflowStatusMap(): array
    {
        return Cache::remember('cits.workflow_status_map', 300, function () {
            return WorkflowStatus::all()->mapWithKeys(
                fn ($s) => [$s->scope.':'.$s->code => $s->toArray()]
            )->all();
        });
    }

    public function workflowStatus(string $scope, string $code): ?array
    {
        return $this->workflowStatusMap()[$scope.':'.$code] ?? null;
    }
}
