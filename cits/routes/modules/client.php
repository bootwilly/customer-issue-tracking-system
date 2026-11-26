<?php

use App\Http\Controllers\Client\TicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'active', 'project'])->prefix('tickets')->name('tickets.')->group(function () {
    Route::get('/', [TicketController::class, 'index'])->name('index');
    Route::get('export', [TicketController::class, 'export'])->name('export');
    Route::get('create', [TicketController::class, 'create'])->name('create');
    Route::post('/', [TicketController::class, 'store'])->name('store');
    Route::get('{id}', [TicketController::class, 'show'])->whereNumber('id')->name('show');
    Route::get('{id}/edit', [TicketController::class, 'edit'])->whereNumber('id')->name('edit');
    Route::put('{id}', [TicketController::class, 'update'])->whereNumber('id')->name('update');
    Route::post('{id}/heartbeat', [TicketController::class, 'heartbeat'])->whereNumber('id')->name('heartbeat');
    Route::post('{id}/release', [TicketController::class, 'release'])->whereNumber('id')->name('release');
    Route::post('{id}/comment', [TicketController::class, 'comment'])->whereNumber('id')->name('comment');
    Route::post('{id}/cancel', [TicketController::class, 'cancel'])->whereNumber('id')->name('cancel');
    Route::post('{id}/close', [TicketController::class, 'close'])->whereNumber('id')->name('close');
    Route::post('{id}/reopen', [TicketController::class, 'reopen'])->whereNumber('id')->name('reopen');
});
