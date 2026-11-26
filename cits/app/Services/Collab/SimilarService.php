<?php

namespace App\Services\Collab;

use App\Models\User;
use App\Repositories\SimilarRepository;
use App\Repositories\SettingRepository;
use App\Services\WorkflowService;

/**
 * Duplicate hint while creating a ticket / issue. Scoring = share of the title's tokens (CJK 2-grams + latin words)
 * found in a candidate title. Client side only ever searches client tickets of the project, never internal issues.
 */
class SimilarService
{
    private const MAX_TOKENS = 10;

    private const CANDIDATES = 60;

    private const RESULTS = 5;

    public function __construct(
        private SimilarRepository $repo,
        private SettingRepository $settings,
        private WorkflowService $wf,
    ) {}

    /** @return string[] */
    public function tokens(string $text): array
    {
        $text = mb_strtolower(trim(mb_substr($text, 0, 200)));
        $tokens = [];
        // latin / digit words (2+ chars)
        preg_match_all('/[a-z0-9][a-z0-9._-]+/u', $text, $m);
        foreach ($m[0] as $w) {
            $tokens[] = $w;
        }
        // CJK runs -> 2-grams (a single CJK char counts on its own)
        preg_match_all('/[\x{3400}-\x{9fff}\x{f900}-\x{faff}\x{20000}-\x{2ffff}]+/u', $text, $c);
        foreach ($c[0] as $run) {
            $chars = mb_str_split($run);
            if (count($chars) === 1) {
                $tokens[] = $run;
            }
            for ($i = 0; $i + 1 < count($chars); $i++) {
                $tokens[] = $chars[$i].$chars[$i + 1];
            }
        }
        $tokens = array_values(array_unique($tokens));
        usort($tokens, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($tokens, 0, self::MAX_TOKENS);
    }

    /** Client tickets of the project (client and internal callers). @return array<int, array> */
    public function tickets(User $u, int $projectId, string $title, ?int $exceptId = null): array
    {
        if (! $this->wf->can($u, 'ticket.view', $projectId) && ! ($u->seesInternal() && $this->wf->can($u, 'issue.view', $projectId))) {
            return [];
        }
        $tokens = $this->tokens($title);
        if (! $tokens) {
            return [];
        }
        $rows = $this->repo->tickets($projectId, $tokens, $this->wf->lookupCodes('similar_exclude_ticket'), self::CANDIDATES)
            ->reject(fn ($t) => $t->id === $exceptId)
            ->map(fn ($t) => [
                'type' => 'ticket', 'id' => $t->id, 'no' => $t->ticket_no, 'title' => $t->title,
                'status' => $this->wf->label('ticket', $t->status), 'created' => tw($t->created_at, 'Y-m-d'),
                'url' => $u->actsAsClient() ? route('tickets.show', $t->id) : route('pm.tickets.show', $t->id),
                'score' => $this->score($tokens, $t->title),
            ]);

        return $this->top($rows->all());
    }

    /** Internal side: issues (excluded statuses left out) + client tickets. @return array<int, array> */
    public function internal(User $u, int $projectId, string $title, ?int $exceptIssueId = null): array
    {
        if (! $u->seesInternal() || ! $this->wf->can($u, 'issue.view', $projectId)) {
            return [];
        }
        $tokens = $this->tokens($title);
        if (! $tokens) {
            return [];
        }
        $excluded = $this->wf->statusMap('issue')->filter(fn ($s) => $s->is_excluded)->keys()->all();
        $issues = $this->repo->issues($projectId, $tokens, $excluded, self::CANDIDATES)
            ->reject(fn ($i) => $i->id === $exceptIssueId)
            ->map(fn ($i) => [
                'type' => 'issue', 'id' => $i->id, 'no' => $i->issue_no, 'title' => $i->title,
                'status' => $this->wf->label('issue', $i->status), 'created' => tw($i->created_at, 'Y-m-d'),
                'url' => route('issues.show', $i->id), 'score' => $this->score($tokens, $i->title),
            ]);

        return $this->top(array_merge($issues->all(), $this->tickets($u, $projectId, $title)));
    }

    private function score(array $tokens, string $title): float
    {
        $t = mb_strtolower($title);
        $hit = count(array_filter($tokens, fn ($k) => str_contains($t, $k)));

        return round($hit / max(1, count($tokens)), 2);
    }

    private function top(array $rows): array
    {
        $min = (float) ($this->settings->get('similar.min_score', '0.34') ?? 0.34);
        $rows = array_values(array_filter($rows, fn ($r) => $r['score'] >= $min));
        usort($rows, fn ($a, $b) => [$b['score'], $b['id']] <=> [$a['score'], $a['id']]);

        return array_slice($rows, 0, self::RESULTS);
    }
}
