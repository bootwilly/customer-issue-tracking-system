<?php

use App\Http\Controllers\Admin\ExportSettingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'active', 'admin'])->prefix('admin/settings/export')->name('admin.export.')->group(function () {
    Route::get('/', [ExportSettingController::class, 'edit'])->name('edit');
    Route::put('/', [ExportSettingController::class, 'update'])->name('update');
});
