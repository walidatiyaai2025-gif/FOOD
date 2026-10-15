<?php

use App\Http\Controllers\Admin\SmartOrderingMerchandisingAdminController;
use App\Http\Controllers\Admin\SmartOrderingOperationsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SmartOrderingOperationsController::class, 'index'])->name('operations.index');
Route::post('/branches', [SmartOrderingOperationsController::class, 'storeBranch'])->name('branches.store');
Route::put('/branches/{branch}', [SmartOrderingOperationsController::class, 'updateBranch'])
    ->whereUuid('branch')->name('branches.update');
Route::post('/branches/{branch}/delivery-zones', [SmartOrderingOperationsController::class, 'storeZone'])
    ->whereUuid('branch')->name('zones.store');
Route::put('/branches/{branch}/delivery-zones/{zone}', [SmartOrderingOperationsController::class, 'updateZone'])
    ->whereUuid('branch')->whereUuid('zone')->name('zones.update');
Route::delete('/branches/{branch}/delivery-zones/{zone}', [SmartOrderingOperationsController::class, 'destroyZone'])
    ->whereUuid('branch')->whereUuid('zone')->name('zones.destroy');
Route::post('/orders/{reference}/status', [SmartOrderingOperationsController::class, 'transition'])
    ->whereUuid('reference')->name('orders.transition');

Route::get('/sites/{site}/merchandising', [SmartOrderingMerchandisingAdminController::class, 'index'])
    ->name('merchandising.index');
Route::post('/sites/{site}/merchandising/offers', [SmartOrderingMerchandisingAdminController::class, 'storeOffer'])
    ->name('merchandising.offers.store');
Route::put('/sites/{site}/merchandising/offers/{offer}', [SmartOrderingMerchandisingAdminController::class, 'updateOffer'])
    ->whereUuid('offer')->name('merchandising.offers.update');
Route::post('/sites/{site}/merchandising/recommendations', [SmartOrderingMerchandisingAdminController::class, 'storeRecommendation'])
    ->name('merchandising.recommendations.store');
Route::put('/sites/{site}/merchandising/recommendations/{recommendation}', [SmartOrderingMerchandisingAdminController::class, 'updateRecommendation'])
    ->whereUuid('recommendation')->name('merchandising.recommendations.update');
