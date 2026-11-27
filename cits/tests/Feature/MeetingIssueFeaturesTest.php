<?php

use App\Models\ActivityEvent;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserNotification;
use App\Repositories\WatchRepository;
use App\Services\Meeting\MeetingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// Meeting items across meetings (threads / history), item pictures, item -> new internal issue.

function mfUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@t.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

function mfPng(string $seed = 'a'): UploadedFile
{
    $img = imagecreatetruecolor(4, 4);
    imagefill($img, 0, 0, imagecolorallocate($img, ord($seed[0]), 10, 10));
    ob_start();
    imagepng($img);

    return UploadedFile::fake()->createWithContent("$seed.png", ob_get_clean());
}

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    SystemSetting::query()->updateOrCreate(['key' => 'attachment.max_mb'], ['value' => '5']);
    SystemSetting::query()->updateOrCreate(['key' => 'attachment.allowed_ext'], ['value' => 'png,jpg']);
    $this->p = Project::create(['code' => 'MF', 'name' => 'MF', 'ticket_prefix' => 'MF']);
    $this->pm = mfUser('mfpm', 'internal', ['internal_pm'], $this->p);
    $this->dev = mfUser('mfdev', 'internal', ['dev'], $this->p);
    $this->dev2 = mfUser('mfdev2', 'internal', ['dev'], $this->p);
    $this->cpm = mfUser('mfcpm', 'client', ['client_pm'], $this->p);
    $this->as = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
    $this->make = function (User $u, array $in = []) {
        ($this->as)($u)->post(route('meetings.store'), $in + ['type' => 'regular', 'title' => '週會', 'start_at' => '2026-12-01 10:00', 'mode' => 'scheduled'])
            ->assertRedirect();

        return Meeting::latest('id')->first();
    };
    $this->issue = fn (string $no, string $status = 'new') => Issue::create(['project_id' => $this->p->id, 'issue_no' => $no, 'origin' => 'pm',
        'title' => "標題{$no}", 'content_html' => '<p>x</p>', 'stage' => 'PM', 'status' => $status, 'created_by' => $this->pm->id]);
    $this->upload = fn (User $u, string $seed = 'a') => ($this->as)($u)
        ->post(route('uploads.image'), ['file' => mfPng($seed), 'context' => 'meeting:item:1'], ['Accept' => 'application/json'])
        ->assertOk()->json('attachment_id');
    $this->publish = function (Meeting $m) {
        ($this->as)($this->pm)->post(route('meetings.status', $m->id), ['to' => 'recording'])->assertSessionHas('ok');
        ($this->as)($this->pm)->post(route('meetings.status', $m->id), ['to' => 'done'])->assertSessionHas('ok');
    };
});

it('groups an issue discussed over several meetings and shows the history on each item', function () {
    $issue = ($this->issue)('MF-1');
    $weekly = ($this->make)($this->pm, ['attendee_ids' => [$this->dev->id], 'customer_visible' => 1]);
    ($this->as)($this->pm)->postJson(route('meetings.items.issues', $weekly->id), ['issue_ids' => [$issue->id]])->assertJsonPath('added', 1);
    $first = $weekly->items()->first();
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$weekly->id, $first->id]), ['discussion' => '先看 log', 'conclusion' => '下週再議'])->assertOk();

    ($this->as)($this->pm)->post(route('meetings.next', $weekly->id), ['start_at' => '2026-12-08 10:00'])->assertRedirect();
    $next = Meeting::latest('id')->first();
    $carried = $next->items()->first();
    expect($carried->issue_id)->toBe($issue->id)->and($carried->carried_from_id)->toBe($first->id);

    // a separate review meeting links the same issue by number
    $review = ($this->make)($this->pm, ['title' => '驗收會', 'start_at' => '2026-12-05 14:00']);
    $r1 = ($this->as)($this->pm)->postJson(route('meetings.items.store', $review->id), ['topic' => '驗收', 'issue_no' => 'MF-1', 'conclusion' => '驗收通過'])
        ->assertOk()->json('item');
    // one item per issue per meeting
    ($this->as)($this->pm)->postJson(route('meetings.items.store', $review->id), ['topic' => '重複', 'issue_no' => 'mf-1'])
        ->assertStatus(422)->assertJsonPath('error', '問題單「mf-1」已在本會議的「驗收」事項中。');
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$review->id, $r1['id']]), ['issue_no' => 'MF-1'])->assertOk(); // same item again: fine

    $threads = app(MeetingService::class)->issueThreads($this->pm, $this->p->id, $issue->id);
    expect($threads)->toHaveCount(2)
        ->and($threads[0]['items']->pluck('id')->all())->toBe([$first->id, $carried->id]) // weekly thread: newest activity (12/08)
        ->and($threads[0]['conclusion'])->toBe('下週再議')
        ->and($threads[1]['items']->pluck('id')->all())->toBe([$r1['id']]);
    ($this->as)($this->pm)->get(route('issues.show', $issue->id))->assertOk()->assertSee('2 場')->assertSee('先看 log')->assertSee('驗收通過');

    // meeting page: the other meetings' discussion of the same issue, internal users only
    ($this->as)($this->pm)->get(route('meetings.show', $next->id))->assertOk()->assertSee('歷次討論（2）')->assertSee('驗收通過');
    ($this->publish)($next);
    ($this->as)($this->cpm)->get(route('meetings.show', $next->id))->assertOk()->assertDontSee('歷次討論')->assertDontSee('驗收通過');
});

