<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Export\ExportSettingService;
use Illuminate\Http\Request;

class ExportSettingController extends Controller
{
    public function __construct(private ExportSettingService $svc) {}

    public function edit()
    {
        return view('admin.settings.export', ['maxRows' => $this->svc->maxRows()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['max_rows' => ['required', 'integer', 'between:1,100000']], [], ['max_rows' => '匯出筆數上限']);
        $this->svc->setMaxRows((int) $data['max_rows']);

        return redirect()->route('admin.export.edit')->with('ok', '匯出設定已儲存。');
    }
}
