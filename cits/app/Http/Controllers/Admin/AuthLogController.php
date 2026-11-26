<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\AuthLogService;
use App\Services\Export\AuthLogExportService;
use App\Services\Export\XlsxExportService;
use Illuminate\Http\Request;

class AuthLogController extends Controller
{
    public function __construct(private AuthLogService $logs) {}

    public function index(Request $r)
    {
        $f = $this->logs->filters($r->query());

        return view('admin.auth-logs.index', [
            'f' => $f,
            'logs' => $this->logs->search($f),
            'events' => $this->logs->events(),
            'details' => $this->logs->details(),
            'keepDays' => $this->logs->keepDays(),
        ]);
    }

    public function export(Request $r, AuthLogExportService $exporter, XlsxExportService $xlsx)
    {
        try {
            $book = $exporter->book($this->logs->filters($r->query()));
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage());
        }

        return $xlsx->download($book, $r->user(), null, '系統');
    }
}
