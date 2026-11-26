<?php

use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\HomeController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ProjectSelectController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// The login form lives at "/" (HomeController); /login only redirects there for old links.
Route::get('/login', fn () => redirect()->route('home'))->name('login');

Route::middleware('guest')->group(function () {
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/session/ping', [LoginController::class, 'ping'])->name('session.ping');

    Route::get('/account/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/account/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('/projects/select', [ProjectSelectController::class, 'show'])->name('projects.select');
    Route::post('/projects/select', [ProjectSelectController::class, 'store'])->name('projects.switch');
});

Route::middleware(['auth', 'active', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', fn () => redirect()->route('admin.users.index'))->name('index');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');

    Route::get('projects/{project}/members', [MemberController::class, 'index'])->name('projects.members');
    Route::post('projects/{project}/members', [MemberController::class, 'store'])->name('projects.members.store');
    Route::put('projects/{project}/members/{user}', [MemberController::class, 'update'])->name('projects.members.update');
    Route::delete('projects/{project}/members/{user}', [MemberController::class, 'destroy'])->name('projects.members.destroy');
});

foreach (glob(__DIR__.'/modules/*.php') as $f) {
    require $f;
}
