<?php

use App\Http\Controllers\Admin\SmartOrderingThemePreviewController;
use App\Http\Controllers\SmartOrderingSitemapController;
use App\Http\Controllers\SmartOrderingStorefrontController;
use Illuminate\Support\Facades\Route;

Route::get('/smart-ordering/{siteKey}/sitemap.xml', SmartOrderingSitemapController::class)
    ->where('siteKey', '[A-Za-z0-9][A-Za-z0-9_-]{1,99}')
    ->middleware('throttle:120,1')
    ->name('public.smart-ordering.sitemap');

Route::get('/smart-ordering/{siteKey}', SmartOrderingStorefrontController::class)
    ->where('siteKey', '[A-Za-z0-9][A-Za-z0-9_-]{1,99}')
    ->middleware('throttle:120,1')
    ->name('public.smart-ordering.storefront');

Route::prefix('admin/smart-ordering')
    ->name('admin.smart-ordering.')
    ->middleware('management.dashboard')
    ->group(function (): void {
        Route::get('/appearance', [SmartOrderingThemePreviewController::class, 'index'])->name('appearance.index');
        Route::get('/appearance/preview', [SmartOrderingThemePreviewController::class, 'preview'])
            ->middleware('throttle:120,1')
            ->name('appearance.preview');
        Route::get('/appearance/demo-preview', [SmartOrderingThemePreviewController::class, 'demo'])
            ->middleware('throttle:120,1')
            ->name('appearance.demo-preview');
    });
