<?php

it('shows the login form on home for guests', function () {
    $this->get('/')->assertOk()->assertSee('name="login"', false);
    $this->get('/login')->assertRedirect('/');
});
