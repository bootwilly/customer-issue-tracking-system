<?php

namespace App\Services\Pm;

use App\Models\Issue;
use App\Repositories\IssueRepository;
use App\Services\WorkflowService;
use Illuminate\Support\Collection;

class IssueProvenanceService
{
    public function __construct(private IssueRepository $issues, private WorkflowService $wf) {}

    /** Shared display values for the PM board and its spreadsheet. */
    public function details(int $pid, Collection $issues, bool $canViewMeetings): Collection
    {
        $labels = $this->wf->lookupMapAll('issue_provenance');
        $origins = $this->wf->lookupMapAll('issue_origin');
        $items = $canViewMeetings ? $this->issues->meetingItemsForSources($pid, $issues->pluck('id')->all(),
            $issues->pluck('source_payload.item_id')->filter()->all()) : collect();
        $byItem = $items->keyBy('id');
        $discussed = $items->whereNotNull('issue_id')->keyBy('issue_id');

        return $issues->mapWithKeys(function (Issue $issue) use ($labels, $origins, $byItem, $discussed, $canViewMeetings) {
            $payload = $issue->source_payload ?? [];
            $url = null;
            $more = 0;
            $detail = ($origins[$issue->origin] ?? $issue->origin).($issue->origin === 'pm' ? ' 開單' : ' 發現');
            $short = null;
            if ($issue->import_source !== null || isset($payload['source'])) {
                $detail = 'Excel '.($issue->import_source ?? $payload['source']).' #'.($issue->import_no ?? $payload['no']);
            } elseif ($issue->provenance === 'meeting') {
                $detail = '會議事項';
                if ($canViewMeetings) {
                    $detail = ($payload['meeting'] ?? '會議').' · 事項 '.($payload['item'] ?? '—');
                    $item = $byItem->get($payload['item_id'] ?? null);
                    if ($item && $item->meeting_id == ($payload['meeting_id'] ?? null)) {
                        $url = route('meetings.show', $item->meeting_id).'#item-'.$item->id;
                    }
                }
            } elseif ($issue->provenance === 'ticket') {
                // Older created events have no ticket snapshot. Identify current links explicitly.
                $tickets = collect($payload['tickets'] ?? $issue->tickets->sortBy('id')->pluck('ticket_no', 'id'));
                $prefix = isset($payload['tickets']) ? '' : '關聯 ';
                $detail = $tickets->isEmpty() ? '無客戶單號紀錄' : $prefix.$tickets->implode('、');
                $short = $tickets->isEmpty() ? $detail : $prefix.$tickets->first();
                $more = max(0, $tickets->count() - 1);
                if ($issue->tickets->contains('id', $tickets->keys()->first())) {
                    $url = route('pm.tickets.show', $tickets->keys()->first());
                }
            }

            return [$issue->id => [
                'label' => $labels[$issue->provenance] ?? $issue->provenance, 'detail' => $detail,
                'short' => $short ?? $detail, 'url' => $url, 'more' => $more,
                'in_meeting' => $issue->provenance !== 'meeting' && $discussed->has($issue->id),
            ]];
        });
    }
}
