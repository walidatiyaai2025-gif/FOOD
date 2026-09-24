<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
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

    Route::middleware(['auth:sanctum', 'active.user'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/profile', [AuthController::class, 'profile']);
    });
});
