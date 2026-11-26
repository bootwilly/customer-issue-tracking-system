<?php

use App\Models\ActivityEvent;
use App\Models\Issue;
use App\Models\Meeting;
use App\Models\MeetingComment;
use App\Models\MeetingItem;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

function mtgUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@t.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

beforeEach(function () {
    $this->withoutVite();
    $this->p = Project::create(['code' => 'MT', 'name' => 'MT', 'ticket_prefix' => 'MT']);
    $this->pm = mtgUser('mtpm', 'internal', ['internal_pm'], $this->p);
    $this->dev = mtgUser('mtdev', 'internal', ['dev'], $this->p);
    $this->dev2 = mtgUser('mtdev2', 'internal', ['dev'], $this->p);
    $this->cpm = mtgUser('mtcpm', 'client', ['client_pm'], $this->p);
    $this->cpm2 = mtgUser('mtcpm2', 'client', ['client_pm'], $this->p);
    $this->cview = mtgUser('mtcview', 'client', ['client_view'], $this->p);
    $this->padm = mtgUser('mtpadm', 'internal', ['project_admin'], $this->p);
    $this->as = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
    $this->make = function (User $u, array $in = []) {
        ($this->as)($u)->post(route('meetings.store'), $in + ['type' => 'regular', 'title' => '週會', 'start_at' => '2026-12-01 10:00', 'mode' => 'scheduled'])
            ->assertRedirect();

        return Meeting::latest('id')->first();
    };
    $this->item = function (User $u, Meeting $m, array $in) {
        return ($this->as)($u)->postJson(route('meetings.items.store', $m->id), $in);
    };
});

it('creates scheduled or started meetings with attendees and topics', function () {
    $m = ($this->make)($this->dev, ['attendee_ids' => [$this->pm->id, 999], 'external_attendees' => "王小明／ABC\n\n王小明／ABC", 'topics' => "入庫異常\n報表速度"]);
    expect($m->status)->toBe('scheduled')->and($m->customer_visible)->toBeFalse()
        ->and($m->start_at->format('Y-m-d H:i'))->toBe('2026-12-01 02:00') // stored UTC
        ->and($m->attendees()->pluck('user_id')->filter()->sort()->values()->all())->toBe(collect([$this->pm->id, $this->dev->id])->sort()->values()->all())
        ->and($m->attendees()->whereNull('user_id')->count())->toBe(1)
        ->and($m->items()->pluck('topic')->all())->toBe(['入庫異常', '報表速度']);

    ($this->as)($this->pm)->post(route('meetings.store'), ['type' => 'review', 'title' => '臨時', 'mode' => 'now'])->assertRedirect();
    expect(Meeting::latest('id')->first()->status)->toBe('recording');

    ($this->as)($this->cview)->get(route('meetings.create'))->assertForbidden();
    ($this->as)($this->pm)->post(route('meetings.store'), ['type' => 'nope', 'title' => 'x', 'start_at' => '2026-12-01 10:00'])->assertSessionHas('err');
});

it('hides internal meetings from clients and holds shared content until published', function () {
    $m = ($this->make)($this->dev, ['topics' => '問題A']);
    ($this->item)($this->dev, $m, ['topic' => '問題B'])->assertOk();
    MeetingItem::where('meeting_id', $m->id)->update(['discussion' => '內部討論細節', 'conclusion' => '改程式']);
    $m->update(['internal_html' => '<p>報價底線</p>', 'notes_html' => '<p>對外紀錄</p>']);

    ($this->as)($this->cpm)->get(route('meetings.show', $m->id))->assertNotFound();
    ($this->as)($this->cpm)->get(route('meetings.index', ['tab' => 'all']))->assertDontSee('週會');

    ($this->as)($this->dev)->post(route('meetings.share', $m->id), ['visible' => 1])->assertSessionHas('ok');
    ($this->as)($this->cpm)->get(route('meetings.show', $m->id))->assertOk()
        ->assertSee('問題A')->assertDontSee('內部討論細節')->assertDontSee('改程式')->assertDontSee('對外紀錄')->assertDontSee('報價底線');

    ($this->as)($this->dev)->post(route('meetings.status', $m->id), ['to' => 'recording'])->assertSessionHas('ok');
    ($this->as)($this->dev)->post(route('meetings.status', $m->id), ['to' => 'done'])->assertSessionHas('ok');
    expect($m->fresh()->published_at)->not->toBeNull();
    ($this->as)($this->cpm)->get(route('meetings.show', $m->id))->assertOk()
        ->assertSee('內部討論細節')->assertSee('改程式')->assertSee('對外紀錄')->assertDontSee('報價底線');

    // un-share: gone for clients again, logged
    ($this->as)($this->dev)->post(route('meetings.share', $m->id), ['visible' => 0])->assertSessionHas('ok');
    ($this->as)($this->cpm)->get(route('meetings.show', $m->id))->assertNotFound();
    expect(ActivityEvent::where(['subject_type' => 'meeting', 'subject_id' => $m->id, 'event_type' => 'meeting_unshared'])->exists())->toBeTrue();

    // clients cannot share
    ($this->as)($this->cpm)->post(route('meetings.share', $m->id), ['visible' => 1])->assertSessionHas('err');
    expect($m->fresh()->customer_visible)->toBeFalse();
});

