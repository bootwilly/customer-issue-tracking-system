<?php

use App\Http\Controllers\Mes\MesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'active', 'project'])->prefix('mes')->name('mes.')->group(function () {
    Route::get('/', [MesController::class, 'index'])->name('index');
    Route::post('/', [MesController::class, 'store'])->name('store');
    Route::post('/import/preview', [MesController::class, 'importPreview'])->name('import.preview');
    Route::post('/import/apply', [MesController::class, 'importApply'])->name('import.apply');
    Route::post('/sitemap/preview', [MesController::class, 'sitemapPreview'])->name('sitemap.preview');
    Route::post('/sitemap/apply', [MesController::class, 'sitemapApply'])->name('sitemap.apply');
    Route::put('/{id}', [MesController::class, 'update'])->whereNumber('id')->name('update');
    Route::post('/{id}/toggle', [MesController::class, 'toggle'])->whereNumber('id')->name('toggle');
});
