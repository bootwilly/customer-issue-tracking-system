<?php

namespace App\Services\Export;

use App\Models\ClientTicket;
use App\Models\User;
use App\Repositories\PmQueryRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\TicketExportRepository;
use App\Repositories\TicketRepository;
use App\Services\Collab\NeedinfoService;
use App\Services\WorkflowService;
use Closure;
use Illuminate\Support\Collection;

/** Builds the client-ticket workbook (client page) and the PM ticket sheet (PM page). */
class TicketExportService
{
    private const LABEL = '客戶單';

    public function __construct(
        private TicketRepository $tickets,
        private PmQueryRepository $pm,
        private TicketExportRepository $attachments,
        private ProjectRepository $projects,
        private ExportColumnService $columns,
        private XlsxExportService $xlsx,
        private WorkflowService $wf,
        private NeedinfoService $needinfo,
    ) {}

    /** @param array{tab?:string,type?:string,q?:string,sort?:string,dir?:string,mine?:int|null} $filters */
    public function clientBook(User $u, int $projectId, array $filters): ExportBook
    {
        $tabs = $this->wf->lookupMap('client_tab');
        $tab = array_key_exists((string) ($filters['tab'] ?? ''), $tabs) ? $filters['tab'] : (array_key_first($tabs) ?? 'open');
        $rows = $this->tickets->listFiltered($projectId, $this->wf->codesForTab($tab), $filters, $this->xlsx->maxRows() + 1);
        $this->xlsx->assertWithinLimit($rows->count());

        $sheet = $this->sheet($rows, 'client_tickets', $this->resolvers(), fn (ClientTicket $t) => route('tickets.show', $t->id), false);

        $sortLabels = ['last' => '最後更新', 'submitted' => '開立時間', 'no' => '單號'];
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? '（升冪）' : '（降冪）';
        $info = [
            '分頁' => $tabs[$tab] ?? $tab,
            '類型' => ($filters['type'] ?? '') !== '' ? $this->wf->lookupLabel('ticket_type', $filters['type']) : '全部',
            '關鍵字' => ($filters['q'] ?? '') !== '' ? (string) $filters['q'] : '（無）',
            '排序' => ($sortLabels[$filters['sort'] ?? 'last'] ?? $sortLabels['last']).$dir,
        ];
        if (! empty($filters['mine'])) {
            $info['範圍'] = '只看我開立的';
        }

        return new ExportBook('client_tickets', $this->filename($projectId), [$sheet], $info);
    }

    /** @param array{oa?:string|null,only_action?:bool,tq?:string|null} $filters */
    public function pmTicketsSheet(User $u, int $projectId, array $filters): ExportSheet
    {
        $rows = $this->pm->pmTicketsForExport(
            $projectId, $this->wf->ticketScopeCodes($filters['tt'] ?? null),
            $this->onlyAction($filters) && $this->wf->isDefaultTicketScope($filters['tt'] ?? null),
            ($filters['tq'] ?? '') !== '' ? (string) $filters['tq'] : null, $this->xlsx->maxRows() + 1,
            $this->needinfo->filterIds($projectId, $filters['ni'] ?? null)
        );
        $this->xlsx->assertWithinLimit($rows->count());

        $resolvers = $this->resolvers() + [
            'accepted_by' => fn (ClientTicket $t) => $t->acceptor?->name,
            'accepted_at' => fn (ClientTicket $t) => $t->accepted_at,
            'dispatched' => fn (ClientTicket $t) => $t->dispatched_at !== null,
            'needs_pm_action' => fn (ClientTicket $t) => (bool) $t->needs_pm_action,
            'unacked_revisions' => fn (ClientTicket $t) => (int) $t->unacked_revisions_count,
            'linked_issues' => fn (ClientTicket $t) => $t->issues->pluck('issue_no')->implode(', '),
        ];

        return $this->sheet($rows, 'pm_tickets', $resolvers, fn (ClientTicket $t) => route('pm.tickets.show', $t->id), true);
    }

    /**
     * Human-readable filter description of the PM ticket sheet (for ExportBook::info).
     *
     * @return array<string,string>
     */
    public function pmInfo(array $filters): array
    {
        return [
            '範圍' => $this->onlyAction($filters) ? '只看需處理' : '全部未結案',
            '關鍵字' => ($filters['tq'] ?? '') !== '' ? (string) $filters['tq'] : '（無）',
        ];
    }

