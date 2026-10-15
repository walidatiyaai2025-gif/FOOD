<?php

use App\Http\Controllers\Admin\SmartOrderingOperationsController;
use Illuminate\Support\Facades\Route;

Route::get('/operations', [SmartOrderingOperationsController::class, 'index'])->name('operations.index');
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
