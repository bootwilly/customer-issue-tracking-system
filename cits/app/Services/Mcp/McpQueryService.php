<?php

namespace App\Services\Mcp;

use App\Exceptions\BusinessException;
use App\Models\Issue;
use App\Models\User;
use App\Repositories\McpQueryRepository;
use App\Services\WorkflowService;
use Carbon\CarbonInterface;

/** Read-only query layer behind the MCP tools; every result is scoped to the token user's projects. */
class McpQueryService
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    private const MAX_CONTENT = 5000;

    private const EVENT_LIMIT = 20;

    public function __construct(private McpQueryRepository $repo, private WorkflowService $wf) {}

    public function listProjects(User $u): array
    {
        return $this->repo->accessibleProjects($u)->map(fn ($p) => ['code' => $p->code, 'name' => $p->name])->all();
    }

    public function listIssues(User $u, array $a): array
    {
        $ids = $this->projectIds($u, $a['project'] ?? null, 'issue.view');
        $f = [
            'status' => $this->clean($a['status'] ?? null), 'stage' => $this->clean($a['stage'] ?? null),
            'assignee' => $this->clean($a['assignee'] ?? null), 'keyword' => $this->clean($a['keyword'] ?? null),
        ];
        if ($a['open_only'] ?? true) {
            $f['exclude_statuses'] = $this->wf->statusMap('issue')->filter(fn ($s) => $s->is_terminal)->keys()->all();
        }

        return $this->repo->issues($ids, $f, $this->limit($a['limit'] ?? null))
            ->map(fn ($i) => $this->issueRow($i))->all();
    }

    public function getIssue(User $u, string $issueNo): array
    {
        $i = $this->repo->issueByNo(trim($issueNo));
        if (! $i || ! $this->canReach($u, $i->project_id, 'issue.view')) {
            throw new BusinessException("找不到問題單：$issueNo");
        }
        $events = $this->repo->recentEvents('issue', $i->id, self::EVENT_LIMIT)->map(fn ($e) => [
            'time' => $this->iso($e->created_at),
            'actor' => $e->actor?->name,
            'label' => $this->eventLabel($e->event_type, $e->to_status),
        ])->all();

        return $this->issueRow($i) + [
            'content' => $this->plain((string) $i->content_html),
            'tickets' => $i->tickets->map(fn ($t) => [
                'ticket_no' => $t->ticket_no, 'title' => $t->title, 'status' => $this->wf->label('ticket', $t->status),
            ])->all(),
            'events' => $events,
        ];
    }

    public function listTickets(User $u, array $a): array
    {
        $ids = $this->projectIds($u, $a['project'] ?? null, 'ticket.view');
        $f = ['status' => $this->clean($a['status'] ?? null), 'keyword' => $this->clean($a['keyword'] ?? null)];

        return $this->repo->tickets($ids, $f, $this->limit($a['limit'] ?? null))->map(fn ($t) => [
            'project' => $t->project?->code,
            'ticket_no' => $t->ticket_no,
            'title' => $t->title,
            'status' => $t->status,
            'status_label' => $this->wf->label('ticket', $t->status),
            'updated_at' => $this->iso($t->updated_at),
        ])->all();
    }

    /**
     * Resolve accessible project ids holding $perm. A given code must be reachable;
     * without a code all reachable projects (the single one, when only one) are used.
     *
     * @return int[]
     */
    private function projectIds(User $u, mixed $code, string $perm): array
    {
        $projects = $this->repo->accessibleProjects($u);
        $code = $this->clean($code);
        if ($code !== null) {
            $projects = $projects->where('code', $code);
            if ($projects->isEmpty()) {
                throw new BusinessException("找不到專案：$code");
            }
        }
        $ids = $projects->filter(fn ($p) => $this->wf->can($u, $perm, $p->id))->pluck('id')->values()->all();
        if (! $ids) {
            throw new BusinessException('沒有權限檢視此資料。');
        }

        return $ids;
    }

    private function canReach(User $u, int $projectId, string $perm): bool
    {
        return $this->repo->accessibleProjects($u)->contains('id', $projectId) && $this->wf->can($u, $perm, $projectId);
    }

    private function issueRow(Issue $i): array
    {
        return [
            'project' => $i->project?->code,
            'issue_no' => $i->issue_no,
            'title' => $i->title,
            'status' => $i->status,
            'status_label' => $this->wf->label('issue', $i->status),
            'stage' => $i->stage,
            'severity' => $i->severity,
            'origin' => $i->origin,
            'assignee_dev' => $i->dev?->name,
            'assignee_test' => $i->tester?->name,
            'current_due' => $i->current_due?->toDateString(),
            'updated_at' => $this->iso($i->updated_at),
            'url' => url('/issues/'.$i->id),
        ];
    }

    private function eventLabel(string $type, ?string $to): string
    {
        return $to ? $type.'：'.$this->wf->label('issue', $to) : $type;
    }

    private function plain(string $html): string
    {
        $html = preg_replace('#<(br|/p|/div|/li|/h\d)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));

        return mb_substr($text, 0, self::MAX_CONTENT);
    }

    private function iso(?CarbonInterface $d): ?string
    {
        return $d?->copy()->utc()->toIso8601String();
    }

    private function limit(mixed $v): int
    {
        return max(1, min(self::MAX_LIMIT, is_numeric($v) ? (int) $v : self::DEFAULT_LIMIT));
    }

    private function clean(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return $v === '' ? null : $v;
    }
}
