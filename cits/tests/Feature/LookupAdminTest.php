<?php

use App\Models\Issue;
use App\Models\LookupItem;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->withoutVite();
    $this->admin = User::create(['account' => 'lkadm', 'name' => 'adm', 'email' => 'lkadm@t.test', 'password' => '123456', 'user_type' => 'internal', 'is_admin' => true, 'status' => 'active']);
    $this->p = Project::create(['code' => 'LK', 'name' => 'LK', 'ticket_prefix' => 'LK']);
    $this->pm = User::create(['account' => 'lkpm', 'name' => 'pm', 'email' => 'lkpm@t.test', 'password' => '123456', 'user_type' => 'internal', 'status' => 'active']);
    $this->p->members()->attach($this->pm->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->p->id);
    $this->pm->assignRole('internal_pm');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->issue = Issue::create(['project_id' => $this->p->id, 'issue_no' => 'LK-1', 'origin' => 'pm', 'title' => 'x', 'content_html' => '<p>x</p>',
        'stage' => 'PM', 'status' => 'new', 'process_type' => 'bug', 'created_by' => $this->pm->id]);
    $this->item = fn (string $code) => LookupItem::where(['category' => 'process_type', 'code' => $code])->first();
});

it('is only for system admins and only for editable categories', function () {
    $this->actingAs($this->pm)->withSession(['project_id' => $this->p->id])->get(route('admin.lookups.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.lookups.index'))->assertOk()->assertSee('處理類型')->assertSee('BUG修正')->assertSee('1 筆');
    $this->actingAs($this->admin)->get(route('admin.lookups.index', 'issue_initial'))->assertNotFound();
    $this->actingAs($this->admin)->post(route('admin.lookups.store', 'issue_initial'), ['name' => 'x'])->assertSessionHas('err');
});

it('adds, renames, reorders, disables and deletes items', function () {
    $this->actingAs($this->admin)->post(route('admin.lookups.store', 'process_type'), ['name' => ' 效能  調整 '])->assertSessionHas('ok');
    $new = LookupItem::where(['category' => 'process_type', 'name' => '效能 調整'])->first();
    expect($new)->not->toBeNull()->and($new->code)->toStartWith('c')->and($new->is_active)->toBeTrue()
        ->and($new->sort)->toBeGreaterThan(($this->item)('feature')->sort);
    $this->actingAs($this->admin)->post(route('admin.lookups.store', 'process_type'), ['name' => '效能 調整'])->assertSessionHas('err');

    $this->actingAs($this->admin)->put(route('admin.lookups.update', ['process_type', $new->id]), ['name' => '效能優化'])->assertSessionHas('ok');
    $this->actingAs($this->admin)->post(route('admin.lookups.move', ['process_type', $new->id]), ['dir' => 'up'])->assertSessionHas('ok');
    expect(LookupItem::where('category', 'process_type')->orderBy('sort')->pluck('name')->all())->toBe(['UI修改', 'BUG修正', '效能優化', '功能新增']);

    // used items cannot be deleted, only disabled; unused can
    $this->actingAs($this->admin)->delete(route('admin.lookups.destroy', ['process_type', ($this->item)('bug')->id]))->assertSessionHas('err');
    $this->actingAs($this->admin)->delete(route('admin.lookups.destroy', ['process_type', $new->id]))->assertSessionHas('ok');
    expect(LookupItem::find($new->id))->toBeNull();

    $this->actingAs($this->admin)->post(route('admin.lookups.toggle', ['process_type', ($this->item)('bug')->id]))->assertSessionHas('ok');
    expect(($this->item)('bug')->is_active)->toBeFalse();
});

it('keeps at least one selectable item', function () {
    foreach (['ui_change', 'bug'] as $c) {
        $this->actingAs($this->admin)->post(route('admin.lookups.toggle', ['process_type', ($this->item)($c)->id]));
    }
    $this->actingAs($this->admin)->post(route('admin.lookups.toggle', ['process_type', ($this->item)('feature')->id]))->assertSessionHas('err');
    expect(($this->item)('feature')->is_active)->toBeTrue();
});

it('hides disabled types from pickers but keeps them on existing issues', function () {
    ($this->item)('bug')->update(['is_active' => false]);
    $as = fn () => $this->actingAs($this->pm)->withSession(['project_id' => $this->p->id]);

    $as()->get(route('pm.issues.create'))->assertOk()->assertDontSee('BUG修正');
    $as()->get(route('issues.show', $this->issue->id))->assertOk()->assertSee('BUG修正（已停用）');

    // saving other fields keeps the disabled value; picking a disabled value anew is refused
    $as()->post(route('issues.fields', $this->issue->id), ['process_type' => 'bug', 'title' => '新標題'])->assertSessionHas('ok');
    expect($this->issue->fresh()->process_type)->toBe('bug')->and($this->issue->fresh()->title)->toBe('新標題');
    $as()->post(route('issues.fields', $this->issue->id), ['process_type' => 'feature'])->assertSessionHas('ok');
    $as()->post(route('issues.fields', $this->issue->id), ['process_type' => 'bug'])->assertSessionHas('err');
    $as()->post(route('issues.fields', $this->issue->id), ['process_type' => 'nonsense'])->assertSessionHas('err');
    expect($this->issue->fresh()->process_type)->toBe('feature');

    expect(app(\App\Services\WorkflowService::class)->lookupMapAll('process_type'))->toHaveKey('bug');
});
