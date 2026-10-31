<?php

use App\Http\Controllers\InternalController;
use App\Http\Controllers\PrivateController;
use App\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

/*
 * /0/public/* and /0/private/* mirror Kraken's own real path prefix
 * exactly (https://api.kraken.com/0/...) so a real client just needs its
 * base URL repointed at this service, nothing else.
 */
Route::prefix('0/public')->group(function () {
    Route::get('/Ticker', [PublicController::class, 'ticker']);
    Route::get('/Time', [PublicController::class, 'time']);
    Route::get('/SystemStatus', [PublicController::class, 'systemStatus']);
});

Route::prefix('0/private')->middleware('kraken.signed')->group(function () {
    Route::post('/Balance', [PrivateController::class, 'balance']);
    Route::post('/TradeBalance', [PrivateController::class, 'tradeBalance']);
    Route::post('/AddOrder', [PrivateController::class, 'addOrder']);
    Route::post('/CancelOrder', [PrivateController::class, 'cancelOrder']);
    Route::post('/OpenOrders', [PrivateController::class, 'openOrders']);
    Route::post('/QueryOrders', [PrivateController::class, 'queryOrders']);
});

// This simulator's own control surface — see InternalController's own
// docblock. Never shares a trust boundary with the signed routes above.
Route::prefix('internal')->middleware('bearer.auth')->group(function () {
    Route::post('/prices', [InternalController::class, 'setPrice']);
    Route::get('/prices', [InternalController::class, 'listPrices']);
    Route::post('/balances', [InternalController::class, 'setBalance']);
    Route::match(['get', 'post'], '/settings', [InternalController::class, 'settings']);
    Route::get('/credentials', [InternalController::class, 'credentials']);
    Route::get('/orders', [InternalController::class, 'orders']);
});
