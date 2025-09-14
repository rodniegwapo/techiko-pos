<?php

use App\Http\Controllers\Product\ProductDiscountController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return Inertia::render('Auth/Login');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Route::middleware('auth')->group(function () {
//     Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
//     Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
//     Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
// });





Route::middleware(['auth'])->group(function () {
    Route::resource('categories', \App\Http\Controllers\CategoryController::class)->names('categories');
    Route::resource('products', \App\Http\Controllers\Products\ProductController::class)->names('products');
    Route::name('products.')->group(function () {
        Route::resource('discounts', \App\Http\Controllers\Products\DiscountController::class)
            ->names('discounts');
    });

    Route::get('/sales', [\App\Http\Controllers\SaleController::class, 'index'])->name('sales.index');
    // terminal
    Route::post('/setup-terminal', [\App\Http\Controllers\TerminalController::class, 'setupTerminal'])->name('setup.terminal');

    // Void logs
    Route::get('/void-logs', [\App\Http\Controllers\VoidLogController::class, 'index'])->name('voids.index');
});

require __DIR__ . '/auth.php';
