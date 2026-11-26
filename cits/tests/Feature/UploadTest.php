<?php

use App\Models\Attachment;
use App\Models\EditorDraft;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\DraftService;
use App\Services\SanitizerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    if (! Route::has('uploads.image')) {
        require base_path('routes/modules/upload.php');
        app('router')->getRoutes()->refreshNameLookups();
    }
    Storage::fake('local');
    SystemSetting::query()->updateOrCreate(['key' => 'attachment.max_mb'], ['value' => '1']);
    SystemSetting::query()->updateOrCreate(['key' => 'attachment.allowed_ext'], ['value' => 'png,jpg,txt,zip']);
});

function mkUser(string $n): User
{
    return User::create([
        'account' => $n, 'name' => $n, 'email' => "$n@example.test", 'password' => 'secret12',
        'user_type' => 'internal', 'is_admin' => false, 'status' => 'active',
    ]);
}

// 1x1 png
function pngFile(string $name = 'a.png'): UploadedFile
{
    $bin = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    return UploadedFile::fake()->createWithContent($name, $bin);
}

it('uploads a pasted image', function () {
    $u = mkUser('u1');
    $r = $this->actingAs($u)->postJson('/uploads/image', ['file' => pngFile(), 'context' => 'ticket:new:1'])->assertOk();
    $a = Attachment::find($r->json('attachment_id'));
    expect($r->json('url'))->toBe(route('attachments.show', $a->id))
        ->and($a->state)->toBe('temp')->and($a->mime)->toBe('image/png');
    Storage::disk('local')->assertExists($a->disk_path);
});

it('assembles chunked upload with sha256', function () {
    $u = mkUser('u2');
    $content = str_repeat('hello world ', 1000);
    $init = $this->actingAs($u)->postJson('/uploads/init', ['name' => 'n.txt', 'size' => strlen($content), 'context' => 'c1'])->assertOk();
    $id = $init->json('id');
    expect($init->json('chunk_size'))->toBe(5 * 1024 * 1024);
    $this->actingAs($u)->postJson("/uploads/$id/chunk", ['index' => 0, 'chunk' => UploadedFile::fake()->createWithContent('0.part', $content)])->assertOk();
    $r = $this->actingAs($u)->postJson("/uploads/$id/complete")->assertOk();
    $a = Attachment::find($r->json('attachment_id'));
    expect($a->sha256)->toBe(hash('sha256', $content))->and($a->size)->toBe(strlen($content));
    expect(Storage::disk('local')->get($a->disk_path))->toBe($content);
    Storage::disk('local')->assertMissing("chunks/$id/000000.part");
});

it('rejects bad extension and oversize', function () {
    $u = mkUser('u3');
    $this->actingAs($u)->postJson('/uploads/init', ['name' => 'x.exe', 'size' => 10])->assertStatus(422);
    $this->actingAs($u)->postJson('/uploads/init', ['name' => 'x.txt', 'size' => 2 * 1024 * 1024])->assertStatus(422);
});

it('rejects incomplete or wrong-size chunk set', function () {
    $u = mkUser('u3b');
    $id = $this->actingAs($u)->postJson('/uploads/init', ['name' => 'n.txt', 'size' => 100])->json('id');
    $this->actingAs($u)->postJson("/uploads/$id/complete")->assertStatus(422);
    $this->actingAs($u)->postJson("/uploads/$id/chunk", ['index' => 0, 'chunk' => UploadedFile::fake()->createWithContent('0.part', 'short')])->assertOk();
    $this->actingAs($u)->postJson("/uploads/$id/complete")->assertStatus(422);
});

it('sanitizes dangerous html', function () {
    $s = app(SanitizerService::class);
    $out = $s->clean('<p onclick="x()">hi<script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">l</a>'
        .'<span style="color:red;position:fixed;font-size:1.25em">s</span><a href="/attachments/3" data-attachment-id="3" target="_blank">f</a>'
        .'<img src="/attachments/5" alt="i"><img src="http://evil.test/a.png"></p>');
    expect($out)->not->toContain('script')->not->toContain('onclick')->not->toContain('onerror')
        ->not->toContain('javascript:')->not->toContain('position')->not->toContain('evil.test')
        ->toContain('color: red; font-size: 1.25em')
        ->toContain('data-attachment-id="3"')->toContain('rel="noopener noreferrer"')
        ->toContain('src="/attachments/5"');
});

it('binds referenced temp attachments and removes unreferenced ones', function () {
    $u = mkUser('u4');
    $svc = app(AttachmentService::class);
    $keep = $svc->storeImage($u, pngFile('k.png'), 'ctx:1');
    $drop = $svc->storeImage($u, UploadedFile::fake()->createWithContent('d.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')), 'ctx:1');
    $svc->bindFromHtml('<p><img src="/attachments/'.$keep->id.'"></p>', 'ticket', 99, 'ctx:1', $u->id);
    $keep->refresh();
    expect($keep->state)->toBe('bound')->and($keep->owner_type)->toBe('ticket')->and($keep->owner_id)->toBe(99);
    expect(Attachment::find($drop->id))->toBeNull();
    Storage::disk('local')->assertExists($keep->disk_path);
    Storage::disk('local')->assertMissing($drop->disk_path);
});

it('saves, gets and clears drafts', function () {
    $u = mkUser('u5');
    $this->actingAs($u)->postJson('/drafts', ['context' => 'c', 'html' => '<p>hi<script>x</script></p>'])->assertOk();
    $r = $this->actingAs($u)->getJson('/drafts?context=c')->assertOk();
    expect($r->json('html'))->toBe('<p>hi</p>');
    $this->actingAs($u)->getJson('/drafts?context=other')->assertOk()->assertJson([]);
    $this->actingAs($u)->deleteJson('/drafts?context=c')->assertOk();
    expect(EditorDraft::count())->toBe(0);

    app(DraftService::class)->save($u->id, 'c2', '<p>x</p>');
    $this->actingAs($u)->postJson('/uploads/image', ['file' => pngFile(), 'context' => 'c2']);
    app(DraftService::class)->clear($u->id, 'c2');
    expect(EditorDraft::count())->toBe(0)->and(Attachment::count())->toBe(0);
});

it('denies attachment to other users but allows uploader and admin', function () {
    $a = mkUser('owner');
    $b = mkUser('other');
    $id = $this->actingAs($a)->postJson('/uploads/image', ['file' => pngFile()])->json('attachment_id');
    $this->actingAs($a)->get("/attachments/$id")->assertOk();
    $this->actingAs($b)->get("/attachments/$id")->assertForbidden();
    $admin = mkUser('adm');
    $admin->forceFill(['is_admin' => true])->save();
    $this->actingAs($admin)->get("/attachments/$id")->assertOk();
});

it('cleans old temp data via command', function () {
    $u = mkUser('u6');
    $a = app(AttachmentService::class)->storeImage($u, pngFile(), 'c');
    Attachment::query()->whereKey($a->id)->update(['created_at' => now()->subDays(8)]);
    EditorDraft::query()->insert(['user_id' => $u->id, 'context_key' => 'old', 'content_html' => 'x', 'updated_at' => now()->subDays(31)]);
    $this->artisan('cits:cleanup-temp')->assertSuccessful();
    expect(Attachment::count())->toBe(0)->and(EditorDraft::count())->toBe(0);
});
