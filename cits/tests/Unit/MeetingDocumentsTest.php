<?php

use App\Exceptions\BusinessException;
use App\Models\Attachment;
use App\Models\Meeting;
use App\Models\User;
use App\Repositories\AttachmentRepository;
use App\Repositories\MeetingRepository;
use App\Services\AttachmentService;
use App\Services\Meeting\MeetingDocuments;
use App\Services\Meeting\MeetingService;
use App\Services\WorkflowService;
use Illuminate\Database\Eloquent\Collection as Models;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

it('lists current references once in note order and applies meeting visibility', function () {
    $client = (new User)->setRawAttributes(['id' => 11, 'user_type' => 'client']);
    $staff = (new User)->setRawAttributes(['id' => 12, 'user_type' => 'internal']);
    $meeting = (new Meeting)->setRawAttributes([
        'id' => 3, 'customer_visible' => true,
        'notes_html' => '<a href="/mescits/attachments/2">b</a><a href="/attachments/1">a</a><a href="/attachments/2">b</a>',
        'internal_html' => '<a href="/attachments/4">private</a>',
    ])->setRelation('creator', $staff);
    $repo = Mockery::mock(AttachmentRepository::class);
    $repo->shouldReceive('meetingDocuments')->andReturn(new Models);
    $repo->shouldReceive('boundMarkdown')->with(3, 'meeting', [2, 1])->andReturn(new Models([
        1 => (new Attachment)->setRawAttributes(['id' => 1]),
        2 => (new Attachment)->setRawAttributes(['id' => 2]),
    ]));
    $repo->shouldReceive('boundMarkdown')->once()->with(3, 'meeting_internal', [4])->andReturn(new Models([
        4 => (new Attachment)->setRawAttributes(['id' => 4]),
    ]));
    $docs = new MeetingDocuments(app(MeetingService::class), $repo, app(AttachmentService::class));
    expect($docs->visible($staff, $meeting)->pluck('id')->all())->toBe([2, 1, 4])
        ->and($docs->visible($client, $meeting))->toBeEmpty();
    $meeting->published_at = now();
    expect($docs->visible($client, $meeting)->pluck('id')->all())->toBe([2, 1]);
    $meeting->notes_html = '';
    expect($docs->visible($client, $meeting))->toBeEmpty();
    $meeting->notes_html = '<a href="/attachments/1">a</a>';
    $meeting->customer_visible = false;
    expect($docs->visible($client, $meeting))->toBeEmpty();
});

it('denies the uploader of a bound document when sharing is revoked', function () {
    $repo = Mockery::mock(AttachmentRepository::class);
    $repo->shouldReceive('meetingOpenToClients')->once()->with(3)->andReturn(false);
    $this->app->instance(AttachmentRepository::class, $repo);
    $user = (new User)->setRawAttributes(['id' => 8, 'user_type' => 'client']);
    $file = (new Attachment)->setRawAttributes(['state' => 'bound', 'uploaded_by' => 8, 'owner_type' => 'meeting', 'owner_id' => 3]);
    expect(app(AttachmentService::class)->canView($user, $file))->toBeFalse();
});

it('checks Markdown byte size, encoding and empty content before rendering', function () {
    $repo = Mockery::mock(AttachmentRepository::class);
    $repo->shouldReceive('setting')->with('attachment.max_mb')->andReturn('1');
    $this->app->instance(AttachmentRepository::class, $repo);
    config(['meeting.markdown_max_bytes' => 32]);
    $service = app(AttachmentService::class);
    $stream = tmpfile();
    $path = stream_get_meta_data($stream)['uri'];
    foreach (["\xff", "ab\0c", str_repeat('x', 33), "\xef\xbb\xbf "] as $invalid) {
        file_put_contents($path, $invalid);
        clearstatcache(true, $path);
        expect(fn () => $service->readMarkdown($path))->toThrow(BusinessException::class);
    }
    file_put_contents($path, "\xef\xbb\xbf# 開會\r\n");
    clearstatcache(true, $path);
    expect($service->readMarkdown($path))->toBe("# 開會\n");
    fclose($stream);
});

it('serves a sanitized preview and original bytes, then refuses both after unsharing', function () {
    $client = (new User)->setRawAttributes(['id' => 11, 'user_type' => 'client']);
    $meeting = (new Meeting)->setRawAttributes([
        'id' => 3, 'project_id' => 6, 'customer_visible' => true, 'notes_html' => '<a href="/attachments/1">read</a>',
    ])->setRelation('creator', $client);
    $file = (new Attachment)->setRawAttributes(['id' => 1, 'original_name' => '說明.md', 'disk_path' => 'attachments/demo.md']);
    $meetings = Mockery::mock(MeetingRepository::class);
    $meetings->shouldReceive('find')->with(3)->andReturn($meeting);
    $this->app->instance(MeetingRepository::class, $meetings);
    $workflow = Mockery::mock(WorkflowService::class);
    $workflow->shouldReceive('can')->with($client, 'meeting.view', 6)->andReturn(true);
    $this->app->instance(WorkflowService::class, $workflow);
    $repo = Mockery::mock(AttachmentRepository::class);
    $repo->shouldReceive('meetingDocuments')->andReturn(new Models);
    $repo->shouldReceive('boundMarkdown')->with(3, 'meeting', [1])->andReturn(new Models([1 => $file]));
    $repo->shouldReceive('setting')->with('attachment.max_mb')->andReturn('1');
    $this->app->instance(AttachmentRepository::class, $repo);
    $this->withoutVite()->withoutMiddleware()->actingAs($client)->withSession(['project_id' => 6]);
    $source = "# 說明\r\n\r\n<script>unsafe()</script>";
    Storage::fake('local')->put($file->disk_path, $source);
    $url = route('meetings.documents.show', [3, 1]);
    $this->get($url)->assertOk()->assertSee('<h1>說明</h1>', false)->assertDontSee('unsafe()');
    $download = $this->get($url.'?download=1')->assertDownload('document-1.md');
    expect($download->headers->get('Content-Disposition'))->toContain(rawurlencode('說明.md'));
    expect(file_get_contents($download->baseResponse->getFile()->getPathname()))->toBe($source);
    $meeting->customer_visible = false;
    $this->get($url)->assertNotFound();
    $this->get($url.'?download=1')->assertNotFound();
});

