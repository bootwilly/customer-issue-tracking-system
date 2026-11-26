<?php

use App\Http\Controllers\Revision\RevisionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'active', 'project'])->group(function () {
    Route::get('tickets/{id}/revisions/{rev}/diff', [RevisionController::class, 'diff'])
        ->whereNumber('id')->whereNumber('rev')->name('tickets.revisions.diff');
});
