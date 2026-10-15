<?php

use App\Http\Controllers\Admin\SmartOrderingOperationsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SmartOrderingOperationsController::class, 'index'])->name('index');
Route::post('/branches', [SmartOrderingOperationsController::class, 'storeBranch'])->name('branches.store');
Route::put('/branches/{branch}', [SmartOrderingOperationsController::class, 'updateBranch'])
    ->whereUuid('branch')->name('branches.update');
Route::post('/orders/{reference}/status', [SmartOrderingOperationsController::class, 'transition'])
    ->whereUuid('reference')->name('orders.transition');
