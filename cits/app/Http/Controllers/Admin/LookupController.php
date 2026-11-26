<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\LookupAdminService;
use Illuminate\Http\Request;

/** 系統管理 → 選項管理 (admin middleware on the route group). */
class LookupController extends Controller
{
    public function __construct(private LookupAdminService $svc) {}

    public function index(Request $r, ?string $category = null)
    {
        $categories = $this->svc->categories();
        $category ??= array_key_first($categories);
        abort_unless($category !== null && array_key_exists($category, $categories), 404);

        return view('admin.lookups.index', [
            'categories' => $categories,
            'category' => $category,
            'items' => $this->svc->items($category),
        ]);
    }

    public function store(Request $r, string $category)
    {
        $d = $r->validate(['name' => ['required', 'string', 'max:100']], [], ['name' => '名稱']);

        return $this->run(fn () => $this->svc->create($category, $d['name']), '已新增。');
    }

    public function update(Request $r, string $category, int $id)
    {
        $d = $r->validate(['name' => ['required', 'string', 'max:100']], [], ['name' => '名稱']);

        return $this->run(fn () => $this->svc->rename($category, $id, $d['name']), '已改名。');
    }

    public function toggle(string $category, int $id)
    {
        return $this->run(fn () => $this->svc->toggle($category, $id), '已更新狀態。');
    }

    public function move(Request $r, string $category, int $id)
    {
        $d = $r->validate(['dir' => ['required', 'in:up,down']]);

        return $this->run(fn () => $this->svc->move($category, $id, $d['dir'] === 'up' ? -1 : 1), '已調整順序。');
    }

    public function destroy(string $category, int $id)
    {
        return $this->run(fn () => $this->svc->delete($category, $id), '已刪除。');
    }

    private function run(callable $fn, string $ok)
    {
        try {
            $fn();
        } catch (BusinessException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }

        return back()->with('ok', $ok);
    }
}
