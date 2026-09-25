<?php

use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Api\V1\NotificationController as ApiNotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'management.dashboard'])
    ->prefix('admin/notifications')
    ->name('admin.notifications.')
    ->group(function (): void {
        Route::get('/', [AdminNotificationController::class, 'index'])->name('index');
        Route::post('/', [AdminNotificationController::class, 'store'])->name('store');
        Route::patch('/{notification}', [AdminNotificationController::class, 'update'])->name('update');
        Route::post('/{notification}/publish', [AdminNotificationController::class, 'publish'])->name('publish');
        Route::delete('/{notification}', [AdminNotificationController::class, 'destroy'])->name('destroy');
    });

Route::middleware(['api', 'auth:sanctum', 'active.user'])
    ->prefix('api/v1/notifications')
    ->name('api.notifications.')
    ->group(function (): void {
        Route::get('/', [ApiNotificationController::class, 'index'])->name('index');
        Route::get('/{notification}', [ApiNotificationController::class, 'show'])->name('show');
        Route::post('/{notification}/read', [ApiNotificationController::class, 'markRead'])->name('read');
    });
