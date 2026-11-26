<?php

namespace App\Repositories;

use App\Models\Attachment;
use Illuminate\Support\Collection;

/** Read-only queries used by the issue export. */
class IssueExportRepository
{
    /**
     * Bound attachments owned by the issues or by their comments.
     *
     * @param  array<int,int[]>  $commentIdsByIssue  issue id => comment ids
     * @return array<int,Collection<int,Attachment>> issue id => attachments (id order)
     */
    public function attachmentsByIssue(array $commentIdsByIssue): array
    {
        if ($commentIdsByIssue === []) {
            return [];
        }
        $issueIds = array_keys($commentIdsByIssue);
        $commentIds = array_merge([], ...array_values($commentIdsByIssue));
        $rows = Attachment::query()->where('state', 'bound')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('owner_type', 'issue')->whereIn('owner_id', $issueIds))
                ->orWhere(fn ($w) => $w->where('owner_type', 'issue_comment')->whereIn('owner_id', $commentIds ?: [0])))
            ->orderBy('id')->get(['id', 'owner_type', 'owner_id', 'original_name']);

        $commentToIssue = [];
        foreach ($commentIdsByIssue as $iid => $cids) {
            foreach ($cids as $cid) {
                $commentToIssue[$cid] = $iid;
            }
        }
        $out = [];
        foreach ($rows as $a) {
            $iid = $a->owner_type === 'issue' ? (int) $a->owner_id : ($commentToIssue[(int) $a->owner_id] ?? null);
            if ($iid !== null) {
                $out[$iid][] = $a;
            }
        }

        return array_map(fn ($l) => collect($l), $out);
    }
}
