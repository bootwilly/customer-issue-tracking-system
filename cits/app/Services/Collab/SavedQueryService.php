<?php

namespace App\Services\Collab;

use App\Exceptions\BusinessException;
use App\Models\SavedQuery;
use App\Models\User;
use App\Repositories\SavedQueryRepository;
use App\Repositories\SettingRepository;
use App\Services\WorkflowService;
use Illuminate\Support\Collection;

/**
 * "My queries" on the list pages. Only the page's own query-string keys are stored; applying a query just opens the
 * page with those params, so the page's normal validation, permissions and "mine" (current user) still apply.
 */
class SavedQueryService
{
    /** scope => [route name, permission, internal side only, allowed query keys] */
    private const SCOPES = [
        'pm' => ['pm.index', 'issue.view', true, ['oa', 'tt', 'tq', 'stage', 'status', 'origin', 'q', 'sort', 'tags', 'ni']],
        'dev' => ['dev.index', 'issue.view', true, ['tab', 'f', 'mine', 'status', 'overdue', 'q']],
        'test' => ['test.index', 'issue.view', true, ['tab', 'mine', 'status', 'q']],
        'client' => ['tickets.index', 'ticket.view', false, ['tab', 'type', 'q', 'sort', 'dir', 'mine', 'ni']],
    ];

    private const VALUE_MAX = 100;

    public function __construct(
        private SavedQueryRepository $repo,
        private SettingRepository $settings,
        private WorkflowService $wf,
    ) {}

    public function canUse(User $u, int $projectId, string $scope): bool
    {
        if (! isset(self::SCOPES[$scope])) {
            return false;
        }
        [, $perm, $internal] = self::SCOPES[$scope];

        return (! $internal || $u->seesInternal()) && $this->wf->can($u, $perm, $projectId);
    }

    /** @return Collection<int, SavedQuery> */
    public function list(User $u, int $projectId, string $scope): Collection
    {
        return $this->canUse($u, $projectId, $scope) ? $this->repo->list($u->id, $projectId, $scope) : collect();
    }

    /** Whitelisted, length-checked params (arrays only of short scalars). */
    public function clean(string $scope, array $input): array
    {
        $keys = self::SCOPES[$scope][3] ?? [];
        $out = [];
        foreach ($keys as $k) {
            if (! array_key_exists($k, $input)) {
                continue;
            }
            $v = $input[$k];
            if (is_array($v)) {
                $v = array_values(array_filter(array_map(fn ($x) => is_scalar($x) ? mb_substr((string) $x, 0, self::VALUE_MAX) : null, $v), fn ($x) => $x !== null && $x !== ''));
                if ($v) {
                    $out[$k] = array_slice($v, 0, 50);
                }
            } elseif (is_scalar($v) && (string) $v !== '') {
                $out[$k] = mb_substr((string) $v, 0, self::VALUE_MAX);
            }
        }

        return $out;
    }

    public function save(User $u, int $projectId, string $scope, string $name, array $input): SavedQuery
    {
        $this->assertUse($u, $projectId, $scope);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new BusinessException('請填寫查詢名稱（最多 100 字）。');
        }
        $params = $this->clean($scope, $input);
        $existing = $this->repo->list($u->id, $projectId, $scope)->firstWhere('name', $name);
        if ($existing) {
            return $this->repo->save($existing, ['params' => $params]); // same name = update its filters
        }
        $max = max(1, $this->settings->int('saved_query.max_per_list', 20));
        if ($this->repo->count($u->id, $projectId, $scope) >= $max) {
            throw new BusinessException("每個清單最多 {$max} 個查詢，請先刪除不用的。");
        }

        return $this->repo->create([
            'user_id' => $u->id, 'project_id' => $projectId, 'scope' => $scope, 'name' => $name,
            'params' => $params, 'sort' => $this->repo->maxSort($u->id, $projectId, $scope) + 10,
        ]);
    }

    public function rename(User $u, int $projectId, int $id, string $name): void
    {
        $q = $this->own($u, $projectId, $id);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new BusinessException('請填寫查詢名稱（最多 100 字）。');
        }
        if ($this->repo->list($u->id, $projectId, $q->scope)->contains(fn ($o) => $o->id !== $q->id && $o->name === $name)) {
            throw new BusinessException('已經有同名的查詢。');
        }
        $this->repo->save($q, ['name' => $name]);
    }

    public function move(User $u, int $projectId, int $id, string $dir): void
    {
        $q = $this->own($u, $projectId, $id);
        $list = $this->repo->list($u->id, $projectId, $q->scope)->values();
        $idx = $list->search(fn ($o) => $o->id === $q->id);
        $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($swap < 0 || $swap >= $list->count()) {
            return;
        }
        foreach ($list as $n => $o) { // renumber, then swap the two neighbours
            $this->repo->save($o, ['sort' => ($n === $idx ? $swap : ($n === $swap ? $idx : $n)) * 10 + 10]);
        }
    }

    public function delete(User $u, int $projectId, int $id): string
    {
        $q = $this->own($u, $projectId, $id);
        $this->repo->delete($q);

        return $q->scope;
    }

    /** Page URL with the stored params. */
    public function url(SavedQuery $q): string
    {
        return route(self::SCOPES[$q->scope][0], $this->clean($q->scope, (array) $q->params));
    }

    public function routeOf(string $scope): string
    {
        return self::SCOPES[$scope][0] ?? throw new BusinessException('清單不存在。');
    }

    private function own(User $u, int $projectId, int $id): SavedQuery
    {
        $q = $this->repo->find($id);
        if (! $q || $q->user_id !== $u->id || $q->project_id !== $projectId) {
            throw new BusinessException('找不到這個查詢。');
        }

        return $q;
    }

    private function assertUse(User $u, int $projectId, string $scope): void
    {
        if (! $this->canUse($u, $projectId, $scope)) {
            throw new BusinessException('沒有權限使用這個清單。');
        }
    }
}
