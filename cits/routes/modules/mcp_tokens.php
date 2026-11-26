<?php

use App\Http\Controllers\Account\ApiTokenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'active'])->group(function () {
    Route::get('/account/tokens', [ApiTokenController::class, 'index'])->name('account.tokens');
    Route::post('/account/tokens', [ApiTokenController::class, 'store'])->name('account.tokens.store');
    Route::post('/account/tokens/{id}/revoke', [ApiTokenController::class, 'revoke'])->whereNumber('id')->name('account.tokens.revoke');
});
