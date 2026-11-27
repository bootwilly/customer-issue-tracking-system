<?php

namespace App\Services\Export;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingComment;
use App\Models\MeetingItem;
use App\Models\User;
use App\Repositories\ProjectRepository;
use App\Services\Meeting\MeetingService;
use App\Services\WorkflowService;
use Closure;
use Illuminate\Support\Collection;

/**
 * Meeting workbook: sheet 會議 (+ 會議詳細: record, internal notes, items, comments) and sheet 討論事項.
 * Visibility follows MeetingService (clients: shared meetings only, no internal notes/comments/issue links,
 * details only after the record is published).
 */
class MeetingExportService
{
    private const LABEL = '會議';

    private const ITEMS_LABEL = '討論事項';

    public function __construct(
        private MeetingService $meetings,
        private ExportColumnService $columns,
        private XlsxExportService $xlsx,
        private WorkflowService $wf,
        private ProjectRepository $projects,
    ) {}

    public function canExport(User $u, int $projectId): bool
    {
        return $this->wf->can($u, 'data.export', $projectId);
    }

    /** @param array{type:?string,status:?string,from:?string,to:?string} $f MeetingService::filters() */
    public function listBook(User $u, int $projectId, string $tab, ?string $keyword, array $f): ExportBook
    {
        $rows = $this->meetings->exportRows($u, $projectId, $tab, $keyword, $f, $this->xlsx->maxRows() + 1);
        $this->xlsx->assertWithinLimit(count($rows));
        $tabs = $this->meetings->tabs();
        $info = [
            '頁籤' => $tabs[$tab] ?? $tab,
            '類型' => $f['type'] ? $this->meetings->typeLabel($f['type']) : '全部',
            '狀態' => $f['status'] ? $this->meetings->statusLabel($f['status']) : '全部',
            '日期' => ($f['from'] || $f['to']) ? (($f['from'] ?? '…').' ～ '.($f['to'] ?? '…')) : '全部',
            '關鍵字' => $keyword ?? '（無）',
            '筆數' => (string) count($rows),
        ];

        return $this->book($u, $projectId, $rows, self::LABEL, $info);
    }

    public function oneBook(User $u, int $projectId, int $id): ExportBook
    {
        $row = $this->meetings->exportOne($u, $projectId, $id);
        $m = $row['meeting'];

        return $this->book($u, $projectId, [$row], self::LABEL.'_'.$m->title, [
            '會議' => $m->title,
            '時間' => tw($m->start_at),
        ]);
    }

    private function book(User $u, int $projectId, array $rows, string $fileLabel, array $info): ExportBook
    {
        $internal = $u->seesInternal();
        $code = (string) $this->projects->find($projectId)?->code;

        return new ExportBook('meetings', $this->xlsx->filename($code, $fileLabel), [
            $this->meetingSheet($rows, $internal),
            $this->itemSheet($rows, $internal),
        ], $info);
    }

    // ---------- 會議 ----------

    private function meetingSheet(array $rows, bool $internal): ExportSheet
    {
        $itemStatuses = $this->wf->statusMap('meeting_item');
        $resolvers = [
            'start_at' => fn (array $r) => $r['meeting']->start_at,
            'end_at' => fn (array $r) => $r['meeting']->end_at,
            'title' => fn (array $r) => $r['meeting']->title,
            'type' => fn (array $r) => $this->meetings->typeLabel($r['meeting']->type),
            'status' => fn (array $r) => $this->meetings->statusLabel($r['meeting']->status),
            'location' => fn (array $r) => $r['meeting']->location,
            'attendees' => fn (array $r) => $this->attendeeNames($r['attendees']),
            'creator' => fn (array $r) => $r['meeting']->creator?->name,
            'published_at' => fn (array $r) => $r['meeting']->published_at,
            'item_count' => fn (array $r) => $r['items']->count(),
            'open_items' => fn (array $r) => ! $r['content'] ? null : $r['items']->filter(fn (MeetingItem $i) => ! ($itemStatuses->get($i->status)?->is_terminal))->count(),
            'updated_at' => fn (array $r) => $r['meeting']->updated_at,
        ];
        if ($internal) {
            $resolvers['shared'] = fn (array $r) => (bool) $r['meeting']->customer_visible;
        }
        $cols = $this->columns->columns('meetings', array_keys($resolvers));
        $data = array_map(fn (array $r) => $this->pick($cols, $resolvers, $r), $rows);
        $details = array_map(fn (array $r) => $this->detail($r, $internal), $rows);

        return new ExportSheet(self::LABEL, $cols, $data, $details, 'title');
    }

