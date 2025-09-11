<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sales/products', [\App\Http\Controllers\SaleController::class, 'products'])->name('sales.products');
    Route::post('/sales/draft', [\App\Http\Controllers\SaleController::class, 'storeDraft'])->name('sales.draft');
    Route::post('/sales/{sale}/sync', [\App\Http\Controllers\SaleController::class, 'syncDraft'])->name('sales.syncDraft');  
});