it('skips items whose issue is closed when scheduling the next meeting', function () {
    [$open, $closed] = [($this->issue)('MF-2'), ($this->issue)('MF-3', 'released')];
    $m = ($this->make)($this->pm, ['topics' => '一般事項']);
    ($this->as)($this->pm)->postJson(route('meetings.items.issues', $m->id), ['issue_ids' => [$open->id, $closed->id]])->assertOk();
    ($this->as)($this->pm)->get(route('meetings.show', $m->id))->assertSee('內部單已結案')->assertSee('略過內部單已結案的事項');

    ($this->as)($this->pm)->post(route('meetings.next', $m->id), ['start_at' => '2026-12-08 10:00', 'skip_closed' => 1])->assertRedirect();
    expect(Meeting::latest('id')->first()->items()->pluck('topic')->all())->toBe(['一般事項', 'MF-2 標題MF-2']);
    ($this->as)($this->pm)->post(route('meetings.next', $m->id), ['start_at' => '2026-12-15 10:00'])->assertRedirect();
    expect(Meeting::latest('id')->first()->items()->count())->toBe(3);
});

it('logs and announces item conclusions to issue watchers once, when the record is published', function () {
    $issue = ($this->issue)('MF-4');
    app(WatchRepository::class)->add('issue', $issue->id, $this->dev->id);
    $m = ($this->make)($this->pm);
    ($this->as)($this->pm)->postJson(route('meetings.items.issues', $m->id), ['issue_ids' => [$issue->id]])->assertOk();
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $m->items()->first()->id]), ['conclusion' => '改用批次匯入'])->assertOk();

    ($this->publish)($m);
    $ev = ActivityEvent::where(['subject_type' => 'issue', 'subject_id' => $issue->id, 'event_type' => 'meeting_discussed'])->get();
    expect($ev)->toHaveCount(1)->and($ev[0]->payload['conclusion'])->toBe('改用批次匯入')
        ->and(UserNotification::where('user_id', $this->dev->id)->where('title', 'like', 'MF-4 在會議%')->count())->toBe(1);
    ($this->as)($this->pm)->get(route('issues.show', $issue->id))->assertSee('會議結論')->assertSee('改用批次匯入');

    // edits after publishing are not announced again
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $m->items()->first()->id]), ['conclusion' => '改用 API'])->assertOk();
    expect(ActivityEvent::where(['subject_id' => $issue->id, 'event_type' => 'meeting_discussed'])->count())->toBe(1);
});

