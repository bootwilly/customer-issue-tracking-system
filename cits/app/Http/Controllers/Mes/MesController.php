<?php

namespace App\Http\Controllers\Mes;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\Mes\MesService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;

class MesController extends Controller
{
    public function __construct(private MesService $svc, private CurrentProject $project) {}

    private function guard(Request $r): int
    {
        $pid = (int) $this->project->id();
        abort_unless($this->svc->canManage($r->user(), $pid), 403);

        return $pid;
    }

    /** Run a service call; BusinessException goes back with the message. */
    private function run(callable $fn, string $ok)
    {
        try {
            $fn();
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }

        return redirect()->route('mes.index')->with('ok', $ok);
    }

    public function index(Request $r)
    {
        $pid = (int) $this->project->id();
        abort_unless($this->svc->canView($r->user(), $pid), 403);

        return view('mes.index', [
            'nodes' => $this->svc->tree($pid),
            'folders' => $this->svc->folders($pid),
            'canManage' => $this->svc->canManage($r->user(), $pid),
        ]);
    }

    private function nodeRules(): array
    {
        return [
            'node_type' => ['sometimes', 'in:folder,page'],
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer'],
            'page_path' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function store(Request $r)
    {
        $pid = $this->guard($r);
        $d = $r->validate($this->nodeRules());

        return $this->run(fn () => $this->svc->create($r->user(), $pid, $d), '已新增。');
    }

    public function update(Request $r, int $id)
    {
        $pid = $this->guard($r);
        $d = $r->validate($this->nodeRules());

        return $this->run(fn () => $this->svc->update($r->user(), $pid, $id, $d), '已更新。');
    }

    public function toggle(Request $r, int $id)
    {
        $pid = $this->guard($r);

        return $this->run(fn () => $this->svc->toggle($r->user(), $pid, $id), '已變更啟用狀態。');
    }

    public function importPreview(Request $r)
    {
        $pid = $this->guard($r);
        $d = $r->validate(['paths' => ['required', 'string', 'max:100000']]);
        try {
            $rows = $this->svc->previewImport($r->user(), $pid, $d['paths']);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }

        return view('mes.preview', ['rows' => $rows, 'paths' => $d['paths']]);
    }

    public function sitemapPreview(Request $r)
    {
        $pid = $this->guard($r);
        $d = $r->validate([
            'sitemap_file' => ['nullable', 'file', 'max:2048'],
            'sitemap_xml' => ['nullable', 'string', 'max:2097152'],
        ]);
        $file = $r->file('sitemap_file');
        if ($file && ! in_array(strtolower($file->getClientOriginalExtension()), ['sitemap', 'xml'], true)) {
            return back()->with('err', '檔案副檔名必須是 .sitemap 或 .xml。');
        }
        $xml = $file ? (string) $file->get() : (string) ($d['sitemap_xml'] ?? '');
        try {
            $res = $this->svc->previewSitemap($r->user(), $pid, $xml);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage());
        }

        return view('mes.sitemap_preview', $res);
    }

    public function sitemapApply(Request $r)
    {
        $pid = $this->guard($r);
        $d = $r->validate(['payload' => ['required', 'string', 'max:3000000']]);
        try {
            $c = $this->svc->applySitemap($r->user(), $pid, $d['payload']);
        } catch (BusinessException $e) {
            return redirect()->route('mes.index')->with('err', $e->getMessage());
        }

        return redirect()->route('mes.index')->with('ok', "已匯入 Web.sitemap：新增 {$c['new']}、更新 {$c['update']}、不變 {$c['same']}。");
    }

    public function importApply(Request $r)
    {
        $pid = $this->guard($r);
        $d = $r->validate(['paths' => ['required', 'string', 'max:100000']]);
        try {
            $n = $this->svc->applyImport($r->user(), $pid, $d['paths']);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }

        return redirect()->route('mes.index')->with('ok', "已匯入 {$n} 個頁面。");
    }
}
