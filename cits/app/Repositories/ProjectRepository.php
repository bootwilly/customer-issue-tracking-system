<?php

namespace App\Repositories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ProjectRepository
{
    public function find(int $id): ?Project
    {
        return Project::find($id);
    }

    public function all(): Collection
    {
        return Project::orderBy('code')->get();
    }

    public function active(): Collection
    {
        return Project::where('status', 'active')->orderBy('code')->get();
    }

    public function activeFor(User $user): Collection
    {
        return Project::where('status', 'active')
            ->whereIn('id', DB::table('project_members')->where('user_id', $user->id)->select('project_id'))
            ->orderBy('code')
            ->get();
    }

    public function create(array $data): Project
    {
        return Project::create($data);
    }

    public function update(Project $project, array $data): Project
    {
        $project->fill($data)->save();

        return $project;
    }

    public function isMember(int $projectId, int $userId): bool
    {
        return DB::table('project_members')->where('project_id', $projectId)->where('user_id', $userId)->exists();
    }

    public function members(Project $project): Collection
    {
        return $project->members()->orderBy('account')->get();
    }

    public function addMember(int $projectId, int $userId): void
    {
        if (! $this->isMember($projectId, $userId)) {
            DB::table('project_members')->insert([
                'project_id' => $projectId, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function removeMember(int $projectId, int $userId): void
    {
        DB::table('project_members')->where('project_id', $projectId)->where('user_id', $userId)->delete();
    }

    /** @return Collection<int, Role> */
    public function allRoles(): Collection
    {
        return $this->withGlobalTeam(fn () => Role::orderBy('id')->get());
    }

    /** @return array<int, string> role names the user holds inside one project. */
    public function roleNames(User $user, int $projectId): array
    {
        return $this->withTeam($projectId, function () use ($user) {
            $user->unsetRelation('roles');

            return $user->roles()->pluck('name')->all();
        });
    }

    public function syncRoles(User $user, int $projectId, array $roleNames): void
    {
        $this->withTeam($projectId, function () use ($user, $roleNames) {
            $user->unsetRelation('roles');
            $user->syncRoles($roleNames);
            $user->unsetRelation('roles');
        });
    }

    private function withTeam(?int $projectId, callable $fn): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $prev = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($projectId);
        try {
            return $fn();
        } finally {
            $registrar->setPermissionsTeamId($prev);
        }
    }

    private function withGlobalTeam(callable $fn): mixed
    {
        return $this->withTeam(null, $fn);
    }
}