it('shows client-created meetings to every client and internal user right away', function () {
    $m = ($this->make)($this->cpm, ['topics' => '畫面當機', 'customer_visible' => 0]);
    expect($m->customer_visible)->toBeTrue();
    MeetingItem::where('meeting_id', $m->id)->update(['conclusion' => '下週修正']);

    ($this->as)($this->cpm2)->get(route('meetings.show', $m->id))->assertOk()->assertSee('下週修正');
    ($this->as)($this->cview)->get(route('meetings.show', $m->id))->assertOk()->assertSee('下週修正');
    ($this->as)($this->dev)->get(route('meetings.show', $m->id))->assertOk()->assertSee('客戶發起');
    ($this->as)($this->dev)->post(route('meetings.share', $m->id), ['visible' => 0])->assertSessionHas('err');
});

it('limits editing to creator, attendees and managers', function () {
    $m = ($this->make)($this->dev, ['customer_visible' => 1]);
    ($this->item)($this->dev2, $m, ['topic' => 'x'])->assertStatus(422);       // internal non-attendee
    ($this->item)($this->pm, $m, ['topic' => 'pm 可以'])->assertOk();          // meeting.manage
    ($this->item)($this->cpm, $m, ['topic' => '客戶'])->assertStatus(422);     // client on internal meeting
    ($this->as)($this->dev2)->get(route('meetings.edit', $m->id))->assertForbidden();

    $c = ($this->make)($this->cpm, ['attendee_ids' => [$this->dev2->id]]);
    ($this->item)($this->dev2, $c, ['topic' => '內部與會者可編'])->assertOk(); // attendee of client meeting
    ($this->item)($this->cpm2, $c, ['topic' => 'x'])->assertStatus(422);       // client non-attendee
    ($this->item)($this->cview, $c, ['topic' => 'x'])->assertStatus(422);      // read-only role

    // cancel: creator or manager only; cancelled = read-only
    ($this->as)($this->dev2)->post(route('meetings.status', $c->id), ['to' => 'cancelled'])->assertSessionHas('err');
    ($this->as)($this->cpm)->post(route('meetings.status', $c->id), ['to' => 'cancelled'])->assertSessionHas('ok');
    ($this->item)($this->cpm, $c, ['topic' => 'x'])->assertStatus(422);
    ($this->as)($this->cpm)->post(route('meetings.status', $c->id), ['to' => 'scheduled'])->assertSessionHas('ok');
});

it('autosaves items, links issues for internal users and reorders', function () {
    $issue = Issue::create(['project_id' => $this->p->id, 'issue_no' => 'MT-7', 'origin' => 'pm', 'title' => '庫存不準', 'content_html' => '<p>x</p>',
        'stage' => 'PM', 'status' => 'new', 'created_by' => $this->pm->id]);
    $m = ($this->make)($this->pm);
    $a = ($this->item)($this->pm, $m, ['topic' => '第一'])->assertOk()->json('item');
    $b = ($this->item)($this->pm, $m, ['topic' => '第二'])->assertOk()->json('item');

    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $a['id']]), ['conclusion' => '先查 log', 'owner_id' => $this->cpm->id, 'due_date' => '2026-12-05', 'issue_no' => 'mt-7'])
        ->assertOk()->assertJsonPath('item.issue_no', 'MT-7')->assertJsonPath('item.conclusion', '先查 log');
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $a['id']]), ['issue_no' => 'MT-404'])->assertStatus(422);
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $a['id']]), ['owner_id' => 99999])->assertStatus(422);
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $a['id']]), ['status' => 'bogus'])->assertStatus(422);
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $a['id']]), ['topic' => ''])->assertStatus(422);

    ($this->as)($this->pm)->postJson(route('meetings.items.move', [$m->id, $b['id']]), ['dir' => 'up'])->assertOk();
    expect($m->items()->pluck('topic')->all())->toBe(['第二', '第一']);

    // issue page lists it; client never sees the issue link
    ($this->as)($this->pm)->get(route('issues.show', $issue->id))->assertOk()->assertSee('先查 log');
    $c = ($this->make)($this->cpm);
    $ci = ($this->item)($this->cpm, $c, ['topic' => '客戶項', 'issue_no' => 'MT-7'])->assertOk()->json('item');
    expect($ci['issue_no'])->toBeNull();

    ($this->as)($this->pm)->deleteJson(route('meetings.items.destroy', [$m->id, $b['id']]))->assertOk();
    expect(MeetingItem::find($b['id']))->toBeNull();

    // "排入會議" from the issue page: already on this meeting -> refused, no duplicate
    ($this->as)($this->pm)->post(route('meetings.add-issue'), ['meeting_id' => $m->id, 'issue_id' => $issue->id])->assertSessionHas('err');
    expect($m->items()->where('issue_id', $issue->id)->count())->toBe(1);
});

