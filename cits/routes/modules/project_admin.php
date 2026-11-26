<?php

use App\Http\Controllers\ProjectAdmin\ProjectAdminController;
use Illuminate\Support\Facades\Route;

// Project admin: system-admin-like pages limited to the current project (permissions project.members / project.trash).
Route::middleware(['web', 'auth', 'active', 'project'])->prefix('project-admin')->name('padmin.')->group(function () {
    Route::get('/', [ProjectAdminController::class, 'index'])->name('index');
    Route::get('/members', [ProjectAdminController::class, 'members'])->name('members');
    Route::post('/members', [ProjectAdminController::class, 'addMember'])->name('members.store');
    Route::put('/members/{user}', [ProjectAdminController::class, 'setRoles'])->whereNumber('user')->name('members.update');
    Route::delete('/members/{user}', [ProjectAdminController::class, 'removeMember'])->whereNumber('user')->name('members.destroy');
    Route::get('/trash', [ProjectAdminController::class, 'trash'])->name('trash');
    Route::post('/trash/flows/{id}/restore', [ProjectAdminController::class, 'restoreFlow'])->whereNumber('id')->name('trash.restore');
    Route::delete('/trash/flows/{id}', [ProjectAdminController::class, 'purgeFlow'])->whereNumber('id')->name('trash.purge');
    Route::post('/trash/meetings/{id}/restore', [ProjectAdminController::class, 'restoreMeeting'])->whereNumber('id')->name('trash.meetings.restore');
    Route::delete('/trash/meetings/{id}', [ProjectAdminController::class, 'purgeMeeting'])->whereNumber('id')->name('trash.meetings.purge');
    Route::get('/events', [ProjectAdminController::class, 'events'])->name('events');
});
