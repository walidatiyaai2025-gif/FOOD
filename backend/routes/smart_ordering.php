<?php

use App\Http\Controllers\Admin\SmartOrderingController;
use App\Http\Controllers\Api\V1\SmartOrderingPublicController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'management.dashboard'])
    ->prefix('admin/smart-ordering')
    ->name('admin.smart-ordering.')
    ->group(function (): void {
        Route::get('/stores/{store}', [SmartOrderingController::class, 'show'])->name('show');
        Route::post('/stores/{store}/provision', [SmartOrderingController::class, 'provision'])->name('provision');
        Route::patch('/stores/{store}/identity', [SmartOrderingController::class, 'identity'])->name('identity');
        Route::patch('/stores/{store}/runtime', [SmartOrderingController::class, 'runtime'])->name('runtime');
        Route::put('/stores/{store}/entitlements/{module}', [SmartOrderingController::class, 'entitlement'])->name('entitlement');
        Route::post('/stores/{store}/branches', [SmartOrderingController::class, 'branch'])->name('branches.store');
        Route::post('/stores/{store}/draft', [SmartOrderingController::class, 'createDraft'])->name('draft.create');
        Route::patch('/stores/{store}/draft/{revision}', [SmartOrderingController::class, 'updateDraft'])->name('draft.update');
        Route::post('/stores/{store}/draft/{revision}/publish', [SmartOrderingController::class, 'publish'])->name('draft.publish');
        Route::post('/stores/{store}/domains', [SmartOrderingController::class, 'requestDomain'])->name('domains.store');
        Route::post('/stores/{store}/domains/{domain}/verify', [SmartOrderingController::class, 'verifyDomain'])->name('domains.verify');
    });

Route::middleware(['api', 'throttle:120,1', 'smart.ordering.public'])
    ->prefix('api/v1/public/smart-ordering')
    ->name('api.v1.public.smart-ordering.')
    ->group(function (): void {
        Route::get('/context', [SmartOrderingPublicController::class, 'context'])->name('context');
    });