it('keeps pictures on discussion items with the item visibility rules', function () {
    $m = ($this->make)($this->pm, ['attendee_ids' => [$this->dev->id], 'customer_visible' => 1, 'topics' => '畫面錯位']);
    $item = $m->items()->first();
    $url = fn (?int $img = null) => route($img ? 'meetings.items.images.update' : 'meetings.items.images.store', array_filter([$m->id, $item->id, $img]));

    $a = ($this->upload)($this->dev);
    ($this->as)($this->dev)->postJson($url(), ['attachment_id' => $a])->assertOk()->assertJsonCount(1, 'images');
    expect(Attachment::find($a))->state->toBe('bound')->owner_type->toBe('meeting_item')->owner_id->toBe($item->id);

    // someone else's upload, or a non-editor, cannot be bound
    $other = ($this->upload)($this->pm, 'b');
    ($this->as)($this->dev)->postJson($url(), ['attachment_id' => $other])->assertStatus(422);
    ($this->as)($this->dev2)->postJson($url(), ['attachment_id' => ($this->upload)($this->dev2, 'c')])->assertStatus(422);
    config(['meeting.item_images_max' => 1]);
    ($this->as)($this->pm)->postJson($url(), ['attachment_id' => $other])->assertStatus(422)->assertJsonPath('error', '每個討論事項最多 1 張圖片。');
    config(['meeting.item_images_max' => 10]);

    // clients: only once the shared record is published
    ($this->as)($this->cpm)->get(route('attachments.show', $a))->assertForbidden();
    ($this->as)($this->cpm)->get(route('meetings.show', $m->id))->assertOk()->assertDontSee('attachments/'.$a);
    ($this->publish)($m);
    ($this->as)($this->cpm)->get(route('attachments.show', $a))->assertOk();
    ($this->as)($this->cpm)->get(route('meetings.show', $m->id))->assertSee('attachments/'.$a);
    ($this->as)($this->pm)->post(route('meetings.share', $m->id), ['visible' => 0]);
    ($this->as)($this->cpm)->get(route('attachments.show', $a))->assertForbidden();

    // annotated version takes the same id and place; the old file goes
    $oldPath = Attachment::find($a)->disk_path;
    $drawn = ($this->upload)($this->pm, 'd');
    ($this->as)($this->pm)->putJson($url($a), ['attachment_id' => $drawn])->assertOk()->assertJsonPath('images.0.id', $a);
    expect(Attachment::find($drawn))->toBeNull()->and(Attachment::find($a)->disk_path)->not->toBe($oldPath);
    Storage::disk('local')->assertMissing($oldPath);

    // carried to the next meeting as its own row on the same file
    ($this->as)($this->pm)->post(route('meetings.next', $m->id), ['start_at' => '2026-12-08 10:00'])->assertRedirect();
    $carried = Meeting::latest('id')->first()->items()->first();
    $copy = Attachment::where(['owner_type' => 'meeting_item', 'owner_id' => $carried->id])->sole();
    expect($copy->id)->not->toBe($a)->and($copy->disk_path)->toBe(Attachment::find($a)->disk_path);

    // removing / deleting the item drops its rows; the shared file stays for the copy
    ($this->as)($this->pm)->deleteJson(route('meetings.items.images.destroy', [$m->id, $item->id, $a]))->assertOk()->assertJsonCount(0, 'images');
    expect(Attachment::find($a))->toBeNull();
    Storage::disk('local')->assertExists($copy->disk_path);
    $n = Meeting::latest('id')->first();
    ($this->as)($this->pm)->deleteJson(route('meetings.items.destroy', [$n->id, $carried->id]))->assertOk();
    expect(Attachment::find($copy->id))->toBeNull();
    Storage::disk('local')->assertMissing($copy->disk_path);
});

