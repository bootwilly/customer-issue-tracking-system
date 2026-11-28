<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Project;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\ProjectRepository;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Project admin (role with project.members / project.trash): a system admin limited to one project.
 * Member operations reuse AdminService; this layer adds the anti-escalation rules:
 *  - only existing active accounts can be added (no account creation);
 *  - roles listed in lookup role_admin_only can be neither granted nor revoked here,
 *    and members holding one are read-only for project admins;
 *  - no changing or removing yourself.
 * System admins bypass permission checks (Gate::before) but the same rules apply on this page.
 */
class ProjectAdminService
{
    public function __construct(
        private AdminService $admin,
        private ProjectRepository $projects,
        private EventRepository $events,
        private WorkflowService $wf,
    ) {}

    public function canMembers(User $u, int $pid): bool
    {
        return $this->wf->can($u, 'project.members', $pid);
    }

    public function canTrash(User $u, int $pid): bool
    {
        return $this->wf->can($u, 'project.trash', $pid);
    }

    public function canAny(User $u, int $pid): bool
    {
        return $this->canMembers($u, $pid) || $this->canTrash($u, $pid);
    }

    private function project(int $pid): Project
    {
        return $this->projects->find($pid) ?? throw new BusinessException('找不到專案。');
    }

    /** @return string[] */
    public function protectedRoles(?User $u = null): array
    {
        return $u?->is_admin ? [] : $this->wf->lookupCodes('role_admin_only');
    }

    /** @return Collection<int, array{user: User, roles: string[], locked: bool}> */
    public function members(User $u, int $pid): Collection
    {
        $this->wf->authorize($u, 'project.members', $pid);
        $protected = $this->protectedRoles($u);

        return $this->admin->membersWithRoles($this->project($pid))->map(fn ($m) => $m + [
            // system admins are managed by system admins only, whatever roles they hold in the project
            'locked' => $m['user']->id === $u->id || (! $u->is_admin && $m['user']->is_admin) || (bool) array_intersect($m['roles'], $protected),
        ]);
    }

    /** @return array<string, Collection> user_type => roles a project admin may grant */
    public function assignableRoles(?User $u = null): array
    {
        $protected = $this->protectedRoles($u);
        $pick = fn (string $type) => $this->admin->rolesForUserType($type)->reject(fn ($r) => in_array($r->name, $protected, true))->values();

        return ['client' => $pick('client'), 'internal' => $pick('internal')];
    }

    public function candidates(User $u, int $pid): Collection
    {
        $this->wf->authorize($u, 'project.members', $pid);
        $all = $this->admin->candidates($this->project($pid));
        if ($u->is_admin) {
            return $all;
        }
        // A project admin sees client accounts only when they are in no project yet, or only in projects the
        // project admin also belongs to: another customer's accounts are neither listed nor addable.
        $clients = $all->filter(fn (User $c) => $c->isClient());
        $byUser = $this->projects->projectIdsByUser([$u->id, ...$clients->pluck('id')->all()]);
        $mine = $byUser[$u->id];

        return $all->filter(fn (User $c) => ! $c->isClient() || array_diff($byUser[$c->id], $mine) === [])->values();
    }

    public function addMember(User $u, int $pid, int $userId): void
    {
        $this->wf->authorize($u, 'project.members', $pid);
        $target = $this->candidates($u, $pid)->firstWhere('id', $userId)
            ?? throw new BusinessException('只能加入啟用中、且尚未在專案內的帳號。');
        $this->call(fn () => $this->admin->addMember($this->project($pid), $target->id));
        $this->events->log($pid, 'member', $target->id, 'member_added', null, null, $u->id, ['account' => $target->account, 'name' => $target->name]);
    }

    /** @param string[] $roles */
    public function setRoles(User $u, int $pid, int $userId, array $roles): void
    {
        $m = $this->editableMember($u, $pid, $userId);
        $roles = array_values(array_unique($roles));
        if (array_intersect($roles, $this->protectedRoles($u))) {
            throw new BusinessException('這個角色只能由系統管理員指派。');
        }
        $this->call(fn () => $this->admin->setMemberRoles($this->project($pid), $userId, $roles));
        $this->events->log($pid, 'member', $userId, 'member_roles_changed', null, null, $u->id,
            ['account' => $m['user']->account, 'from' => $m['roles'], 'to' => $roles]);
    }

    public function removeMember(User $u, int $pid, int $userId): void
    {
        $m = $this->editableMember($u, $pid, $userId);
        $this->call(fn () => $this->admin->removeMember($this->project($pid), $userId));
        $this->events->log($pid, 'member', $userId, 'member_removed', null, null, $u->id,
            ['account' => $m['user']->account, 'name' => $m['user']->name, 'roles' => $m['roles']]);
    }

    /** @return array{user: User, roles: string[], locked: bool} */
    private function editableMember(User $u, int $pid, int $userId): array
    {
        if ($userId === $u->id) {
            throw new BusinessException('不能變更或移除自己。');
        }
        $m = $this->members($u, $pid)->first(fn ($m) => $m['user']->id === $userId)
            ?? throw new BusinessException('該使用者不是此專案成員。');
        if ($m['locked']) {
            throw new BusinessException('此成員具有只能由系統管理員管理的角色，請聯絡系統管理員。');
        }

        return $m;
    }

    public function events(User $u, int $pid): Collection
    {
        if (! $this->canAny($u, $pid)) {
            throw new BusinessException('沒有權限執行此操作。');
        }

        // subject type = workflow scope of the statuses an event carries
        return $this->events->forProject($pid, ['member', 'flow', 'meeting'], 200, ['issue' => IssueTrashService::EVENTS])->each(function ($e) {
            $e->label = $this->wf->lookupLabel('event_label', $e->event_type);
            $e->from_label = $e->from_status ? $this->wf->label($e->subject_type, $e->from_status) : null;
            $e->to_label = $e->to_status ? $this->wf->label($e->subject_type, $e->to_status) : null;
        });
    }

    private function call(callable $fn): void
    {
        try {
            $fn();
        } catch (DomainException $e) {
            throw new BusinessException($e->getMessage());
        }
    }
}
