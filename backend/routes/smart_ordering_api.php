<?php

use App\Http\Controllers\Api\V1\PublicSmartOrderingController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/smart-ordering/context', [PublicSmartOrderingController::class, 'context'])
    ->middleware(['throttle:120,1', 'smart.ordering.site'])
    ->name('api.smart-ordering.context');
