<?php

namespace App\Support;

use App\Models\Project;
use App\Repositories\ProjectRepository;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

class CurrentProject
{
    private ?Project $cached = null;

    public function __construct(private ProjectRepository $projects) {}

    public function id(): ?int
    {
        $id = session('project_id');

        return $id ? (int) $id : null;
    }

    public function get(): ?Project
    {
        $id = $this->id();
        if (! $id) {
            return null;
        }
        if (! $this->cached || $this->cached->id !== $id) {
            $this->cached = $this->projects->find($id);
        }

        return $this->cached;
    }

    public function set(int $id): void
    {
        session(['project_id' => $id]);
        $this->cached = null;
        $this->apply();
    }

    public function forget(): void
    {
        session()->forget('project_id');
        $this->cached = null;
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /** Bind the permission team id to the selected project so permission checks are per project. */
    public function apply(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->id());

        $user = Auth::user();
        if ($user) {
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
        }
    }
}
