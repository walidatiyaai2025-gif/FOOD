<?php

use App\Http\Controllers\Api\V1\PublicSmartOrderingController;
use App\Http\Controllers\Api\V1\SmartOrderingCustomerController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/smart-ordering/context', [PublicSmartOrderingController::class, 'context'])
    ->middleware(['throttle:120,1', 'smart.ordering.site'])
    ->name('api.smart-ordering.context');

Route::prefix('/v1/smart-ordering/customer')
    ->middleware(['auth:sanctum', 'active.user', 'throttle:120,1', 'smart.ordering.site'])
    ->group(function (): void {
        Route::get('/orders', [SmartOrderingCustomerController::class, 'orders'])
            ->name('api.smart-ordering.customer.orders');
        Route::post('/orders/{orderNumber}/repeat', [SmartOrderingCustomerController::class, 'repeat'])
            ->where('orderNumber', '[A-Za-z0-9._-]+')
            ->name('api.smart-ordering.customer.orders.repeat');
        Route::get('/loyalty', [SmartOrderingCustomerController::class, 'loyalty'])
            ->name('api.smart-ordering.customer.loyalty');
        Route::post('/loyalty/rewards/{reward}/redeem', [SmartOrderingCustomerController::class, 'redeem'])
            ->whereUuid('reward')
            ->name('api.smart-ordering.customer.loyalty.redeem');
        Route::post('/orders/{orderNumber}/feedback', [SmartOrderingCustomerController::class, 'feedback'])
            ->where('orderNumber', '[A-Za-z0-9._-]+')
            ->name('api.smart-ordering.customer.feedback');
    });
