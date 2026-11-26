<?php

use App\Http\Controllers\Meeting\MeetingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'active', 'project'])->prefix('meetings')->name('meetings.')->group(function () {
    Route::get('/', [MeetingController::class, 'index'])->name('index');
    Route::get('/export', [MeetingController::class, 'export'])->name('export');
    Route::get('/{id}/export', [MeetingController::class, 'exportOne'])->whereNumber('id')->name('export-one');
    Route::get('/create', [MeetingController::class, 'create'])->name('create');
    Route::post('/', [MeetingController::class, 'store'])->name('store');
    Route::post('/add-issue', [MeetingController::class, 'addIssue'])->name('add-issue');
    Route::post('/add-issues', [MeetingController::class, 'bulkAddIssues'])->name('add-issues');
    Route::get('/{id}', [MeetingController::class, 'show'])->whereNumber('id')->name('show');
    Route::post('/{id}/documents', [MeetingController::class, 'uploadDocument'])->whereNumber('id')->name('documents.upload');
    Route::get('/{id}/documents/{document}', [MeetingController::class, 'document'])->whereNumber(['id', 'document'])->name('documents.show');
    Route::get('/{id}/edit', [MeetingController::class, 'edit'])->whereNumber('id')->name('edit');
    Route::put('/{id}', [MeetingController::class, 'update'])->whereNumber('id')->name('update');
    Route::delete('/{id}', [MeetingController::class, 'destroy'])->whereNumber('id')->name('destroy');
    Route::post('/{id}/status', [MeetingController::class, 'status'])->whereNumber('id')->name('status');
    Route::post('/{id}/share', [MeetingController::class, 'share'])->whereNumber('id')->name('share');
    Route::post('/{id}/notes', [MeetingController::class, 'notes'])->whereNumber('id')->name('notes');
    Route::post('/{id}/next', [MeetingController::class, 'next'])->whereNumber('id')->name('next');
    Route::get('/{id}/ics', [MeetingController::class, 'ics'])->whereNumber('id')->name('ics');
    Route::post('/{id}/items', [MeetingController::class, 'storeItem'])->whereNumber('id')->name('items.store');
    Route::get('/{id}/issues', [MeetingController::class, 'issueSearch'])->whereNumber('id')->name('issues.search');
    Route::post('/{id}/items/issues', [MeetingController::class, 'addIssues'])->whereNumber('id')->name('items.issues');
    Route::put('/{id}/items/{item}', [MeetingController::class, 'updateItem'])->whereNumber(['id', 'item'])->name('items.update');
    Route::delete('/{id}/items/{item}', [MeetingController::class, 'destroyItem'])->whereNumber(['id', 'item'])->name('items.destroy');
    Route::post('/{id}/items/{item}/move', [MeetingController::class, 'moveItem'])->whereNumber(['id', 'item'])->name('items.move');
    Route::post('/{id}/comments', [MeetingController::class, 'storeComment'])->whereNumber('id')->name('comments.store');
    Route::put('/{id}/comments/{comment}', [MeetingController::class, 'updateComment'])->whereNumber(['id', 'comment'])->name('comments.update');
    Route::delete('/{id}/comments/{comment}', [MeetingController::class, 'destroyComment'])->whereNumber(['id', 'comment'])->name('comments.destroy');
});
