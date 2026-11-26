<?php

use App\Http\Controllers\Upload\AttachmentController;
use App\Http\Controllers\Upload\DraftController;
use App\Http\Controllers\Upload\UploadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::post('/uploads/image', [UploadController::class, 'image'])->name('uploads.image');
    Route::post('/uploads/init', [UploadController::class, 'init'])->name('uploads.init');
    Route::post('/uploads/{id}/chunk', [UploadController::class, 'chunk'])->name('uploads.chunk');
    Route::post('/uploads/{id}/complete', [UploadController::class, 'complete'])->name('uploads.complete');

    Route::get('/attachments/{id}', [AttachmentController::class, 'show'])->whereNumber('id')->name('attachments.show');

    Route::get('/drafts', [DraftController::class, 'show'])->name('drafts.show');
    Route::post('/drafts', [DraftController::class, 'store'])->name('drafts.store');
    Route::delete('/drafts', [DraftController::class, 'destroy'])->name('drafts.destroy');
});
