<?php

use App\Http\Controllers\Issue\IssueController;
use App\Http\Controllers\Pm\PmController;
use App\Http\Controllers\Pm\PmIssueCreateController;
use App\Http\Controllers\Pm\PmIssueImportController;
use App\Http\Controllers\Pm\PmTicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'active', 'project'])->group(function () {
    Route::get('/pm', [PmController::class, 'index'])->name('pm.index');
    Route::get('/pm/export', [PmController::class, 'export'])->name('pm.export');
    Route::post('/pm/due/{id}/review', [PmController::class, 'reviewDue'])->whereNumber('id')->name('pm.due.review');

    Route::get('/pm/tickets/{id}', [PmTicketController::class, 'show'])->whereNumber('id')->name('pm.tickets.show');
    Route::post('/pm/tickets/{id}/accept', [PmTicketController::class, 'accept'])->whereNumber('id')->name('pm.tickets.accept');
    Route::post('/pm/tickets/{id}/ack', [PmTicketController::class, 'ack'])->whereNumber('id')->name('pm.tickets.ack');
    Route::post('/pm/tickets/{id}/reply', [PmTicketController::class, 'reply'])->whereNumber('id')->name('pm.tickets.reply');
    Route::post('/pm/tickets/{id}/settle', [PmTicketController::class, 'settle'])->whereNumber('id')->name('pm.tickets.settle');
    Route::post('/pm/tickets/{id}/issue', [PmTicketController::class, 'createIssue'])->whereNumber('id')->name('pm.tickets.issue');
    Route::post('/pm/tickets/{id}/link', [PmTicketController::class, 'link'])->whereNumber('id')->name('pm.tickets.link');
    Route::post('/pm/tickets/{id}/quote', [PmTicketController::class, 'quote'])->whereNumber('id')->name('pm.tickets.quote');

    Route::get('/pm/issues/import', [PmIssueImportController::class, 'index'])->name('pm.issues.import');
    Route::get('/pm/issues/import/template', [PmIssueImportController::class, 'template'])->name('pm.issues.import.template');
    Route::post('/pm/issues/import/preview', [PmIssueImportController::class, 'preview'])->name('pm.issues.import.preview');
    Route::get('/pm/issues/import/{file}/media', [PmIssueImportController::class, 'media'])->whereNumber('file')->name('pm.issues.import.media');
    Route::post('/pm/issues/import/apply', [PmIssueImportController::class, 'apply'])->name('pm.issues.import.apply');

    Route::get('/pm/issues/create', [PmIssueCreateController::class, 'create'])->name('pm.issues.create');
    Route::post('/pm/issues', [PmIssueCreateController::class, 'store'])->name('pm.issues.store');
    Route::get('/pm/issues/{id}', fn (int $id) => redirect()->route('issues.show', $id))->whereNumber('id');
    Route::post('/pm/issues/bulk-delete', [IssueController::class, 'bulkDestroy'])->name('issues.bulk-destroy');

    Route::get('/issues/{id}', [IssueController::class, 'show'])->whereNumber('id')->name('issues.show');
    Route::delete('/issues/{id}', [IssueController::class, 'destroy'])->whereNumber('id')->name('issues.destroy');
    Route::post('/issues/{id}/transition', [IssueController::class, 'transition'])->whereNumber('id')->name('issues.transition');
    Route::post('/issues/{id}/fields', [IssueController::class, 'fields'])->whereNumber('id')->name('issues.fields');
    Route::post('/issues/{id}/due', [IssueController::class, 'setDue'])->whereNumber('id')->name('issues.due');
    Route::post('/issues/{id}/due-request', [IssueController::class, 'requestDue'])->whereNumber('id')->name('issues.due_request');
    Route::post('/issues/{id}/addendum', [IssueController::class, 'addendum'])->whereNumber('id')->name('issues.addendum');
    Route::post('/issues/{id}/comment', [IssueController::class, 'comment'])->whereNumber('id')->name('issues.comment');
});
