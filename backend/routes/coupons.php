<?php

use App\Http\Controllers\Admin\CouponManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'management.dashboard'])
    ->prefix('admin/coupons')
    ->name('admin.coupons.')
    ->group(function (): void {
        Route::get('/', [CouponManagementController::class, 'index'])->name('index');
        Route::post('/', [CouponManagementController::class, 'store'])->name('store');
        Route::patch('/{coupon}', [CouponManagementController::class, 'update'])->name('update');
        Route::patch('/{coupon}/status', [CouponManagementController::class, 'toggle'])->name('toggle');
        Route::delete('/{coupon}', [CouponManagementController::class, 'destroy'])->name('destroy');
    });
