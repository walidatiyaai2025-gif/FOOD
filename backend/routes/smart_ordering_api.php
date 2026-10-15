<?php

use App\Http\Controllers\Api\V1\PublicSmartOrderingController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/smart-ordering/context', [PublicSmartOrderingController::class, 'context'])
    ->middleware(['throttle:120,1', 'smart.ordering.site'])
    ->name('api.smart-ordering.context');

Route::get('/v1/smart-ordering/{slug}', [PublicSmartOrderingController::class, 'show'])
    ->where('slug', '[a-z0-9][a-z0-9-]{0,62}[a-z0-9]|[a-z0-9]')
    ->middleware('throttle:120,1')
    ->name('api.smart-ordering.site');
