<?php

use App\Http\Controllers\Admin\LookupController;
use Illuminate\Support\Facades\Route;

// 系統管理 → 選項管理: categories listed in lookup "lookup_editable"
Route::middleware(['web', 'auth', 'active', 'admin'])->prefix('admin/lookups')->name('admin.lookups.')->group(function () {
    Route::get('/{category?}', [LookupController::class, 'index'])->name('index');
    Route::post('/{category}', [LookupController::class, 'store'])->name('store');
    Route::put('/{category}/{id}', [LookupController::class, 'update'])->whereNumber('id')->name('update');
    Route::post('/{category}/{id}/toggle', [LookupController::class, 'toggle'])->whereNumber('id')->name('toggle');
    Route::post('/{category}/{id}/move', [LookupController::class, 'move'])->whereNumber('id')->name('move');
    Route::delete('/{category}/{id}', [LookupController::class, 'destroy'])->whereNumber('id')->name('destroy');
});
