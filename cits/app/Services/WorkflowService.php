<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Models\WorkflowStatus;
use App\Repositories\WorkflowRepository;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

class WorkflowService
{
    public const SYSTEM = 'system';

    public function __construct(private WorkflowRepository $repo) {}

    /** @return Collection<string, WorkflowStatus> */
    public function statusMap(string $scope): Collection
    {
        return $this->repo->statuses($scope);
    }

    public function status(string $scope, string $code): WorkflowStatus
    {
        return $this->repo->statuses($scope)->get($code) ?? throw new BusinessException("未定義的狀態：$code");
    }

    public function label(string $scope, string $code): string
    {
        return $this->repo->statuses($scope)->get($code)?->name ?? $code;
    }

    /** @return string[] status codes shown under a client tab */
    public function codesForTab(string $tab): array
    {
        return $this->repo->statuses('ticket')->where('client_tab', $tab)->keys()->all();
    }

    /**
     * Ticket status codes for a list scope: a client_tab code, or 'all'. Unknown/empty = the first tab (unfinished tickets).
     *
     * @return string[]
     */
    public function ticketScopeCodes(?string $scope): array
    {
        if ($scope === 'all') {
            return $this->repo->statuses('ticket')->keys()->all();
        }

        return $this->codesForTab($this->ticketScope($scope));
    }

    /** Normalized ticket list scope: a known client_tab code or 'all'; default is the first tab. */
    public function ticketScope(?string $scope): string
    {
        $tabs = $this->lookupCodes('client_tab');

        return $scope === 'all' || in_array($scope, $tabs, true) ? $scope : (string) ($tabs[0] ?? '');
    }

    /** "Only needing action" only makes sense on the default (unfinished) scope. */
    public function isDefaultTicketScope(?string $scope): bool
    {
        return $this->ticketScope($scope) === ($this->lookupCodes('client_tab')[0] ?? null);
    }

    /** @return string[] */
    public function codesForStage(string $stage): array
    {
        return $this->repo->statuses('issue')->where('stage', $stage)->keys()->all();
    }

    public function initial(string $category, string $code): string
    {
        return $this->repo->lookupName($category, $code) ?? throw new BusinessException("缺少初始狀態設定：$category/$code");
    }

    public function lookups(string $category): Collection
    {
        return $this->repo->lookups($category);
    }

    /** @return string[] active codes of a lookup category, in sort order */
    public function lookupCodes(string $category): array
    {
        return $this->repo->lookups($category)->pluck('code')->all();
    }

    /** @return array<string,string> code => name of a lookup category, in sort order */
    public function lookupMap(string $category): array
    {
        return $this->repo->lookups($category)->pluck('name', 'code')->all();
    }

    /** @return array<string,string> code => name including disabled items: for displaying values already stored */
    public function lookupMapAll(string $category): array
    {
        return $this->repo->lookupsAll($category)->pluck('name', 'code')->all();
    }

    /** Required lookup value (configuration error if missing). */
    public function lookupValue(string $category, string $code): string
    {
        return $this->repo->lookupName($category, $code) ?? throw new BusinessException("缺少設定：$category/$code");
    }

    public function lookupLabel(string $category, ?string $code): string
    {
        return $code ? ($this->repo->lookupName($category, $code) ?? $code) : '';
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        return $this->repo->setting($key, $default);
    }

    /** Permission check scoped to a project (spatie teams). Admins pass via Gate::before. */
    public function can(User $user, string $permission, int $projectId): bool
    {
        if ($permission === self::SYSTEM) {
            return false;
        }
        $reg = app(PermissionRegistrar::class);
        $prev = $reg->getPermissionsTeamId();
        $reg->setPermissionsTeamId($projectId);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        try {
            return $user->can($permission);
        } finally {
            $reg->setPermissionsTeamId($prev);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function authorize(User $user, string $permission, int $projectId): void
    {
        if (! $this->can($user, $permission, $projectId)) {
            throw new BusinessException('沒有權限執行此操作。');
        }
    }

    /** Transition targets the user may use from a status (system-only transitions excluded). */
    public function allowedTargets(string $scope, string $from, User $user, int $projectId): Collection
    {
        return $this->repo->transitionsFrom($scope, $from)
            ->filter(fn ($t) => collect(explode('|', $t->permission))->contains(fn ($p) => $this->can($user, $p, $projectId)))
            ->map(fn ($t) => ['code' => $t->to_code, 'name' => $this->label($scope, $t->to_code), 'requires' => $t->requires_fields ?? []])
            ->values();
    }

    /** Target of the transition from $from that requires $field (e.g. the DEV hand-off that requires a package), or null. */
    public function targetRequiring(string $scope, string $from, string $field): ?string
    {
        return $this->repo->transitionsFrom($scope, $from)
            ->filter(fn ($t) => in_array($field, $t->requires_fields ?? [], true))
            ->sortBy(fn ($t) => $this->status($scope, $t->to_code)->sort)
            ->first()?->to_code;
    }

    /** Find a target reachable from $from via a transition whose permission list contains $permission. */
    public function targetByPermission(string $scope, string $from, string $permission, ?callable $filter = null): ?string
    {
        return $this->repo->transitionsFrom($scope, $from)
            ->filter(fn ($t) => in_array($permission, explode('|', $t->permission), true))
            ->filter(fn ($t) => $filter ? $filter($this->status($scope, $t->to_code)) : true)
            ->sortBy(fn ($t) => $this->status($scope, $t->to_code)->sort)
            ->first()?->to_code;
    }

    /**
     * Validate a transition. $user null = system call (allowed for 'system' transitions only).
     *
     * @return string[] required field names
     */
    public function assertTransition(string $scope, string $from, string $to, ?User $user, int $projectId, array $data = []): array
    {
        $t = $this->repo->transition($scope, $from, $to)
            ?? throw new BusinessException(sprintf('不允許從「%s」變更為「%s」。', $this->label($scope, $from), $this->label($scope, $to)));
        $perms = explode('|', $t->permission);
        $ok = $user === null
            ? in_array(self::SYSTEM, $perms, true)
            : collect($perms)->contains(fn ($p) => $this->can($user, $p, $projectId));
        if (! $ok) {
            throw new BusinessException('沒有權限執行此狀態變更。');
        }
        $req = $t->requires_fields ?? [];
        foreach ($req as $field) {
            if (! isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                throw new BusinessException("此狀態變更必須填寫：$field");
            }
        }

        return $req;
    }

    /** Field-level edit rule check (field_edit_rules). */
    public function canEditField(User $user, string $field, string $origin, string $stage, int $creatorId, int $projectId): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $this->repo->fieldRules($field)
            ->filter(fn ($r) => ($r->origin === $origin || $r->origin === '*') && ($r->stage === null || $r->stage === $stage))
            ->contains(fn ($r) => $this->can($user, $r->permission, $projectId) && ($r->actor_rule !== 'creator' || $user->id === $creatorId));
    }
}
