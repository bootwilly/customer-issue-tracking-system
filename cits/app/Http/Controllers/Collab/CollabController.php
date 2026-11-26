<?php

namespace App\Http\Controllers\Collab;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\Collab\DependencyService;
use App\Services\Collab\NeedinfoService;
use App\Services\Collab\SavedQueryService;
use App\Services\Collab\SimilarService;
use App\Services\Collab\TagService;
use App\Services\Collab\WatchService;
use App\Services\IssueService;
use App\Services\TicketService;
use App\Support\CurrentProject;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** needinfo, watch, duplicate hint, saved queries, issue tags, issue dependencies (docs/bugzilla-inspired-suggestions.md). */
class CollabController extends Controller
{
    public function __construct(
        private CurrentProject $project,
        private TicketService $tickets,
        private IssueService $issues,
    ) {}

    // ---------- watch ----------

    public function watchTicket(Request $r, int $id, WatchService $watch): RedirectResponse
    {
        $on = $r->boolean('on');

        return $this->act(fn () => $watch->toggleTicket($r->user(), $this->tickets->get($id, $this->pid()), $on), $on ? '已關注這張單。' : '已取消關注。');
    }

    public function watchIssue(Request $r, int $id, WatchService $watch): RedirectResponse
    {
        $on = $r->boolean('on');

        return $this->act(fn () => $watch->toggleIssue($r->user(), $this->issues->get($id, $this->pid(), $r->user()), $on), $on ? '已關注這張單。' : '已取消關注。');
    }

    // ---------- needinfo ----------

    public function requestInfo(Request $r, int $id, NeedinfoService $svc): RedirectResponse
    {
        $d = $r->validate(['note' => 'required|string|max:'.NeedinfoService::NOTE_MAX]);

        return $this->act(fn () => $svc->request($r->user(), $id, $this->pid(), $d['note']), '已通知客戶補充資料。');
    }

    public function withdrawInfo(Request $r, int $id, NeedinfoService $svc): RedirectResponse
    {
        return $this->act(fn () => $svc->withdraw($r->user(), $id, $this->pid()), '已取消補充資料要求。');
    }

    // ---------- duplicate hint (JSON) ----------

    public function similarTickets(Request $r, SimilarService $svc): JsonResponse
    {
        $d = $r->validate(['title' => 'nullable|string|max:200', 'except' => 'nullable|integer']);

        return response()->json(['items' => $svc->tickets($r->user(), $this->pid(), (string) ($d['title'] ?? ''), $d['except'] ?? null)]);
    }

    public function similarInternal(Request $r, SimilarService $svc): JsonResponse
    {
        abort_unless($r->user()->seesInternal(), 403);
        $d = $r->validate(['title' => 'nullable|string|max:200', 'except' => 'nullable|integer']);

        return response()->json(['items' => $svc->internal($r->user(), $this->pid(), (string) ($d['title'] ?? ''), $d['except'] ?? null)]);
    }

    // ---------- saved queries ----------

    public function saveQuery(Request $r, SavedQueryService $svc): RedirectResponse
    {
        $d = $r->validate(['scope' => 'required|string|max:20', 'name' => 'required|string|max:100', 'qs' => 'nullable|string|max:2000']);
        parse_str((string) ($d['qs'] ?? ''), $params);
        try {
            $q = $svc->save($r->user(), $this->pid(), $d['scope'], $d['name'], $params);
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage());
        }

        return redirect()->to($svc->url($q))->with('ok', "已儲存查詢「{$q->name}」。");
    }

    public function renameQuery(Request $r, int $id, SavedQueryService $svc): RedirectResponse
    {
        $d = $r->validate(['name' => 'required|string|max:100']);

        return $this->act(fn () => $svc->rename($r->user(), $this->pid(), $id, $d['name']), '已改名。');
    }

    public function moveQuery(Request $r, int $id, SavedQueryService $svc): RedirectResponse
    {
        $d = $r->validate(['dir' => 'required|in:up,down']);

        return $this->act(fn () => $svc->move($r->user(), $this->pid(), $id, $d['dir']), '已調整順序。');
    }

    public function deleteQuery(Request $r, int $id, SavedQueryService $svc): RedirectResponse
    {
        return $this->act(fn () => $svc->delete($r->user(), $this->pid(), $id), '已刪除查詢。');
    }

    // ---------- tags ----------

    public function tags(Request $r, int $id, TagService $svc): RedirectResponse
    {
        $d = $r->validate(['tags' => 'nullable|array|max:50', 'tags.*' => 'string|max:30']);

        return $this->act(fn () => $svc->set($r->user(), $this->issues->get($id, $this->pid(), $r->user()), $d['tags'] ?? []), '已更新標籤。');
    }

    // ---------- dependencies ----------

    public function addDependency(Request $r, int $id, DependencyService $svc): RedirectResponse
    {
        $d = $r->validate(['issue_no' => 'required|string|max:50', 'kind' => 'required|string|max:20']);

        return $this->act(fn () => $svc->add($r->user(), $this->issues->get($id, $this->pid(), $r->user()), $d['issue_no'], $d['kind']), '已新增相依關係。', '#deps');
    }

    public function removeDependency(Request $r, int $id, int $dep, DependencyService $svc): RedirectResponse
    {
        return $this->act(fn () => $svc->remove($r->user(), $this->issues->get($id, $this->pid(), $r->user()), $dep), '已移除相依關係。', '#deps');
    }

    private function pid(): int
    {
        return (int) $this->project->id();
    }

    private function act(Closure $fn, string $ok, string $anchor = ''): RedirectResponse
    {
        try {
            $fn();
        } catch (BusinessException $e) {
            return redirect()->to(url()->previous().$anchor)->with('err', $e->getMessage())->withInput();
        }

        return redirect()->to(url()->previous().$anchor)->with('ok', $ok);
    }
}