    private function onlyAction(array $filters): bool
    {
        return array_key_exists('only_action', $filters) ? (bool) $filters['only_action'] : (string) ($filters['oa'] ?? '1') === '1';
    }

    private function filename(int $projectId): string
    {
        return $this->xlsx->filename($this->projects->find($projectId)?->code ?? 'project', self::LABEL);
    }

    /** @return array<string,Closure> */
    private function resolvers(): array
    {
        return [
            'ticket_no' => fn (ClientTicket $t) => $t->ticket_no,
            'title' => fn (ClientTicket $t) => $t->title,
            'ticket_type' => fn (ClientTicket $t) => $this->wf->lookupLabel('ticket_type', $t->ticket_type),
            'anomaly_category' => fn (ClientTicket $t) => $t->anomaly_category ? $this->wf->lookupLabel('anomaly_category', $t->anomaly_category) : null,
            'mes_page' => fn (ClientTicket $t) => $t->mesPage?->name,
            'status' => fn (ClientTicket $t) => $this->wf->label('ticket', $t->status),
            'creator' => fn (ClientTicket $t) => $t->creator?->name,
            'created_at' => fn (ClientTicket $t) => $t->created_at,
            'updated_at' => fn (ClientTicket $t) => $t->updated_at,
            'closed_at' => fn (ClientTicket $t) => $t->closed_at,
            'current_rev' => fn (ClientTicket $t) => (int) $t->current_rev,
        ];
    }

    /**
     * @param  Collection<int, ClientTicket>  $rows
     * @param  array<string,Closure>  $resolvers
     */
    private function sheet(Collection $rows, string $listKey, array $resolvers, Closure $url, bool $internal): ExportSheet
    {
        $cols = $this->columns->columns($listKey, array_keys($resolvers));
        $data = $rows->map(function (ClientTicket $t) use ($cols, $resolvers) {
            $r = [];
            foreach (array_keys($cols) as $k) {
                $r[$k] = $resolvers[$k]($t);
            }

            return $r;
        })->values()->all();

        $commentIds = $rows->flatMap(fn (ClientTicket $t) => $t->comments->pluck('id'))->all();
        $byTicket = [];
        $byComment = [];
        foreach ($this->attachments->attachmentsOf($rows->pluck('id')->all(), $commentIds) as $a) {
            $link = new ExportLink($a->original_name, route('attachments.show', $a->id));
            if ($a->owner_type === 'ticket') {
                $byTicket[$a->owner_id][] = $link;
            } else {
                $byComment[$a->owner_id][] = $link;
            }
        }

        $details = $rows->map(function (ClientTicket $t) use ($url, $byTicket, $byComment, $internal) {
            $comments = [];
            $att = $byTicket[$t->id] ?? [];
            foreach ($t->comments as $c) {
                $name = $c->author?->name ?? '';
                $kind = $this->wf->lookupLabel('comment_kind', $c->kind);
                $comments[] = '['.tw($c->created_at)." Asia/Taipei] {$name}（{$kind}）";
                array_push($comments, ...HtmlToText::lines($c->content_html));
                $comments[] = '';
                array_push($att, ...($byComment[$c->id] ?? []));
            }
            while ($comments && end($comments) === '') {
                array_pop($comments);
            }

            $sections = ['內容' => HtmlToText::lines($t->content_html), '留言' => $comments, '附件' => $att];
            if ($internal) {
                $sections['關聯內部單'] = $t->issues->map(fn ($i) => new ExportLink(
                    "{$i->issue_no} {$i->title}（".$this->wf->label('issue', $i->status).'）',
                    url('/issues/'.$i->id)
                ))->all();
            }

            $link = $url($t);

            return new DetailBlock("{$t->ticket_no} {$t->title}", [
                '狀態' => $this->wf->label('ticket', $t->status),
                '類型' => $this->wf->lookupLabel('ticket_type', $t->ticket_type),
                '異常類別' => $t->anomaly_category ? $this->wf->lookupLabel('anomaly_category', $t->anomaly_category) : null,
                '功能頁面' => $t->mesPage?->name,
                '開立者' => $t->creator?->name,
                '開立時間' => $t->created_at,
                '最後更新' => $t->updated_at,
                '網址' => new ExportLink($link, $link),
            ], $sections);
        })->values()->all();

        return new ExportSheet(self::LABEL, $cols, $data, $details, 'title');
    }
}
