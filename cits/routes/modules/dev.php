<?php

use App\Http\Controllers\Dev\DevController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'active', 'project'])->group(function () {
    Route::get('/dev', [DevController::class, 'index'])->name('dev.index');
    Route::get('/dev/export', [DevController::class, 'export'])->name('dev.export');
    Route::get('/dev/issues/create', [DevController::class, 'create'])->name('dev.issues.create');
    Route::post('/dev/issues', [DevController::class, 'store'])->name('dev.issues.store');
    Route::post('/dev/packages', [DevController::class, 'storePackage'])->name('dev.packages.store');
    Route::post('/dev/packages/{id}/issues', [DevController::class, 'addToPackage'])->whereNumber('id')->name('dev.packages.issues.add');
    Route::delete('/dev/packages/{id}/issues/{issueId}', [DevController::class, 'removeFromPackage'])->whereNumber(['id', 'issueId'])->name('dev.packages.issues.remove');
    // Notification links point here; the shared detail page lives at /issues/{id}.
    Route::get('/dev/issues/{id}', fn (int $id) => redirect('/issues/'.$id))->whereNumber('id')->name('dev.issues.show');
});
