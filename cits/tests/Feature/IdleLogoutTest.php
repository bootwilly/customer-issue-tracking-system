<?php

use App\Http\Middleware\LogoutWhenIdle;
use App\Models\User;
use App\Repositories\SettingRepository;

// signed out after session.idle_minutes without a request; background requests do not count

beforeEach(function () {
    $this->u = User::create(['account' => 'idle', 'name' => 'idle', 'email' => 'idle@idle.test', 'password' => '123456', 'user_type' => 'internal', 'status' => 'active', 'is_admin' => true]);
    app(SettingRepository::class)->set('session.idle_minutes', '60');
});

it('keeps the user signed in within the limit and signs out after it', function () {
    $this->actingAs($this->u)->get('/account/password')->assertOk();

    $this->travel(59)->minutes();
    $this->get('/account/password')->assertOk();

    $this->travel(61)->minutes();
    $this->get('/account/password')->assertRedirect(route('home'))->assertSessionHas('err');
    $this->assertGuest();
});

it('does not count background requests as activity', function () {
    $this->actingAs($this->u)->get('/account/password')->assertOk();
    $this->travel(40)->minutes();
    $this->getJson('/session/ping', [LogoutWhenIdle::BACKGROUND_HEADER => '1'])->assertOk();
    $this->travel(21)->minutes();
    $this->getJson('/session/ping')->assertStatus(401);
    $this->assertGuest();
});

it('turns off with 0 and only then offers remember me', function () {
    $this->get('/')->assertDontSee('記住我');
    app(SettingRepository::class)->set('session.idle_minutes', '0');
    $this->get('/')->assertSee('記住我');
    $this->actingAs($this->u)->get('/account/password')->assertOk();
    $this->travel(500)->minutes();
    $this->get('/account/password')->assertOk();
});
