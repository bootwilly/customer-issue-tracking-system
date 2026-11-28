<?php

namespace App\Services;

use App\Repositories\EventRepository;
use Illuminate\Support\Collection;

class IssueDispatchService
{
    public const DEV_LABEL = 'PM 派工時間';

    public const PM_COLUMNS = ['created_at' => '開單時間', 'dev_dispatched_at' => '首次派開發', 'test_dispatched_at' => '最近派測試', 'dispatch_source' => '派測來源'];

    public const TEST_COLUMNS = ['dispatched_at' => '測試派工時間', 'dispatch_source' => '派工來源'];

    public const NO_TEST_DISPATCH = ['dispatched_at' => '—', 'dispatch_source' => '—'];

    private const TEST_SOURCES = ['issue.dispatch' => 'PM', 'dev.manage' => '開發'];

    public function __construct(private EventRepository $events, private WorkflowService $wf) {}

    public function devTimes(int $pid, array $ids, bool $atClose = false): Collection
    {
        return $this->events->firstDevDispatchTimes($pid, $ids, $this->wf->initial('issue_initial', 'dev'), $atClose)
            ->map(fn ($time) => tw($time));
    }

    public function pmTimeline(int $pid, Collection $rows, bool $atClose = false): Collection
    {
        $key = $atClose ? 'request_event_id' : 'id';
        $ids = $rows->pluck($key)->all();
        $dev = $this->devTimes($pid, $ids, $atClose);
        $test = $this->test($pid, $ids, $atClose);

        return $rows->mapWithKeys(fn ($row) => [$row->$key => [
            'created_at' => tw($row->created_at),
            'dev_dispatched_at' => $dev[$row->$key] ?? '—',
            'test_dispatched_at' => $test[$row->$key]['dispatched_at'] ?? '—',
            'dispatch_source' => $test[$row->$key]['dispatch_source'] ?? '—',
        ]]);
    }

    public function test(int $pid, array $ids, bool $atClose = false): Collection
    {
        $testCodes = $this->wf->codesForStage($this->wf->lookupValue('stage_role', 'test'));

        return $this->events->latestTestDispatches($pid, $ids, $testCodes, array_keys(self::TEST_SOURCES), $atClose)
            ->map(fn ($row) => ['dispatched_at' => tw($row['dispatched_at']), 'dispatch_source' => self::TEST_SOURCES[$row['permission']]]);
    }
}
