<?php

namespace App\Services\Export;

use App\Models\AuthLog;
use App\Services\AuthLogService;

/** Sign-in log workbook (system admin). Columns come from lookup export_auth_logs. */
class AuthLogExportService
{
    private const LABEL = '登入紀錄';

    public function __construct(
        private AuthLogService $logs,
        private ExportColumnService $columns,
        private XlsxExportService $xlsx,
    ) {}

    public function book(array $f): ExportBook
    {
        $rows = $this->logs->exportRows($f, $this->xlsx->maxRows() + 1);
        $this->xlsx->assertWithinLimit($rows->count());

        $resolvers = [
            'created_at' => fn (AuthLog $l) => $l->created_at,
            'event' => fn (AuthLog $l) => $this->logs->eventLabel($l->event),
            'detail' => fn (AuthLog $l) => $this->logs->detailLabel($l->detail),
            'account' => fn (AuthLog $l) => $l->user?->account,
            'name' => fn (AuthLog $l) => $l->user?->name,
            'login' => fn (AuthLog $l) => $l->login,
            'ip' => fn (AuthLog $l) => $l->ip,
            'forwarded_for' => fn (AuthLog $l) => $l->forwarded_for,
            'user_agent' => fn (AuthLog $l) => $l->user_agent,
        ];
        $cols = $this->columns->columns('auth_logs', array_keys($resolvers));
        $data = $rows->map(function (AuthLog $l) use ($cols, $resolvers) {
            $out = [];
            foreach (array_keys($cols) as $k) {
                $out[$k] = $resolvers[$k]($l);
            }

            return $out;
        })->values()->all();

        $info = [
            '使用者' => $f['user'] ?? '全部',
            'IP' => $f['ip'] ?? '全部',
            '事件' => $f['event'] ? $this->logs->eventLabel($f['event']) : '全部',
            '日期' => ($f['from'] || $f['to']) ? (($f['from'] ?? '…').' ～ '.($f['to'] ?? '…')) : '全部',
            '筆數' => (string) count($data),
        ];

        return new ExportBook('auth_logs', $this->xlsx->filename('CITS', self::LABEL), [
            new ExportSheet(self::LABEL, $cols, $data),
        ], $info);
    }
}
