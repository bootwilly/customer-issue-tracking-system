<?php

use App\Models\AuthLog;
use App\Models\ClientTicket;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\TicketService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// forged-IP brute force, attachment types, stored-XSS defence, security headers

function shUser(string $account, string $type = 'internal'): User
{
    return User::create(['account' => $account, 'name' => $account, 'email' => "$account@sh.test", 'password' => 'secret1', 'user_type' => $type, 'status' => 'active']);
}

it('locks an account after repeated failures even when the IP changes every time', function () {
    shUser('frank');
    RateLimiter::clear('login-account|frank');
    foreach (range(1, 10) as $i) {
        $this->withHeaders(['X-Forwarded-For' => "198.51.100.$i"])->post('/login', ['login' => 'frank', 'password' => 'bad']);
    }
    $this->withHeaders(['X-Forwarded-For' => '198.51.100.99'])->post('/login', ['login' => 'frank', 'password' => 'secret1'])
        ->assertSessionHas('err');
    $this->assertGuest();
    $last = AuthLog::orderByDesc('id')->first();
    expect($last->only(['event', 'detail', 'ip']))->toBe(['event' => 'login_locked', 'detail' => 'per_account', 'ip' => '198.51.100.99']);
});

it('ships the widened attachment list and rejects other types', function () {
    expect(app(AttachmentService::class)->allowedExtensions())->toContain('docx', 'xlsx', 'pptx', 'doc', 'xls', 'ppt', 'json', 'md', 'zip', 'rar', '7z', 'txt')
        ->not->toContain('exe', 'html', 'svg', 'js');

    $u = shUser('gina');
    foreach (['spec.docx', 'data.json', 'notes.md', 'pack.7z', 'pack.rar', 'deck.pptx'] as $name) {
        $this->actingAs($u)->postJson('/uploads/init', ['name' => $name, 'size' => 10])->assertOk();
    }
    foreach (['run.exe', 'page.html', 'icon.svg', 'app.js', 'noext'] as $name) {
        $this->actingAs($u)->postJson('/uploads/init', ['name' => $name, 'size' => 10])->assertStatus(422);
    }
    $this->get('/account/password')->assertOk(); // page renders
});

it('sanitizes stored rich text again when it is displayed', function () {
    $p = Project::create(['code' => 'SH', 'name' => 'SH', 'ticket_prefix' => 'SH']);
    $c = shUser('henry', 'client');
    $p->members()->attach($c->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $c->assignRole(['client_pm']);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $c = $c->fresh();
    $t = app(TicketService::class)->create($c, $p->id, ['ticket_type' => 'anomaly', 'anomaly_category' => 'ui', 'title' => 'xss', 'content_html' => '<p>ok</p>']);

    // a row written around the service (old code, import, direct DB edit)
    ClientTicket::whereKey($t->id)->update(['content_html' => '<p>kept-text</p><script>alert("sh-9")</script><img src="x" onerror="alert(\'sh-8\')"><a href="javascript:alert(\'sh-7\')">l</a>']);

    $html = $this->actingAs($c)->withSession(['project_id' => $p->id])->get('/tickets/'.$t->id)->assertOk()->getContent();
    expect($html)->toContain('kept-text')->not->toContain('sh-9')->not->toContain('sh-8')->not->toContain('sh-7');
});

it('sends security headers and sandboxes uploaded files', function () {
    Storage::fake('local');
    SystemSetting::query()->updateOrCreate(['key' => 'attachment.max_mb'], ['value' => '1']);
    $u = shUser('ivy');
    $res = $this->actingAs($u)->get('/account/password')->assertOk();
    expect($res->headers->get('Content-Security-Policy'))->toContain("object-src 'none'")->toContain("frame-ancestors 'self'")
        ->and($res->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $png = UploadedFile::fake()->createWithContent('a.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $id = $this->postJson('/uploads/image', ['file' => $png])->json('attachment_id');
    expect($this->get("/attachments/$id")->assertOk()->headers->get('Content-Security-Policy'))->toContain('sandbox');
});
