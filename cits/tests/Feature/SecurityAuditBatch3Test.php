<?php

use App\Models\ApiToken;
use App\Models\EditorDraft;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\User;
use App\Repositories\SettingRepository;
use App\Services\ApiTokenService;
use App\Services\DraftService;
use App\Services\MailSettingService;
use App\Services\OutboxService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// third batch for docs/security-audit-20261127.md: M-05, M-06 (preview), L-03, L-04, L-05

function scUser(string $account, string $type = 'internal', array $roles = [], ?Project $p = null, array $extra = []): User
{
    $u = User::create($extra + ['account' => $account, 'name' => $account, 'email' => "$account@sc.test", 'password' => 'secret1', 'user_type' => $type, 'status' => 'active']);
    if ($p) {
        $p->members()->attach($u->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
        $u->assignRole($roles);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    return $u->fresh();
}

beforeEach(function () {
    $this->withoutVite();
    $this->admin = scUser('scadmin', 'internal', [], null, ['is_admin' => true]);
});

it('M-05 never sends the stored SMTP password to a new host and requires STARTTLS for tls', function () {
    $svc = app(MailSettingService::class);
    $base = ['enabled' => true, 'host' => 'smtp.old.test', 'port' => '587', 'encryption' => 'tls', 'username' => 'u', 'from_address' => 'a@b.test', 'from_name' => 'x'];
    $svc->update($this->admin, $base, 'pw-old', false);
    expect(app(SettingRepository::class)->get('mail.password'))->toBe('pw-old');

    $svc->update($this->admin, $base, null, false);                                  // same host, blank password: kept
    expect(app(SettingRepository::class)->get('mail.password'))->toBe('pw-old');

    $svc->update($this->admin, ['host' => 'smtp.new.test'] + $base, null, false);     // new host, blank password: dropped
    expect(app(SettingRepository::class)->get('mail.password'))->toBeNull();

    app(OutboxService::class)->applySmtpConfig();
    expect(config('mail.mailers.smtp.require_tls'))->toBeTrue();
});

it('M-05 treats a value encrypted with another key as unset instead of failing', function () {
    SystemSetting::query()->updateOrCreate(['key' => 'mail.password'], ['value' => 'not-a-valid-payload', 'is_encrypted' => true]);
    expect(app(SettingRepository::class)->get('mail.password', 'fallback'))->toBe('fallback');
    $this->actingAs($this->admin)->get(route('admin.mail.edit'))->assertOk();
});

it('M-06 serves the framed meeting Markdown preview with a strict CSP', function () {
    Storage::fake('local');
    $p = Project::create(['code' => 'SE', 'name' => 'SE', 'ticket_prefix' => 'SE']);
    $pm = scUser('scpm', 'internal', ['internal_pm'], $p);
    $as = fn () => $this->actingAs($pm)->withSession(['project_id' => $p->id]);
    $as()->post(route('meetings.store'), ['type' => 'regular', 'title' => 'w', 'start_at' => '2026-12-01 10:00', 'mode' => 'scheduled'])->assertRedirect();
    $m = Meeting::latest('id')->first();
    $as()->post(route('meetings.documents.store', $m->id), ['internal' => 0, 'files' => [UploadedFile::fake()->createWithContent('n.md', "# Hi\n\ntext")]]);
    $doc = DB::table('attachments')->where('owner_type', 'meeting')->where('owner_id', $m->id)->value('id');
    expect($doc)->not->toBeNull();

    $csp = $as()->get(route('meetings.documents.show', [$m->id, $doc]))->assertOk()->headers->get('Content-Security-Policy');
    expect($csp)->toContain("script-src 'self'")->toContain("object-src 'none'")->toContain("form-action 'none'");
});

it('L-04 caps drafts per user and their size', function () {
    $u = scUser('scdraft');
    $svc = app(DraftService::class);
    foreach (range(1, DraftService::MAX_PER_USER + 3) as $n) {
        $svc->save($u->id, "ctx:$n", '<p>x</p>');
    }
    expect(EditorDraft::where('user_id', $u->id)->count())->toBe(DraftService::MAX_PER_USER)
        ->and(EditorDraft::where('user_id', $u->id)->where('context_key', 'ctx:'.(DraftService::MAX_PER_USER + 3))->exists())->toBeTrue();

    $this->actingAs($u)->postJson('/drafts', ['context' => 'big', 'html' => str_repeat('a', DraftService::MAX_HTML + 1)])->assertStatus(422);
});

it('L-05 refuses a duplicate ticket prefix', function () {
    Project::create(['code' => 'SF', 'name' => 'SF', 'ticket_prefix' => 'SF']);
    $this->actingAs($this->admin)->post('/admin/projects', ['code' => 'SG', 'name' => 'SG', 'ticket_prefix' => 'SF', 'status' => 'active'])
        ->assertSessionHasErrors('ticket_prefix');
});

it('L-05 deletes an unused account that still has tokens, watches and saved queries', function () {
    $u = scUser('scdel');
    app(ApiTokenService::class)->create($u, 't', 'none');
    $p = Project::create(['code' => 'SH2', 'name' => 'SH2', 'ticket_prefix' => 'SH2']);
    $p->members()->attach($u->id);

    $this->actingAs($this->admin)->delete("/admin/users/{$u->id}")->assertSessionHas('ok');
    expect(User::find($u->id))->toBeNull()->and(ApiToken::where('user_id', $u->id)->count())->toBe(0);
});