it('puts ticked issues from the 問題管理 list on a meeting or a new one', function () {
    $mk = fn (string $no) => Issue::create(['project_id' => $this->p->id, 'issue_no' => $no, 'origin' => 'pm', 'title' => "標題{$no}",
        'content_html' => '<p>x</p>', 'stage' => 'PM', 'status' => 'new', 'created_by' => $this->pm->id]);
    [$a, $b] = [$mk('MT-21'), $mk('MT-22')];
    $m = ($this->make)($this->pm, ['title' => '檢討會']);

    ($this->as)($this->pm)->get(route('pm.index'))->assertOk()->assertSee('排入會議')->assertSee('檢討會')->assertSee('＋ 新會議…');
    ($this->as)($this->pm)->post(route('meetings.add-issues'), ['meeting_id' => $m->id, 'issue_ids' => [$a->id, $b->id]])
        ->assertRedirect(route('meetings.show', $m->id).'#items');
    expect($m->items()->pluck('issue_id')->all())->toBe([$a->id, $b->id]);
    ($this->as)($this->pm)->post(route('meetings.add-issues'), ['meeting_id' => $m->id, 'issue_ids' => [$a->id]])->assertSessionHas('ok', '勾選的問題單都已在這場會議的討論事項中。');
    ($this->as)($this->pm)->post(route('meetings.add-issues'), ['meeting_id' => $m->id])->assertSessionHasErrors('issue_ids');

    // new meeting: form lists them, creating links them ahead of typed topics
    ($this->as)($this->pm)->post(route('meetings.add-issues'), ['meeting_id' => 'new', 'issue_ids' => [$b->id, $a->id]])
        ->assertRedirect(route('meetings.create', ['issue_ids' => [$b->id, $a->id]]));
    ($this->as)($this->pm)->get(route('meetings.create', ['issue_ids' => [$b->id, $a->id]]))->assertOk()->assertSee('標題MT-22')->assertSee('從問題管理帶入');
    ($this->as)($this->pm)->post(route('meetings.store'), ['type' => 'review', 'title' => '新檢討', 'start_at' => '2026-12-02 14:00', 'issue_ids' => [$b->id, $a->id], 'topics' => '其他'])->assertRedirect();
    $n = Meeting::latest('id')->first();
    expect($n->items()->pluck('topic')->all())->toBe(['MT-22 標題MT-22', 'MT-21 標題MT-21', '其他']);

    // clients never carry issues in
    ($this->as)($this->cpm)->post(route('meetings.store'), ['type' => 'review', 'title' => '客戶會', 'start_at' => '2026-12-02 14:00', 'issue_ids' => [$a->id]]);
    expect(Meeting::latest('id')->first()->items()->count())->toBe(0);
});

