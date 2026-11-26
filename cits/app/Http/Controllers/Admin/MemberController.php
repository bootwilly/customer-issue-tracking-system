<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Services\AdminService;
use DomainException;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function __construct(private AdminService $admin) {}

    public function index(Project $project)
    {
        $members = $this->admin->membersWithRoles($project);

        return view('admin.projects.members', [
            'project' => $project,
            'members' => $members,
            'candidates' => $this->admin->candidates($project),
            'rolesByType' => [
                'client' => $this->admin->rolesForUserType('client'),
                'internal' => $this->admin->rolesForUserType('internal'),
            ],
        ]);
    }

    public function store(Request $request, Project $project)
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']], [], ['user_id' => '使用者']);
        try {
            $this->admin->addMember($project, (int) $data['user_id']);
        } catch (DomainException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', '已加入專案成員，請指派角色。');
    }

    public function update(Request $request, Project $project, User $user)
    {
        $data = $request->validate(['roles' => ['array'], 'roles.*' => ['string']]);
        try {
            $this->admin->setMemberRoles($project, $user->id, $data['roles'] ?? []);
        } catch (DomainException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', '角色已更新。');
    }

    public function destroy(Project $project, User $user)
    {
        try {
            $this->admin->removeMember($project, $user->id);
        } catch (DomainException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', '已移除成員。');
    }
}
