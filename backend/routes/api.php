<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GuestCartController;
use App\Http\Controllers\Api\V1\GuestCatalogController;
use App\Http\Controllers\Api\V1\GuestStoreController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::get('/version', fn () => response()->json([
        'api' => 'v1',
        'platform_version' => trim((string) @file_get_contents(base_path('../VERSION'))),
    ]));

    Route::get('/app-version', fn () => response()->json([
        'status' => 'foundation',
        'message' => 'App version policy storage is prepared; policy evaluation is an implementation issue.',
    ]));

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    Route::get('/stores', [GuestStoreController::class, 'index']);
    Route::get('/stores/{store}/categories', [GuestCatalogController::class, 'categories']);
    Route::get('/stores/{store}/products', [GuestCatalogController::class, 'products']);
    Route::get('/stores/{store}/offers', [GuestCatalogController::class, 'offers']);
    Route::get('/products/{product}', [GuestCatalogController::class, 'product']);

    Route::get('/cart', [GuestCartController::class, 'show']);
    Route::post('/cart/items', [GuestCartController::class, 'addItem']);
    Route::patch('/cart/items/{item}', [GuestCartController::class, 'updateItem']);
    Route::delete('/cart/items/{item}', [GuestCartController::class, 'removeItem']);

    Route::middleware(['auth:sanctum', 'active.user'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/profile', [AuthController::class, 'profile']);

        Route::post('/checkout', static fn () => response()->json([
            'message' => 'Checkout execution is not implemented in the guest browsing foundation.',
        ], 501));
    });
});
