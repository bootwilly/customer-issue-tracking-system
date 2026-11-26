<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Repositories\RevisionRepository;

class RevisionDiffService
{
    public function __construct(private RevisionRepository $repo, private WorkflowService $wf) {}

    /** Convert sanitized HTML to plain-text lines (block tags and br become newlines). */
    public function toLines(?string $html): array
    {
        $t = (string) $html;
        $t = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $t);
        $t = preg_replace('~</\s*(p|div|li|h[1-6]|blockquote|pre|tr|ul|ol)\s*>~i', "\n", $t);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $t);
        $lines = array_map('rtrim', explode("\n", $t));
        while ($lines && $lines[0] === '') {
            array_shift($lines);
        }
        while ($lines && end($lines) === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }

    /**
     * Line-level LCS diff.
     *
     * @param  string[]  $a  old lines
     * @param  string[]  $b  new lines
     * @return array<int, array{type:string,text:string}> type: same|add|del
     */
    public function diffLines(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['type' => 'same', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['type' => 'del', 'text' => $a[$i++]];
            } else {
                $out[] = ['type' => 'add', 'text' => $b[$j++]];
            }
        }
        while ($i < $n) {
            $out[] = ['type' => 'del', 'text' => $a[$i++]];
        }
        while ($j < $m) {
            $out[] = ['type' => 'add', 'text' => $b[$j++]];
        }

        return $out;
    }

    public function diffHtml(?string $oldHtml, ?string $newHtml): array
    {
        return $this->diffLines($this->toLines($oldHtml), $this->toLines($newHtml));
    }

    public function canView(User $u, int $projectId): bool
    {
        return $this->wf->can($u, $u->isClient() ? 'ticket.view' : 'issue.view', $projectId);
    }

    /** @throws BusinessException when the ticket/revision is missing or not accessible */
    public function compare(User $u, int $projectId, int $ticketId, int $revNo): array
    {
        if (! $this->canView($u, $projectId)) {
            throw new BusinessException('沒有權限檢視。');
        }
        $ticket = $this->repo->ticketInProject($ticketId, $projectId);
        $new = $ticket ? $this->repo->revision($ticket->id, $revNo) : null;
        if (! $ticket || ! $new) {
            throw new BusinessException('找不到此版本。');
        }
        $old = $revNo > 1 ? $this->repo->revision($ticket->id, $revNo - 1) : null;

        return [
            'ticket' => $ticket,
            'new' => $new,
            'old' => $old,
            'title' => ['old' => $old?->title, 'new' => $new->title, 'changed' => $old?->title !== $new->title],
            'meta' => [
                '類型' => $this->wf->lookupLabel('ticket_type', $ticket->ticket_type),
                '異常分類' => $ticket->anomaly_category ? $this->wf->lookupLabel('anomaly_category', $ticket->anomaly_category) : '—',
                'MES 頁面' => $ticket->mesPage?->name ?? '—',
            ],
            'lines' => $this->diffHtml($old?->content_snapshot, $new->content_snapshot),
            'isClient' => ! $u->seesInternal(),
        ];
    }
}
