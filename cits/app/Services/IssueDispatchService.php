<?php

namespace App\Services;

use App\Repositories\EventRepository;
use Illuminate\Support\Collection;

class IssueDispatchService
{
    public const DEV_LABEL = 'PM 派工時間';

    public const TEST_COLUMNS = ['dispatched_at' => '測試派工時間', 'dispatch_source' => '派工來源'];

    public const NO_TEST_DISPATCH = ['dispatched_at' => '—', 'dispatch_source' => '—'];

    private const TEST_SOURCES = ['issue.dispatch' => 'PM', 'dev.manage' => '開發'];

    public function __construct(private EventRepository $events, private WorkflowService $wf) {}

    public function devTimes(int $pid, array $issueIds): Collection
    {
        return $this->events->firstDevDispatchTimes($pid, $issueIds, $this->wf->initial('issue_initial', 'dev'))
            ->map(fn ($time) => tw($time));
    }

    public function test(int $pid, array $ids, bool $atClose = false): Collection
    {
        $testCodes = $this->wf->codesForStage($this->wf->lookupValue('stage_role', 'test'));

        return $this->events->latestTestDispatches($pid, $ids, $testCodes, array_keys(self::TEST_SOURCES), $atClose)
            ->map(fn ($row) => ['dispatched_at' => tw($row['dispatched_at']), 'dispatch_source' => self::TEST_SOURCES[$row['permission']]]);
    }
}
