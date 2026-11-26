<?php

use App\Http\Controllers\Notify\AccountSettingsController;
use App\Http\Controllers\Notify\MailSettingController;
use App\Http\Controllers\Notify\NotificationController;
use App\Http\Controllers\Notify\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'guest'])->group(function () {
    Route::get('/password/forgot', [PasswordResetController::class, 'forgot'])->name('password.forgot');
    Route::post('/password/forgot', [PasswordResetController::class, 'send'])->name('password.forgot.send');
    Route::get('/password/reset/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/password/reset/{token}', [PasswordResetController::class, 'reset'])->name('password.reset.store');
});

Route::middleware(['web', 'auth', 'active'])->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.readAll');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id')->name('notifications.read');

    Route::get('/account/settings', [AccountSettingsController::class, 'edit'])->name('account.settings');
    Route::put('/account/settings', [AccountSettingsController::class, 'update'])->name('account.settings.update');
});

Route::middleware(['web', 'auth', 'active', 'admin'])->prefix('admin/settings/mail')->name('admin.mail.')->group(function () {
    Route::get('/', [MailSettingController::class, 'edit'])->name('edit');
    Route::put('/', [MailSettingController::class, 'update'])->name('update');
    Route::post('test', [MailSettingController::class, 'test'])->name('test');
});
