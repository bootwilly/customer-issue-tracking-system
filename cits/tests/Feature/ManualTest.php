<?php

use App\Models\User;
use App\Services\ManualService;

beforeEach(function () {
    $this->withoutVite();
    $mk = fn (string $a, string $type, bool $admin = false) => User::create(['account' => $a, 'name' => $a, 'email' => "$a@t.test", 'password' => '123456', 'user_type' => $type, 'is_admin' => $admin, 'status' => 'active']);
    $this->client = $mk('mncli', 'client');
    $this->staff = $mk('mnint', 'internal');
    $this->admin = $mk('mnadm', 'internal', true);
});

it('requires login', function () {
    $this->get(route('manual.show'))->assertRedirect();
    $this->get(route('manual.image', 'login.png'))->assertRedirect();
});

it('hides internal chapters, blocks and screenshots from client accounts', function () {
    $c = $this->actingAs($this->client);
    $c->get(route('manual.show'))->assertOk()->assertSee('操作手冊')->assertDontSee('04-pm');
    $c->get(route('manual.show', '04-pm'))->assertNotFound();
    $c->get(route('manual.show', '09-system-admin'))->assertNotFound();
    $c->get(route('manual.image', 'pm-index.png'))->assertNotFound();
    $c->get(route('manual.image', 'client-list.png'))->assertOk();
    $c->get(route('manual.show', '02-workflow'))->assertOk()->assertDontSee('2.4 內部單狀態');
    $c->get(route('manual.show', '07-common'))->assertOk()->assertDontSee('時序總覽');
    $c->get(route('manual.show', '10-troubleshooting'))->assertOk()->assertDontSee('已知問題')->assertDontSee('10.3');
});

it('shows internal chapters to staff and the admin chapter to admins only', function () {
    $this->actingAs($this->staff)->get(route('manual.show', '04-pm'))->assertOk();
    $this->actingAs($this->staff)->get(route('manual.show', '09-system-admin'))->assertNotFound();
    $this->actingAs($this->staff)->get(route('manual.show', '02-workflow'))->assertOk()->assertSee('2.4 內部單狀態')->assertSee('cits-flow', false);
    $this->actingAs($this->admin)->get(route('manual.show', '09-system-admin'))->assertOk();
});

it('renders every chapter without leftover raw links or markers', function () {
    foreach (array_keys(app(ManualService::class)->chapters($this->admin)) as $chapter) {
        $html = app(ManualService::class)->render($this->admin, $chapter);
        expect($html)->not->toContain('.md"')->not->toContain('src="img/')->not->toContain('internal -->');
    }
});