it('stores each meeting upload privately in its editor context and rejects client internal uploads', function () {
    $user = (new User)->setRawAttributes(['id' => 12, 'user_type' => 'client']);
    $meeting = (new Meeting)->setRawAttributes(['id' => 3, 'project_id' => 6]);
    $svc = Mockery::mock(MeetingService::class)->makePartial();
    $svc->shouldReceive('canView')->with($user, 6)->andReturn(true);
    $svc->shouldReceive('find')->with($user, 6, 3)->andReturn($meeting);
    $svc->shouldReceive('canEdit')->with($user, $meeting)->andReturn(true);
    $this->app->instance(MeetingService::class, $svc);
    $repo = Mockery::mock(AttachmentRepository::class);
    $repo->shouldReceive('setting')->with('attachment.max_mb')->andReturn('1');
    $saved = [];
    $repo->shouldReceive('create')->twice()->andReturnUsing(function ($data) use (&$saved) {
        $saved[] = $data;

        return (new Attachment)->setRawAttributes(['id' => count($saved)] + $data);
    });
    $this->app->instance(AttachmentRepository::class, $repo);
    $disk = Storage::fake('local');
    $this->withoutMiddleware()->actingAs($user)->withSession(['project_id' => 6]);
    $url = route('meetings.documents.upload', 3);
    foreach (['第一份.md', '第二份.MD'] as $index => $name) {
        $file = UploadedFile::fake()->createWithContent($name, '# '.$name);
        $this->post($url, ['file' => $file, 'internal' => '0'])->assertOk()->assertJsonPath('name', $name);
        expect($saved[$index]['draft_context_key'])->toBe('meeting:notes:3')
            ->and($saved[$index]['state'])->toBe('temp')
            ->and($disk->get($saved[$index]['disk_path']))->toBe('# '.$name);
    }
    $this->post($url, ['internal' => '1'])->assertForbidden();
});

it('publishes a batch directly on a meeting with empty notes and returns the visible document list', function () {
    $client = (new User)->setRawAttributes(['id' => 12, 'user_type' => 'client']);
    $meeting = (new Meeting)->setRawAttributes(['id' => 3, 'project_id' => 6, 'customer_visible' => true])->setRelation('creator', $client);
    $svc = Mockery::mock(MeetingService::class)->makePartial();
    $svc->shouldReceive('canView')->with($client, 6)->andReturn(true);
    $svc->shouldReceive('find')->with($client, 6, 3)->andReturn($meeting);
    $svc->shouldReceive('canEdit')->with($client, $meeting)->andReturn(true);
    $this->app->instance(MeetingService::class, $svc);
    $repo = Mockery::mock(AttachmentRepository::class);
    $repo->shouldReceive('setting')->with('attachment.max_mb')->andReturn('1');
    $saved = [];
    $repo->shouldReceive('create')->twice()->andReturnUsing(function ($data) use (&$saved) {
        expect($data['draft_context_key'])->toBe(Attachment::MEETING_DOCUMENTS);
        $file = (new Attachment)->setRawAttributes(['id' => count($saved) + 1] + $data);
        $saved[] = $file;
        return $file;
    });
    $repo->shouldReceive('bindMeetingDocuments')->once()->with([1, 2], 12, 3, 'meeting');
    $repo->shouldReceive('meetingDocuments')->once()->with(3, ['meeting'])->andReturnUsing(fn () => new Models([
        (new Attachment)->setRawAttributes(['id' => 1, 'original_name' => 'first.md']),
        (new Attachment)->setRawAttributes(['id' => 2, 'original_name' => 'second.md']),
    ]));
    $this->app->instance(AttachmentRepository::class, $repo);
    Storage::fake('local');
    $this->withoutMiddleware()->actingAs($client)->withSession(['project_id' => 6]);
    $url = route('meetings.documents.store', 3);
    $this->postJson($url, ['internal' => '1'])->assertForbidden();
    $files = array_map(fn ($name) => UploadedFile::fake()->createWithContent($name, '# Test'), ['first.md', 'second.md']);
    $result = $this->post($url, ['internal' => '0', 'files' => $files], ['Accept' => 'application/json'])->assertOk();
    expect($result->json('list'))->toContain('first.md', 'second.md', route('meetings.documents.show', [3, 2]));
});

it('keeps a partially staged batch out of the document list when a file fails', function () {
    $storage = Mockery::mock(AttachmentService::class);
    $repo = Mockery::mock(AttachmentRepository::class);
    $storage->shouldReceive('storeMeetingMarkdown')->once()->ordered()->andReturn((new Attachment)->setRawAttributes(['id' => 1]));
    $storage->shouldReceive('storeMeetingMarkdown')->once()->ordered()->andThrow(new BusinessException('格式錯誤'));
    $repo->shouldNotReceive('bindMeetingDocuments');
    $service = new MeetingDocuments(app(MeetingService::class), $repo, $storage);
    $files = [UploadedFile::fake()->create('ok.md'), UploadedFile::fake()->create('bad.txt')];
    expect(fn () => $service->upload(new User, new Meeting, $files, true))->toThrow(BusinessException::class);
});
