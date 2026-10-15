<?php

use App\Http\Controllers\Api\V1\SmartOrderingMenuController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/smart-ordering/{slug}')
    ->where(['slug' => '[a-z0-9][a-z0-9-]{0,63}'])
    ->middleware('throttle:120,1')
    ->name('api.smart-ordering.menu.')
    ->group(function (): void {
        Route::get('/menu', [SmartOrderingMenuController::class, 'menu'])->name('show');
        Route::get('/categories', [SmartOrderingMenuController::class, 'categories'])->name('categories');
        Route::get('/items', [SmartOrderingMenuController::class, 'items'])->name('items');
        Route::get('/items/{item}', [SmartOrderingMenuController::class, 'item'])
            ->whereUuid('item')->name('items.show');
        Route::post('/items/{item}/quote', [SmartOrderingMenuController::class, 'quote'])
            ->whereUuid('item')->name('items.quote');
    });
