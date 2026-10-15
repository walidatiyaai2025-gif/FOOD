<?php

use App\Http\Controllers\Api\V1\PublicSmartOrderingController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/smart-ordering/{slug}', [PublicSmartOrderingController::class, 'show'])
    ->where('slug', '[a-z0-9][a-z0-9-]{0,63}')
    ->middleware('throttle:120,1')
    ->name('api.smart-ordering.site');
