<?php

namespace App\Services\Mes;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\MesPageRepository;
use App\Repositories\UnitOfWork;
use App\Services\WorkflowService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

class MesService
{
    public function __construct(
        private MesPageRepository $repo,
        private EventRepository $events,
        private WorkflowService $wf,
        private UnitOfWork $uow,
    ) {}

    public function canView(User $u, int $pid): bool
    {
        return $u->seesInternal() && $this->wf->can($u, 'issue.view', $pid);
    }

    public function canManage(User $u, int $pid): bool
    {
        return $u->seesInternal() && $this->wf->can($u, 'mes.manage', $pid);
    }

    /** Flattened tree: each node gets ->depth. Orphans are appended at the root level. */
    public function tree(int $pid): Collection
    {
        $all = $this->repo->all($pid);
        $byParent = $all->groupBy(fn ($n) => (string) $n->parent_id);
        $out = new Collection;
        $walk = function (?int $parent, int $depth) use (&$walk, $byParent, $out) {
            foreach ($byParent->get((string) $parent, []) as $n) {
                $n->depth = $depth;
                $out->push($n);
                $walk($n->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return $out;
    }

    public function folders(int $pid): Collection
    {
        return $this->tree($pid)->where('node_type', 'folder')->values();
    }

    /** @return array{0:string,1:string} [path, file] */
    private function normalizePath(string $path): array
    {
        $path = trim($path);
        if (! str_starts_with($path, '~/') || ! str_ends_with(strtolower($path), '.aspx') || strlen($path) <= 7) {
            throw new BusinessException('頁面路徑必須以 ~/ 開頭並以 .aspx 結尾。');
        }

        return [$path, basename($path)];
    }

    private function checkParent(int $pid, ?int $parentId, ?int $selfId = null): void
    {
        if (! $parentId) {
            return;
        }
        $parent = $this->repo->find($parentId);
        if (! $parent || $parent->project_id !== $pid || $parent->node_type !== 'folder') {
            throw new BusinessException('上層必須是同專案的資料夾。');
        }
        for ($n = $parent; $n && $selfId; $n = $n->parent_id ? $this->repo->find($n->parent_id) : null) {
            if ($n->id === $selfId) {
                throw new BusinessException('不能把節點移到自己底下。');
            }
        }
    }

    public function create(User $u, int $pid, array $d): int
    {
        $this->wf->authorize($u, 'mes.manage', $pid);
        $type = ($d['node_type'] ?? '') === 'folder' ? 'folder' : 'page';
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new BusinessException('請輸入名稱。');
        }
        $parent = ! empty($d['parent_id']) ? (int) $d['parent_id'] : null;
        $this->checkParent($pid, $parent);
        $path = $file = null;
        if ($type === 'page') {
            [$path, $file] = $this->normalizePath((string) ($d['page_path'] ?? ''));
            if ($this->repo->findByPath($pid, $path)) {
                throw new BusinessException('此頁面路徑已存在。');
            }
        }

        return $this->uow->run(function () use ($u, $pid, $type, $name, $parent, $path, $file, $d) {
            $n = $this->repo->create([
                'project_id' => $pid, 'parent_id' => $parent, 'node_type' => $type, 'name' => $name,
                'page_path' => $path, 'page_file' => $file, 'sort' => (int) ($d['sort'] ?? 0), 'is_active' => true,
            ]);
            $this->events->log($pid, 'mes', $n->id, 'mes_change', null, null, $u->id, ['action' => 'create', 'type' => $type, 'name' => $name, 'path' => $path]);

            return $n->id;
        });
    }

    public function update(User $u, int $pid, int $id, array $d): void
    {
        $this->wf->authorize($u, 'mes.manage', $pid);
        $n = $this->repo->find($id);
        if (! $n || $n->project_id !== $pid) {
            throw new BusinessException('找不到此節點。');
        }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new BusinessException('請輸入名稱。');
        }
        $parent = ! empty($d['parent_id']) ? (int) $d['parent_id'] : null;
        $this->checkParent($pid, $parent, $n->id);
        $data = ['name' => $name, 'parent_id' => $parent, 'sort' => (int) ($d['sort'] ?? 0)];
        if ($n->node_type === 'page') {
            [$path, $file] = $this->normalizePath((string) ($d['page_path'] ?? ''));
            $dup = $this->repo->findByPath($pid, $path);
            if ($dup && $dup->id !== $n->id) {
                throw new BusinessException('此頁面路徑已存在。');
            }
            $data += ['page_path' => $path, 'page_file' => $file];
        }
        $this->uow->run(function () use ($u, $pid, $n, $data) {
            $before = $n->only(array_keys($data));
            $this->repo->save($n, $data);
            $this->events->log($pid, 'mes', $n->id, 'mes_change', null, null, $u->id, ['action' => 'update', 'before' => $before, 'after' => $data]);
        });
    }

    public function toggle(User $u, int $pid, int $id): bool
    {
        $this->wf->authorize($u, 'mes.manage', $pid);
        $n = $this->repo->find($id);
        if (! $n || $n->project_id !== $pid) {
            throw new BusinessException('找不到此節點。');
        }

        return $this->uow->run(function () use ($u, $pid, $n) {
            $this->repo->save($n, ['is_active' => ! $n->is_active]);
            $this->events->log($pid, 'mes', $n->id, 'mes_change', null, null, $u->id, ['action' => $n->is_active ? 'activate' : 'deactivate', 'name' => $n->name]);

            return $n->is_active;
        });
    }

    /**
     * Dry-run of a bulk import.
     *
     * @return array<int, array{path:string,status:string,folders:string[],note:string}>
     */
    public function previewImport(User $u, int $pid, string $text): array
    {
        $this->wf->authorize($u, 'mes.manage', $pid);

        return $this->plan($pid, $text, false, $u);
    }

    public function applyImport(User $u, int $pid, string $text): int
    {
        $this->wf->authorize($u, 'mes.manage', $pid);

        return $this->uow->run(function () use ($pid, $text, $u) {
            $plan = $this->plan($pid, $text, true, $u);

            return collect($plan)->where('status', 'new')->count();
        });
    }

    /**
     * Dry-run of a Web.sitemap import. Nothing is written.
     *
     * @return array{rows:array,counts:array,payload:string}
     */
    public function previewSitemap(User $u, int $pid, string $xml): array
    {
        $this->wf->authorize($u, 'mes.manage', $pid);
        $nodes = (new SitemapParser)->parse($xml);
        $res = $this->planSitemap($pid, $nodes, false, $u);
        $res['payload'] = Crypt::encryptString(json_encode(['pid' => $pid, 'uid' => $u->id, 'nodes' => $nodes], JSON_UNESCAPED_UNICODE));

        return $res;
    }

    /** Apply a payload produced by previewSitemap (tamper-safe: encrypted, bound to project and user). */
    public function applySitemap(User $u, int $pid, string $payload): array
    {
        $this->wf->authorize($u, 'mes.manage', $pid);
        try {
            $data = json_decode(Crypt::decryptString($payload), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new BusinessException('匯入資料無效或已被竄改，請重新預覽。');
        }
        if (($data['pid'] ?? null) !== $pid || ($data['uid'] ?? null) !== $u->id || ! is_array($data['nodes'] ?? null)) {
            throw new BusinessException('匯入資料與目前專案或使用者不符，請重新預覽。');
        }

        return $this->uow->run(fn () => $this->planSitemap($pid, $data['nodes'], true, $u))['counts'];
    }

    /**
     * Match against existing nodes and (optionally) write. Never deactivates or deletes anything.
     *
     * @return array{rows:array,counts:array}
     */
    private function planSitemap(int $pid, array $nodes, bool $apply, User $u): array
    {
        $all = $this->repo->all($pid);
        $folders = [];
        foreach ($all->where('node_type', 'folder') as $f) {
            $folders[(string) $f->parent_id.'|'.mb_strtolower($f->name)] ??= $f;
        }
        $pages = [];
        foreach ($all->where('node_type', 'page') as $p) {
            $pages[mb_strtolower((string) $p->page_path)] = $p;
        }

        $map = [];      // node index => resolved id (existing, created, or negative placeholder in dry-run)
        $labels = [];   // node index => display name for parent column
        $rows = [];
        $counts = ['new' => 0, 'update' => 0, 'same' => 0, 'invalid' => 0];
        $placeholder = 0;
        $seenPages = [];

        $push = function (array $n, string $status, string $note = '') use (&$rows, &$counts) {
            $counts[$status]++;
            $rows[] = ['kind' => $n['type'], 'status' => $status, 'name' => $n['name'], 'path' => (string) ($n['path'] ?? $n['raw'] ?? ''), 'depth' => $n['depth'] ?? 0, 'note' => $note];
        };

        foreach ($nodes as $i => $n) {
            $parentId = isset($n['parent']) ? ($map[$n['parent']] ?? null) : null;
            $parentLabel = isset($n['parent']) ? ($labels[$n['parent']] ?? '') : '';

            if ($n['name'] === '') {
                $map[$i] = $parentId;
                $labels[$i] = $parentLabel;
                $push($n, 'invalid', '缺少名稱');

                continue;
            }

            if ($n['type'] === 'folder') {
                $key = (string) $parentId.'|'.mb_strtolower($n['name']);
                if (isset($folders[$key])) {
                    $f = $folders[$key];
                    $map[$i] = is_object($f) ? $f->id : $f;
                    $push($n, 'same');
                } else {
                    if ($apply) {
                        $f = $this->repo->create(['project_id' => $pid, 'parent_id' => $parentId, 'node_type' => 'folder', 'name' => $n['name'], 'sort' => (int) $n['sort'], 'is_active' => true]);
                        $this->events->log($pid, 'mes', $f->id, 'mes_change', null, null, $u->id, ['action' => 'sitemap_folder', 'name' => $n['name']]);
                        $map[$i] = $f->id;
                    } else {
                        $map[$i] = --$placeholder;
                    }
                    $folders[$key] = $map[$i];
                    $push($n, 'new');
                }
                $labels[$i] = $n['name'];

                continue;
            }

            // page
            $map[$i] = $parentId;
            $labels[$i] = $parentLabel;
            if (! $n['path']) {
                $push($n, 'invalid', '不是有效的 .aspx 路徑');

                continue;
            }
            $pk = mb_strtolower($n['path']);
            if (isset($seenPages[$pk])) {
                $push($n, 'invalid', '路徑在 sitemap 內重複');

                continue;
            }
            $seenPages[$pk] = true;
            $file = basename($n['path']);

            if (isset($pages[$pk])) {
                $p = $pages[$pk];
                $changes = [];
                if ($p->name !== $n['name']) {
                    $changes['name'] = $n['name'];
                }
                if ($p->parent_id !== $parentId) {
                    $changes['parent_id'] = $parentId;
                }
                if (! $changes) {
                    $push($n, 'same');

                    continue;
                }
                $note = collect(['name' => '名稱：'.$p->name.' → '.$n['name'], 'parent_id' => '上層變更'])->only(array_keys($changes))->implode('；');
                if ($apply) {
                    $before = $p->only(array_keys($changes));
                    $this->repo->save($p, $changes);
                    $this->events->log($pid, 'mes', $p->id, 'mes_change', null, null, $u->id, ['action' => 'sitemap_update', 'before' => $before, 'after' => $changes]);
                }
                $push($n, 'update', $note);

                continue;
            }

            if ($apply) {
                $p = $this->repo->create([
                    'project_id' => $pid, 'parent_id' => $parentId, 'node_type' => 'page', 'name' => $n['name'],
                    'page_path' => $n['path'], 'page_file' => $file, 'sort' => (int) $n['sort'], 'is_active' => true,
                ]);
                $this->events->log($pid, 'mes', $p->id, 'mes_change', null, null, $u->id, ['action' => 'sitemap_page', 'path' => $n['path']]);
                $pages[$pk] = $p;
            }
            $push($n, 'new');
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    private function plan(int $pid, string $text, bool $apply, User $u): array
    {
        $all = $this->repo->all($pid);
        $folders = [];
        foreach ($all->where('node_type', 'folder') as $f) {
            $folders[(string) $f->parent_id.'|'.mb_strtolower($f->name)] = $f->id;
        }
        $paths = $all->where('node_type', 'page')->pluck('page_path')->map(fn ($p) => mb_strtolower((string) $p))->flip()->all();

        $rows = [];
        $seen = [];
        $lines = preg_split('/\r\n|\r|\n/', $text);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || isset($seen[mb_strtolower($line)])) {
                continue;
            }
            $seen[mb_strtolower($line)] = true;
            try {
                [$path, $file] = $this->normalizePath($line);
            } catch (BusinessException $e) {
                $rows[] = ['path' => $line, 'status' => 'invalid', 'folders' => [], 'note' => $e->getMessage()];

                continue;
            }
            if (isset($paths[mb_strtolower($path)])) {
                $rows[] = ['path' => $path, 'status' => 'exists', 'folders' => [], 'note' => ''];

                continue;
            }
            $segments = array_values(array_filter(explode('/', substr($path, 2)), fn ($s) => $s !== ''));
            array_pop($segments);
            $parent = null;
            $created = [];
            foreach ($segments as $seg) {
                $key = (string) $parent.'|'.mb_strtolower($seg);
                if (! isset($folders[$key])) {
                    $created[] = $seg;
                    if ($apply) {
                        $f = $this->repo->create(['project_id' => $pid, 'parent_id' => $parent, 'node_type' => 'folder', 'name' => $seg, 'sort' => 0, 'is_active' => true]);
                        $this->events->log($pid, 'mes', $f->id, 'mes_change', null, null, $u->id, ['action' => 'import_folder', 'name' => $seg]);
                        $folders[$key] = $f->id;
                    } else {
                        $folders[$key] = -count($folders) - 1; // placeholder so later lines see the folder as planned
                    }
                }
                $parent = $folders[$key];
            }
            if ($apply) {
                $p = $this->repo->create([
                    'project_id' => $pid, 'parent_id' => $parent, 'node_type' => 'page',
                    'name' => pathinfo($file, PATHINFO_FILENAME), 'page_path' => $path, 'page_file' => $file, 'sort' => 0, 'is_active' => true,
                ]);
                $this->events->log($pid, 'mes', $p->id, 'mes_change', null, null, $u->id, ['action' => 'import_page', 'path' => $path]);
            }
            $paths[mb_strtolower($path)] = true;
            $rows[] = ['path' => $path, 'status' => 'new', 'folders' => $created, 'note' => ''];
        }

        return $rows;
    }
}
