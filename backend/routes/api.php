<?php

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
});
