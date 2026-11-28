<?php

namespace App\Http\Controllers\ProjectAdmin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\Flow\FlowService;
use App\Services\IssueTrashService;
use App\Services\Meeting\MeetingService;
use App\Services\ProjectAdminService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;

class ProjectAdminController extends Controller
{
    public function __construct(private ProjectAdminService $svc, private FlowService $flows, private MeetingService $meetings, private IssueTrashService $issues, private CurrentProject $project) {}

    private function pid(Request $r, ?string $need = null): int
    {
        $pid = (int) $this->project->id();
        $ok = match ($need) {
            'members' => $this->svc->canMembers($r->user(), $pid),
            'trash' => $this->svc->canTrash($r->user(), $pid),
            default => $this->svc->canAny($r->user(), $pid),
        };
        abort_unless($ok, 403);

        return $pid;
    }

    private function tabs(Request $r, int $pid): array
    {
        return ['canMembers' => $this->svc->canMembers($r->user(), $pid), 'canTrash' => $this->svc->canTrash($r->user(), $pid)];
    }

    public function index(Request $r)
    {
        $pid = $this->pid($r);

        return redirect()->route($this->svc->canMembers($r->user(), $pid) ? 'padmin.members' : 'padmin.trash');
    }

    public function members(Request $r)
    {
        $pid = $this->pid($r, 'members');

        return view('project-admin.members', $this->tabs($r, $pid) + [
            'members' => $this->svc->members($r->user(), $pid),
            'candidates' => $this->svc->candidates($r->user(), $pid),
            'rolesByType' => $this->svc->assignableRoles($r->user()),
            'protected' => $this->svc->protectedRoles($r->user()),
        ]);
    }

    public function addMember(Request $r)
    {
        $pid = $this->pid($r, 'members');
        $d = $r->validate(['user_id' => ['required', 'integer']], [], ['user_id' => '使用者']);

        return $this->run(fn () => $this->svc->addMember($r->user(), $pid, (int) $d['user_id']), '已加入專案，請勾選角色。');
    }

    public function setRoles(Request $r, int $user)
    {
        $pid = $this->pid($r, 'members');
        $d = $r->validate(['roles' => ['array'], 'roles.*' => ['string', 'max:100']]);

        return $this->run(fn () => $this->svc->setRoles($r->user(), $pid, $user, $d['roles'] ?? []), '角色已更新。');
    }

    public function removeMember(Request $r, int $user)
    {
        $pid = $this->pid($r, 'members');

        return $this->run(fn () => $this->svc->removeMember($r->user(), $pid, $user), '已移出專案。');
    }

    public function trash(Request $r)
    {
        $pid = $this->pid($r, 'trash');

        return view('project-admin.trash', $this->tabs($r, $pid) + [
            'flows' => $this->flows->trashed($r->user(), $pid), 'meetings' => $this->meetings->trashed($r->user(), $pid),
            'issues' => $this->issues->trashed($r->user(), $pid),
        ]);
    }

    public function restoreFlow(Request $r, int $id)
    {
        $pid = $this->pid($r, 'trash');

        return $this->run(fn () => $this->flows->restore($r->user(), $pid, $id), '已還原流程圖。');
    }

    public function purgeFlow(Request $r, int $id)
    {
        $pid = $this->pid($r, 'trash');
        $d = $r->validate(['confirm_title' => ['required', 'string', 'max:200']], [], ['confirm_title' => '流程圖標題']);

        return $this->run(fn () => $this->flows->purge($r->user(), $pid, $id, $d['confirm_title']), '已永久刪除。');
    }

    public function restoreMeeting(Request $r, int $id)
    {
        $pid = $this->pid($r, 'trash');

        return $this->run(fn () => $this->meetings->restore($r->user(), $pid, $id), '已還原會議。');
    }

    public function purgeMeeting(Request $r, int $id)
    {
        $pid = $this->pid($r, 'trash');
        $d = $r->validate(['confirm_title' => ['required', 'string', 'max:200']], [], ['confirm_title' => '會議主題']);

        return $this->run(fn () => $this->meetings->purge($r->user(), $pid, $id, $d['confirm_title']), '已永久刪除。');
    }

    public function restoreIssue(Request $r, int $id)
    {
        $pid = $this->pid($r, 'trash');

        return $this->run(fn () => $this->issues->restore($r->user(), $pid, $id), '已還原內部單。');
    }

    public function purgeIssue(Request $r, int $id)
    {
        $pid = $this->pid($r, 'trash');
        $d = $r->validate(['confirm_no' => ['required', 'string', 'max:30']], [], ['confirm_no' => '單號']);

        return $this->run(fn () => $this->issues->purge($r->user(), $pid, $id, $d['confirm_no']), '已永久刪除。');
    }

    public function events(Request $r)
    {
        $pid = $this->pid($r);

        return view('project-admin.events', $this->tabs($r, $pid) + ['events' => $this->svc->events($r->user(), $pid)]);
    }

    private function run(callable $fn, string $ok)
    {
        try {
            $fn();
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', $ok);
    }
}
