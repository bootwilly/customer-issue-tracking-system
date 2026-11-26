<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Services\Report\ReportService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $svc, private CurrentProject $project) {}

    public function index(Request $r)
    {
        $pid = $this->authorizeView($r);

        return view('reports.index', ['d' => $this->svc->build($pid, $r->query('from'), $r->query('to'))]);
    }

    public function export(Request $r)
    {
        $pid = $this->authorizeView($r);
        $d = $this->svc->build($pid, $r->query('from'), $r->query('to'));
        $sections = $this->svc->csvSections($d);
        $name = 'report-'.$d['from'].'_'.$d['to'].'.csv';

        return response()->streamDownload(function () use ($sections) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($sections as $i => [$title, $headers, $rows]) {
                if ($i > 0) {
                    fwrite($out, "\r\n");
                }
                fputcsv($out, [$title], ',', '"', '', "\r\n");
                fputcsv($out, $headers, ',', '"', '', "\r\n");
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($c) => $this->safe($c), $row), ',', '"', '', "\r\n");
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeView(Request $r): int
    {
        $pid = (int) $this->project->id();
        abort_unless($this->svc->canView($r->user(), $pid), 403);

        return $pid;
    }

    /** Neutralise spreadsheet formula injection in user-entered text. */
    private function safe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && str_contains('=+-@', $v[0]) ? "'".$v : $v;
    }
}