it('picks issues from the issue list into internal meetings', function () {
    $mk = fn (string $no, string $title, string $status, string $stage) => Issue::create(['project_id' => $this->p->id, 'issue_no' => $no, 'origin' => 'pm',
        'title' => $title, 'content_html' => '<p>x</p>', 'stage' => $stage, 'status' => $status, 'created_by' => $this->pm->id]);
    $a = $mk('MT-1', '入庫掃描失敗', 'new', 'PM');
    $b = $mk('MT-2', '報表太慢', 'dev_doing', 'DEV');
    $c = $mk('MT-3', '舊單已上線', 'released', 'RELEASE');
    $other = Project::create(['code' => 'OT', 'name' => 'OT', 'ticket_prefix' => 'OT']);
    $x = Issue::create(['project_id' => $other->id, 'issue_no' => 'OT-1', 'origin' => 'pm', 'title' => '別專案', 'content_html' => '<p>x</p>',
        'stage' => 'PM', 'status' => 'new', 'created_by' => $this->pm->id]);
    $m = ($this->make)($this->dev);
    $search = fn (User $u, array $q = []) => ($this->as)($u)->getJson(route('meetings.issues.search', [$m->id] + $q));

    // default: open issues only, this project only
    expect(collect($search($this->dev)->assertOk()->json('issues'))->pluck('issue_no')->sort()->values()->all())->toBe(['MT-1', 'MT-2']);
    expect(collect($search($this->dev, ['open' => 0])->json('issues'))->pluck('issue_no')->sort()->values()->all())->toBe(['MT-1', 'MT-2', 'MT-3']);
    expect(collect($search($this->dev, ['stage' => 'DEV'])->json('issues'))->pluck('issue_no')->all())->toBe(['MT-2']);
    expect(collect($search($this->dev, ['q' => '掃描'])->json('issues'))->pluck('issue_no')->all())->toBe(['MT-1']);

    ($this->as)($this->dev)->postJson(route('meetings.items.issues', $m->id), ['issue_ids' => [$a->id, $b->id, $x->id]])->assertOk()->assertJsonPath('added', 2);
    expect($m->items()->pluck('topic')->all())->toBe(['MT-1 入庫掃描失敗', 'MT-2 報表太慢'])
        ->and($m->items()->pluck('issue_id')->all())->toBe([$a->id, $b->id]);
    expect(collect($search($this->dev)->json('issues'))->firstWhere('issue_no', 'MT-1')['linked'])->toBeTrue();
    // already linked ones are skipped
    ($this->as)($this->dev)->postJson(route('meetings.items.issues', $m->id), ['issue_ids' => [$a->id, $c->id]])->assertJsonPath('added', 1);

    // clients and non-editors cannot pick
    $c2 = ($this->make)($this->cpm, ['attendee_ids' => [$this->dev->id]]);
    ($this->as)($this->cpm)->getJson(route('meetings.issues.search', $c2->id))->assertStatus(422);
    ($this->as)($this->cpm)->postJson(route('meetings.items.issues', $c2->id), ['issue_ids' => [$a->id]])->assertStatus(422);
    ($this->as)($this->dev)->postJson(route('meetings.items.issues', $c2->id), ['issue_ids' => [$a->id]])->assertJsonPath('added', 1); // internal attendee
    ($this->as)($this->dev2)->getJson(route('meetings.issues.search', $m->id))->assertStatus(422);
    ($this->as)($this->dev)->get(route('meetings.show', $m->id))->assertSee('從問題管理加入');
    ($this->as)($this->cpm)->get(route('meetings.show', $c2->id))->assertDontSee('從問題管理加入');
});

it('schedules the next meeting carrying open items', function () {
    $m = ($this->make)($this->pm, ['attendee_ids' => [$this->dev->id], 'external_attendees' => '外賓', 'topics' => "未完\n已完", 'customer_visible' => 1, 'end_at' => '2026-12-01 11:30']);
    $done = $m->items()->where('topic', '已完')->first();
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $done->id]), ['status' => 'done'])->assertOk();

    ($this->as)($this->pm)->post(route('meetings.next', $m->id), ['start_at' => '2026-12-08 10:00'])->assertRedirect();
    $n = Meeting::latest('id')->first();
    expect($n->prev_meeting_id)->toBe($m->id)->and($n->customer_visible)->toBeTrue()->and($n->status)->toBe('scheduled')
        ->and($n->end_at->diffInMinutes($n->start_at, true))->toEqual(90)
        ->and($n->items()->pluck('topic')->all())->toBe(['未完'])
        ->and($n->items()->first()->carried_from_id)->toBe($m->items()->where('topic', '未完')->first()->id)
        ->and($n->attendees()->count())->toBe(3);
});