it('turns a discussion item into a new internal issue linked to the item', function () {
    $m = ($this->make)($this->pm, ['attendee_ids' => [$this->dev->id], 'topics' => '出貨單列印跑版']);
    $item = $m->items()->first();
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $item->id]),
        ['discussion' => "A4 印出\n右邊被切掉", 'conclusion' => '改版面', 'owner_id' => $this->dev->id])->assertOk();
    $pic = ($this->upload)($this->pm);
    ($this->as)($this->pm)->postJson(route('meetings.items.images.store', [$m->id, $item->id]), ['attachment_id' => $pic])->assertOk();
    ($this->as)($this->pm)->get(route('meetings.show', $m->id))->assertSee('轉內部單');

    $form = ($this->as)($this->pm)->get(route('meetings.items.issue', [$m->id, $item->id]))->assertOk()
        ->assertSee('出貨單列印跑版')->assertSee('右邊被切掉');
    $copy = Attachment::where(['state' => 'temp', 'uploaded_by' => $this->pm->id, 'draft_context_key' => 'issue:meeting-item:'.$item->id])->sole();
    expect($copy->disk_path)->toBe(Attachment::find($pic)->disk_path);
    ($this->as)($this->pm)->get(route('meetings.items.issue', [$m->id, $item->id]))->assertOk(); // reopening reuses the copy
    expect(Attachment::where('draft_context_key', 'issue:meeting-item:'.$item->id)->count())->toBe(1);

    $html = '<p>A4 印出</p><p><img src="'.route('attachments.show', $copy->id).'" alt="附圖"></p>';
    ($this->as)($this->pm)->post(route('meetings.items.issue.store', [$m->id, $item->id]),
        ['origin' => 'pm', 'title' => '出貨單列印跑版', 'severity' => 3, 'content_html' => $html])->assertRedirect();
    $issue = Issue::latest('id')->first();
    expect($issue->title)->toBe('出貨單列印跑版')->and($issue->origin)->toBe('pm')
        ->and($item->fresh()->issue_id)->toBe($issue->id)
        ->and(Attachment::find($copy->id))->state->toBe('bound')->owner_type->toBe('issue')->owner_id->toBe($issue->id)
        ->and(ActivityEvent::where(['subject_type' => 'issue', 'subject_id' => $issue->id, 'event_type' => 'from_meeting'])->exists())->toBeTrue()
        ->and(DB::table('issue_watchers')->where(['issue_id' => $issue->id, 'user_id' => $this->dev->id])->exists())->toBeTrue();
    ($this->as)($this->pm)->get(route('issues.show', $issue->id))->assertSee('由會議事項建立')->assertSee('第 1 項');

    // already linked: no second issue
    ($this->as)($this->pm)->post(route('meetings.items.issue.store', [$m->id, $item->id]),
        ['origin' => 'pm', 'title' => 'x', 'severity' => 3, 'content_html' => '<p>x</p>'])->assertSessionHas('err');
    expect(Issue::count())->toBe(1);
});

it('lets only internal editors with an issue-creating role convert items, as an origin they may use', function () {
    $m = ($this->make)($this->pm, ['attendee_ids' => [$this->dev->id], 'topics' => '速度慢']);
    $item = $m->items()->first();
    $post = fn (User $u, string $origin) => ($this->as)($u)->post(route('meetings.items.issue.store', [$m->id, $item->id]),
        ['origin' => $origin, 'title' => '速度慢', 'severity' => 2, 'content_html' => '<p>x</p>']);

    $post($this->dev2, 'dev')->assertSessionHas('err'); // not an attendee
    ($this->as)($this->dev2)->get(route('meetings.items.issue', [$m->id, $item->id]))->assertSessionHas('err');
    $post($this->dev, 'pm')->assertSessionHas('err', '請選擇內部單來源。'); // DEV may not create PM issues
    ($this->as)($this->dev)->get(route('meetings.items.issue', [$m->id, $item->id]))->assertOk()->assertSee('DEV')->assertDontSee('value="pm"', false);
    $item->update(['owner_id' => $this->cpm->id]); // a client owner never becomes a watcher of an internal issue
    $post($this->dev, 'dev')->assertRedirect();
    $issue = Issue::latest('id')->first();
    expect($issue)->origin->toBe('dev')->status->toBe('dev_todo')
        ->and(DB::table('issue_watchers')->where(['issue_id' => $issue->id, 'user_id' => $this->cpm->id])->exists())->toBeFalse();

    $c = ($this->make)($this->cpm, ['topics' => '客戶事項']);
    $ci = $c->items()->first();
    ($this->as)($this->cpm)->get(route('meetings.show', $c->id))->assertOk()->assertDontSee('轉內部單');
    ($this->as)($this->cpm)->post(route('meetings.items.issue.store', [$c->id, $ci->id]),
        ['origin' => 'pm', 'title' => 'x', 'severity' => 2, 'content_html' => '<p>x</p>'])->assertSessionHas('err');
    expect($ci->fresh()->issue_id)->toBeNull();
});
