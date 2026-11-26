<?php

use App\Http\Controllers\Collab\CollabController;
use Illuminate\Support\Facades\Route;

// needinfo, watch, duplicate hint, saved queries, issue tags, issue dependencies
Route::middleware(['web', 'auth', 'active', 'project'])->group(function () {
    Route::post('/tickets/{id}/watch', [CollabController::class, 'watchTicket'])->whereNumber('id')->name('collab.tickets.watch');
    Route::post('/issues/{id}/watch', [CollabController::class, 'watchIssue'])->whereNumber('id')->name('collab.issues.watch');

    Route::post('/pm/tickets/{id}/needinfo', [CollabController::class, 'requestInfo'])->whereNumber('id')->name('collab.needinfo.request');
    Route::delete('/pm/tickets/{id}/needinfo', [CollabController::class, 'withdrawInfo'])->whereNumber('id')->name('collab.needinfo.withdraw');

    Route::get('/similar/tickets', [CollabController::class, 'similarTickets'])->name('collab.similar.tickets');
    Route::get('/similar/internal', [CollabController::class, 'similarInternal'])->name('collab.similar.internal');

    Route::post('/saved-queries', [CollabController::class, 'saveQuery'])->name('collab.queries.store');
    Route::post('/saved-queries/{id}/rename', [CollabController::class, 'renameQuery'])->whereNumber('id')->name('collab.queries.rename');
    Route::post('/saved-queries/{id}/move', [CollabController::class, 'moveQuery'])->whereNumber('id')->name('collab.queries.move');
    Route::delete('/saved-queries/{id}', [CollabController::class, 'deleteQuery'])->whereNumber('id')->name('collab.queries.destroy');

    Route::post('/issues/{id}/tags', [CollabController::class, 'tags'])->whereNumber('id')->name('collab.issues.tags');
    Route::post('/issues/{id}/dependencies', [CollabController::class, 'addDependency'])->whereNumber('id')->name('collab.deps.store');
    Route::delete('/issues/{id}/dependencies/{dep}', [CollabController::class, 'removeDependency'])->whereNumber(['id', 'dep'])->name('collab.deps.destroy');
});
