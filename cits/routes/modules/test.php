<?php

use App\Http\Controllers\Test\TestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'active', 'project'])->group(function () {
    Route::get('/test', [TestController::class, 'index'])->name('test.index');
    Route::get('/test/export', [TestController::class, 'export'])->name('test.export');
    Route::get('/test/issues/create', [TestController::class, 'create'])->name('test.issues.create');
    Route::post('/test/issues', [TestController::class, 'store'])->name('test.issues.store');
});