it('handles comments with internal threads, edits and soft deletes', function () {
    $m = ($this->make)($this->pm, ['customer_visible' => 1]);
    ($this->as)($this->cpm)->post(route('meetings.comments.store', $m->id), ['body' => '客戶提問'])->assertRedirect();
    ($this->as)($this->pm)->post(route('meetings.comments.store', $m->id), ['body' => '內部討論', 'is_internal' => 1])->assertRedirect();
    $internal = MeetingComment::where('body', '內部討論')->first();
    ($this->as)($this->dev)->post(route('meetings.comments.store', $m->id), ['body' => '內部回覆', 'parent_id' => $internal->id])->assertRedirect();
    expect(MeetingComment::where('body', '內部回覆')->first()->is_internal)->toBeTrue();
    // client: cannot reply to / see internal, cannot flag internal
    ($this->as)($this->cpm)->post(route('meetings.comments.store', $m->id), ['body' => 'x', 'parent_id' => $internal->id])->assertSessionHas('err');
    ($this->as)($this->cpm)->post(route('meetings.comments.store', $m->id), ['body' => '偷標內部', 'is_internal' => 1]);
    expect(MeetingComment::where('body', '偷標內部')->first()->is_internal)->toBeFalse();
    ($this->as)($this->cpm2)->get(route('meetings.show', $m->id))->assertSee('客戶提問')->assertDontSee('內部討論')->assertDontSee('內部回覆');
    ($this->as)($this->cview)->post(route('meetings.comments.store', $m->id), ['body' => '唯讀'])->assertSessionHas('err');

    // unread badge for others
    ($this->as)($this->dev)->get(route('meetings.index', ['tab' => 'all']))->assertSee('則新留言');
    ($this->as)($this->dev)->get(route('meetings.show', $m->id));
    ($this->as)($this->dev)->get(route('meetings.index', ['tab' => 'all']))->assertDontSee('則新留言');

    $own = MeetingComment::where('body', '客戶提問')->first();
    ($this->as)($this->cpm2)->put(route('meetings.comments.update', [$m->id, $own->id]), ['body' => '改別人的'])->assertSessionHas('err');
    ($this->as)($this->cpm)->put(route('meetings.comments.update', [$m->id, $own->id]), ['body' => '客戶提問（修正）'])->assertSessionHas('ok');
    expect($own->fresh()->edited_at)->not->toBeNull();
    ($this->as)($this->cpm)->delete(route('meetings.comments.destroy', [$m->id, $own->id]))->assertSessionHas('ok');
    ($this->as)($this->cpm2)->get(route('meetings.show', $m->id))->assertSee('此留言已刪除')->assertDontSee('客戶提問（修正）');
    ($this->as)($this->padm)->get(route('meetings.show', $m->id))->assertSee('客戶提問（修正）');
});

it('lists my open items, exports ics and goes through the trash', function () {
    $m = ($this->make)($this->pm, ['topics' => '給客戶的事', 'customer_visible' => 1]);
    $i = $m->items()->first();
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $i->id]), ['owner_id' => $this->cpm->id])->assertOk();
    // not published yet: client does not see it under "mine"
    ($this->as)($this->cpm)->get(route('meetings.index', ['tab' => 'mine']))->assertDontSee('給客戶的事');
    ($this->as)($this->pm)->post(route('meetings.status', $m->id), ['to' => 'done']);
    ($this->as)($this->cpm)->get(route('meetings.index', ['tab' => 'mine']))->assertSee('給客戶的事');

    $ics = ($this->as)($this->cpm)->get(route('meetings.ics', $m->id))->assertOk();
    expect($ics->headers->get('content-type'))->toContain('text/calendar')
        ->and($ics->streamedContent())->toContain('DTSTART:20261201T020000Z')->toContain('SUMMARY:週會');

    ($this->as)($this->pm)->delete(route('meetings.destroy', $m->id))->assertForbidden();
    ($this->as)($this->padm)->delete(route('meetings.destroy', $m->id))->assertRedirect(route('meetings.index'));
    ($this->as)($this->pm)->get(route('meetings.show', $m->id))->assertNotFound();
    ($this->as)($this->padm)->get(route('padmin.trash'))->assertOk()->assertSee('週會');
    ($this->as)($this->padm)->post(route('padmin.trash.meetings.restore', $m->id))->assertSessionHas('ok');
    ($this->as)($this->padm)->delete(route('meetings.destroy', $m->id));
    ($this->as)($this->padm)->delete(route('padmin.trash.meetings.purge', $m->id), ['confirm_title' => '錯'])->assertSessionHas('err');
    ($this->as)($this->padm)->delete(route('padmin.trash.meetings.purge', $m->id), ['confirm_title' => '週會'])->assertSessionHas('ok');
    expect(Meeting::find($m->id))->toBeNull()->and(MeetingItem::find($i->id))->toBeNull();
    ($this->as)($this->padm)->get(route('padmin.events'))->assertOk()->assertSee('永久刪除會議');
});

it('shows the meetings link and lets admins manage meeting types', function () {
    ($this->as)($this->cview)->get(route('meetings.index'))->assertOk();
    ($this->as)($this->dev)->get(route('flows.index'))->assertSee('會議');
    $admin = User::create(['account' => 'mtadm', 'name' => 'adm', 'email' => 'mtadm@t.test', 'password' => '123456', 'user_type' => 'internal', 'is_admin' => true, 'status' => 'active']);
    $this->actingAs($admin)->get(route('admin.lookups.index', 'meeting_type'))->assertOk()->assertSee('定期會議');
});
