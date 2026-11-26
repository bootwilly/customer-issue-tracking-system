<?php

use App\Http\Controllers\Flow\FlowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'active', 'project'])->prefix('flows')->name('flows.')->group(function () {
    Route::get('/', [FlowController::class, 'index'])->name('index');
    Route::post('/preview', [FlowController::class, 'preview'])->name('preview');
    Route::post('/', [FlowController::class, 'store'])->name('store');
    Route::get('/create', [FlowController::class, 'create'])->name('create');
    Route::get('/{id}', [FlowController::class, 'show'])->whereNumber('id')->name('show');
    Route::put('/{id}', [FlowController::class, 'update'])->whereNumber('id')->name('update');
    Route::delete('/{id}', [FlowController::class, 'destroy'])->whereNumber('id')->name('destroy');
    Route::get('/{id}/edit', [FlowController::class, 'edit'])->whereNumber('id')->name('edit');
    Route::get('/{id}/image', [FlowController::class, 'image'])->whereNumber('id')->name('image');
    Route::get('/{id}/download', [FlowController::class, 'download'])->whereNumber('id')->name('download');
    Route::post('/{id}/publish', [FlowController::class, 'publish'])->whereNumber('id')->name('publish');
    Route::post('/{id}/discard', [FlowController::class, 'discard'])->whereNumber('id')->name('discard');
    Route::post('/{id}/status', [FlowController::class, 'status'])->whereNumber('id')->name('status');
    Route::post('/{id}/editors', [FlowController::class, 'editors'])->whereNumber('id')->name('editors');
    Route::post('/{id}/sort', [FlowController::class, 'sort'])->whereNumber('id')->name('sort');
    Route::post('/{id}/snapshots/{snapshot}/restore', [FlowController::class, 'restoreSnapshot'])->whereNumber(['id', 'snapshot'])->name('snapshots.restore');
});
