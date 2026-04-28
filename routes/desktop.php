<?php

use App\Http\Controllers\Desktop\DesktopAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('desktop')->name('desktop.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [DesktopAuthController::class, 'create'])->name('login');
        Route::post('login', [DesktopAuthController::class, 'store'])->name('login.store');
    });
});