    private function detail(array $r, bool $internal): DetailBlock
    {
        /** @var Meeting $m */
        $m = $r['meeting'];
        $url = route('meetings.show', $m->id);
        $items = [];
        foreach ($r['items']->values() as $n => $i) {
            $meta = array_filter([
                $i->owner?->name ? '負責人：'.$i->owner->name : null,
                $i->due_date ? '期限：'.$i->due_date->format('Y-m-d') : null,
                $r['content'] ? '狀態：'.$this->wf->label('meeting_item', $i->status) : null,
            ]);
            $items[] = ($n + 1).'. '.$i->topic.'（'.implode('／', $meta).'）';
            if ($internal && $i->issue) {
                $items[] = new ExportLink('　內部單：'.$i->issue->issue_no.' '.$i->issue->title, url('/issues/'.$i->issue->id));
            }
            foreach (['discussion' => '討論', 'conclusion' => '結論'] as $k => $label) {
                if ((string) $i->{$k} !== '') {
                    foreach (preg_split('/\R/u', (string) $i->{$k}) as $j => $line) {
                        $items[] = '　'.($j === 0 ? $label.'：' : '　　').$line;
                    }
                }
            }
        }
        $comments = [];
        foreach ($r['comments'] as $c) {
            /** @var MeetingComment $c */
            $tag = $c->is_internal ? '（內部）' : '';
            $comments[] = '['.tw($c->created_at)." Asia/Taipei] {$c->author?->name}{$tag}".($c->parent_id ? ' 回覆' : '');
            $comments[] = $c->deleted_at ? '（已刪除）' : (string) $c->body;
            $comments[] = '';
        }
        while ($comments && end($comments) === '') {
            array_pop($comments);
        }
        $sections = [
            '會議紀錄' => $r['content'] ? HtmlToText::lines((string) $r['notes']) : ['（紀錄尚未發布）'],
            '討論事項' => $items,
            '留言' => $comments,
        ];
        if ($internal) {
            $sections = ['會議紀錄' => $sections['會議紀錄'], '內部備註' => HtmlToText::lines((string) $r['internal'])] + $sections;
        }
        $fields = [
            '時間' => tw($m->start_at).($m->end_at ? ' ～ '.tw($m->end_at, 'H:i') : ''),
            '類型' => $this->meetings->typeLabel($m->type),
            '狀態' => $this->meetings->statusLabel($m->status),
            '地點' => $m->location,
            '與會者' => $this->attendeeNames($r['attendees']),
            '發起人' => $m->creator?->name,
        ];
        if ($internal) {
            $fields['分享給客戶'] = $m->customer_visible ? '是' : '否';
        }
        $fields['網址'] = new ExportLink($url, $url);

        return new DetailBlock(tw($m->start_at, 'Y-m-d').' '.$m->title, $fields, $sections);
    }

    // ---------- 討論事項 ----------

    private function itemSheet(array $rows, bool $internal): ExportSheet
    {
        $resolvers = [
            'meeting_start' => fn (array $x) => $x['m']->start_at,
            'meeting' => fn (array $x) => new ExportLink($x['m']->title, route('meetings.show', $x['m']->id).'#item-'.$x['i']->id),
            'no' => fn (array $x) => $x['n'],
            'topic' => fn (array $x) => $x['i']->topic,
            'discussion' => fn (array $x) => $x['i']->discussion,
            'conclusion' => fn (array $x) => $x['i']->conclusion,
            'owner' => fn (array $x) => $x['i']->owner?->name,
            'due_date' => fn (array $x) => $x['i']->due_date?->format('Y-m-d'),
            'status' => fn (array $x) => $x['c'] ? $this->wf->label('meeting_item', $x['i']->status) : null,
        ];
        if ($internal) {
            $resolvers['issue'] = fn (array $x) => $x['i']->issue ? new ExportLink($x['i']->issue->issue_no, url('/issues/'.$x['i']->issue->id)) : null;
        }
        $cols = $this->columns->columns('meeting_items', array_keys($resolvers));
        $data = [];
        foreach ($rows as $r) {
            foreach ($r['items']->values() as $n => $i) {
                $data[] = $this->pick($cols, $resolvers, ['m' => $r['meeting'], 'i' => $i, 'n' => $n + 1, 'c' => $r['content']]);
            }
        }

        return new ExportSheet(self::ITEMS_LABEL, $cols, $data);
    }

    // ---------- helpers ----------

    /** @param array<string,Closure> $resolvers */
    private function pick(array $cols, array $resolvers, array $row): array
    {
        $out = [];
        foreach (array_keys($cols) as $k) {
            $out[$k] = $resolvers[$k]($row);
        }

        return $out;
    }

    private function attendeeNames(Collection $attendees): string
    {
        return $attendees->map(fn (MeetingAttendee $a) => $a->user?->name ?? $a->external_name)->filter()->implode('、');
    }
}
