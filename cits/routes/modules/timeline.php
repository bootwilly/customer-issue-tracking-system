<?php

use App\Http\Controllers\Timeline\TimelineController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'active', 'project'])->group(function () {
    Route::get('/timeline', [TimelineController::class, 'index'])->name('timeline.index');
});
