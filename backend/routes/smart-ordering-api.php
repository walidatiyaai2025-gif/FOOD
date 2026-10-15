<?php

use App\Http\Controllers\Api\SmartOrderingAnalyticsEventController;
use App\Http\Controllers\Api\SmartOrderingController;
use App\Http\Controllers\Api\SmartOrderingMerchandisingController;
use Illuminate\Support\Facades\Route;

Route::post('/carts', [SmartOrderingController::class, 'createCart'])->name('carts.create');
Route::get('/carts/{cart}', [SmartOrderingController::class, 'showCart'])->whereUuid('cart')->name('carts.show');
Route::post('/carts/{cart}/items', [SmartOrderingController::class, 'addItem'])->whereUuid('cart')->name('carts.items.add');
Route::patch('/carts/{cart}/items/{item}', [SmartOrderingController::class, 'updateItem'])
    ->whereUuid('cart')->whereUuid('item')->name('carts.items.update');
Route::delete('/carts/{cart}/items/{item}', [SmartOrderingController::class, 'removeItem'])
    ->whereUuid('cart')->whereUuid('item')->name('carts.items.remove');
Route::post('/carts/{cart}/checkout', [SmartOrderingController::class, 'checkout'])
    ->whereUuid('cart')->name('carts.checkout');
Route::get('/orders/{reference}', [SmartOrderingController::class, 'tracking'])
    ->whereUuid('reference')->name('orders.tracking');

Route::get('/carts/{cart}/merchandising', [SmartOrderingMerchandisingController::class, 'placement'])
    ->whereUuid('cart')->name('carts.merchandising.placement');
Route::post('/carts/{cart}/merchandising/events', [SmartOrderingMerchandisingController::class, 'event'])
    ->whereUuid('cart')->name('carts.merchandising.event');

Route::post('/analytics/events', [SmartOrderingAnalyticsEventController::class, 'store'])->name('analytics.events.store');
