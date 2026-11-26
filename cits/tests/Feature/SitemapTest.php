<?php

use App\Exceptions\BusinessException;
use App\Models\MesPage;
use App\Models\Project;
use App\Models\User;
use App\Services\Mes\SitemapParser;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\PermissionRegistrar;

function mdsUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@t.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

function mdsXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<siteMap xmlns="http://schemas.microsoft.com/AspNet/SiteMap-File-1.0">
  <siteMapNode title="首頁" description="">
    <siteMapNode url="~/Production/WorkOrder.aspx?x=1#top" title="工單" />
    <siteMapNode title="品質">
      <siteMapNode url="Quality/Inspect.aspx" title="檢驗" />
    </siteMapNode>
    <siteMapNode url="http://evil.example/x.aspx" title="外部" />
  </siteMapNode>
</siteMap>
XML;
}

beforeEach(function () {
    $this->p = Project::create(['code' => 'SM', 'name' => 'SM', 'ticket_prefix' => 'SM']);
    $this->pm = mdsUser('smpm', 'internal', ['internal_pm'], $this->p);
    $this->client = mdsUser('smclient', 'client', ['client_pm'], $this->p);
    $this->as = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
});

it('parses nested folders and pages with normalization', function () {
    $n = (new SitemapParser)->parse(mdsXml());
    expect($n)->toHaveCount(5)
        ->and($n[0])->toMatchArray(['type' => 'folder', 'name' => '首頁', 'parent' => null, 'sort' => 10])
        ->and($n[1])->toMatchArray(['type' => 'page', 'path' => '~/Production/WorkOrder.aspx', 'parent' => 0, 'sort' => 10])
        ->and($n[2])->toMatchArray(['type' => 'folder', 'name' => '品質', 'parent' => 0, 'sort' => 20])
        ->and($n[3])->toMatchArray(['type' => 'page', 'path' => '~/Quality/Inspect.aspx', 'parent' => 2])
        ->and($n[4]['path'])->toBeNull();
});

it('previews without writing and then applies', function () {
    $res = ($this->as)($this->pm)->post(route('mes.sitemap.preview'), ['sitemap_xml' => mdsXml()])->assertOk()->assertSee('新增');
    expect(MesPage::count())->toBe(0);
    $payload = $res->viewData('payload');

    ($this->as)($this->pm)->post(route('mes.sitemap.apply'), ['payload' => $payload])->assertRedirect(route('mes.index'));
    expect(MesPage::where('node_type', 'folder')->count())->toBe(2)
        ->and(MesPage::where('node_type', 'page')->count())->toBe(2)
        ->and(MesPage::where('page_path', '~/Quality/Inspect.aspx')->first()->parent->name)->toBe('品質');
});

it('updates existing pages, never deactivates missing ones, and tampering fails', function () {
    $keep = MesPage::create(['project_id' => $this->p->id, 'node_type' => 'page', 'name' => '不在sitemap', 'page_path' => '~/Other/X.aspx', 'page_file' => 'X.aspx', 'sort' => 0, 'is_active' => true]);
    $old = MesPage::create(['project_id' => $this->p->id, 'node_type' => 'page', 'name' => '舊名', 'page_path' => '~/production/workorder.aspx', 'page_file' => 'workorder.aspx', 'sort' => 0, 'is_active' => true]);

    $res = ($this->as)($this->pm)->post(route('mes.sitemap.preview'), ['sitemap_xml' => mdsXml()])->assertOk()->assertSee('已存在');
    $payload = $res->viewData('payload');
    ($this->as)($this->pm)->post(route('mes.sitemap.apply'), ['payload' => $payload]);

    expect($old->fresh()->name)->toBe('工單')->and($old->fresh()->parent->name)->toBe('首頁')
        ->and($keep->fresh()->is_active)->toBeTrue()
        ->and(MesPage::count())->toBe(5);

    $before = MesPage::count();
    ($this->as)($this->pm)->post(route('mes.sitemap.apply'), ['payload' => substr_replace($payload, $payload[20] === 'A' ? 'B' : 'A', 20, 1)])->assertSessionHas('err');
    expect(MesPage::count())->toBe($before);
});

it('accepts an uploaded .sitemap file', function () {
    $f = UploadedFile::fake()->createWithContent('Web.sitemap', mdsXml());
    ($this->as)($this->pm)->post(route('mes.sitemap.preview'), ['sitemap_file' => $f])->assertOk()->assertSee('工單');
});

it('rejects XXE / DOCTYPE payloads', function () {
    $xxe = '<?xml version="1.0"?><!DOCTYPE s [<!ENTITY x SYSTEM "file:///etc/passwd">]><siteMap><siteMapNode title="&x;" url="~/A.aspx"/></siteMap>';
    expect(fn () => (new SitemapParser)->parse($xxe))->toThrow(BusinessException::class);
    ($this->as)($this->pm)->post(route('mes.sitemap.preview'), ['sitemap_xml' => $xxe])->assertSessionHas('err');
    expect(MesPage::count())->toBe(0);
});

it('forbids non-managers', function () {
    ($this->as)($this->client)->post(route('mes.sitemap.preview'), ['sitemap_xml' => mdsXml()])->assertForbidden();
    ($this->as)($this->client)->post(route('mes.sitemap.apply'), ['payload' => 'x'])->assertForbidden();
});
