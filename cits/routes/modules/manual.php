<?php

use App\Http\Controllers\Manual\ManualController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'active'])->prefix('manual')->name('manual.')->group(function () {
    Route::get('/img/{file}', [ManualController::class, 'image'])->where('file', '[a-z0-9-]+\.png')->name('image');
    Route::get('/{chapter?}', [ManualController::class, 'show'])->where('chapter', 'README|\d\d-[a-z-]+')->name('show');
});
