<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Repositories\ApiTokenRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UnitOfWork;
use App\Repositories\UserRepository;
use App\Repositories\WatchRepository;
use DomainException;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class AdminService
{
    public function __construct(
        private UserRepository $users,
        private ProjectRepository $projects,
        private ApiTokenRepository $tokens,
        private WatchRepository $watches,
        private UnitOfWork $uow,
    ) {}

    /**
     * The single place that decides whether a role belongs to client users:
     * role names starting with "client_" are client roles, everything else is internal.
     */
    public function isClientRole(string $roleName): bool
    {
        return str_starts_with($roleName, 'client_');
    }

    public function roleMatchesUserType(string $roleName, string $userType): bool
    {
        return $this->isClientRole($roleName) === ($userType === 'client');
    }

    /** @return Collection<int, Role> */
    public function rolesForUserType(string $userType): Collection
    {
        return $this->projects->allRoles()->filter(fn ($r) => $this->roleMatchesUserType($r->name, $userType))->values();
    }

    // ---- users ----

    public function users(): Collection
    {
        return $this->users->all();
    }

    public function findUser(int $id): User
    {
        return $this->users->find($id) ?? throw new DomainException('找不到使用者。');
    }

    public function createUser(array $data): User
    {
        $data['is_admin'] = (bool) ($data['is_admin'] ?? false);

        return $this->users->create($data);
    }

    public function updateUser(User $user, array $data, User $actor): User
    {
        $data['is_admin'] = (bool) ($data['is_admin'] ?? false);
        if ($user->id === $actor->id && ($data['status'] !== 'active' || ! $data['is_admin'])) {
            throw new DomainException('不能停用自己或移除自己的管理員權限。');
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $typeChanged = isset($data['user_type']) && $data['user_type'] !== $user->user_type;

        return $this->uow->run(function () use ($user, $data, $typeChanged) {
            $updated = $this->users->update($user, $data);
            if ($typeChanged) {
                $this->dropAccessOfOtherType($updated);
            }

            return $updated;
        });
    }

    /**
     * After an internal <-> client switch: roles of the other type are removed in every project; a user who became
     * a client also loses internal-only things (MCP tokens, issue watches).
     */
    private function dropAccessOfOtherType(User $user): void
    {
        foreach ($this->projects->projectIdsByUser([$user->id])[$user->id] as $pid) {
            $roles = $this->projects->roleNames($user, $pid);
            $keep = array_values(array_filter($roles, fn (string $r) => $this->roleMatchesUserType($r, $user->user_type)));
            if ($keep !== $roles) {
                $this->projects->syncRoles($user, $pid, $keep);
            }
        }
        if ($user->isClient()) {
            $this->tokens->revokeAllFor($user->id);
            $this->watches->removeAllFor('issue', $user->id);
        }
    }

    public function deleteUser(User $user, User $actor): void
    {
        if ($user->id === $actor->id) {
            throw new DomainException('不能刪除自己。');
        }
        if ($this->users->hasHistory($user->id)) {
            throw new DomainException('此使用者已有操作紀錄，無法刪除，請改為停用。');
        }
        $this->users->delete($user);
    }

    // ---- projects ----

    public function projects(): Collection
    {
        return $this->projects->all();
    }

    public function findProject(int $id): Project
    {
        return $this->projects->find($id) ?? throw new DomainException('找不到專案。');
    }

    public function createProject(array $data): Project
    {
        return $this->projects->create($data);
    }

    public function updateProject(Project $project, array $data): Project
    {
        return $this->projects->update($project, $data);
    }

    // ---- members ----

    /** @return Collection<int, array{user: User, roles: array<int, string>}> */
    public function membersWithRoles(Project $project): Collection
    {
        return $this->projects->members($project)->map(fn (User $u) => [
            'user' => $u,
            'roles' => $this->projects->roleNames($u, $project->id),
        ]);
    }

    /** Users that can still be added (not yet members). */
    public function candidates(Project $project): Collection
    {
        $memberIds = $this->projects->members($project)->pluck('id');

        return $this->users->all()->filter(fn (User $u) => $u->isActive() && ! $memberIds->contains($u->id))->values();
    }

    public function addMember(Project $project, int $userId): void
    {
        $this->findUser($userId);
        $this->projects->addMember($project->id, $userId);
    }

    public function removeMember(Project $project, int $userId): void
    {
        $user = $this->findUser($userId);
        $this->projects->syncRoles($user, $project->id, []);
        $this->projects->removeMember($project->id, $userId);
    }

    public function setMemberRoles(Project $project, int $userId, array $roleNames): void
    {
        $user = $this->findUser($userId);
        if (! $this->projects->isMember($project->id, $userId)) {
            throw new DomainException('該使用者不是此專案成員。');
        }
        $valid = $this->rolesForUserType($user->user_type)->pluck('name')->all();
        $roleNames = array_values(array_unique($roleNames));
        if (array_diff($roleNames, $valid)) {
            throw new DomainException('所選角色不適用於此使用者類型。');
        }
        $this->projects->syncRoles($user, $project->id, $roleNames);
    }
}
