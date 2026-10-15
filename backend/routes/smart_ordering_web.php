<?php

use App\Http\Controllers\Admin\SmartOrderingController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/smart-ordering')
    ->name('admin.smart-ordering.')
    ->middleware('management.dashboard')
    ->group(function (): void {
        Route::get('/', [SmartOrderingController::class, 'index'])->name('index');
        Route::put('/sites/{site}/runtime', [SmartOrderingController::class, 'runtime'])->whereNumber('site')->name('runtime');
        Route::put('/sites/{site}/entitlements', [SmartOrderingController::class, 'entitlements'])->whereNumber('site')->name('entitlements');
        Route::put('/sites/{site}/slug', [SmartOrderingController::class, 'slug'])->whereNumber('site')->name('slug');
        Route::put('/sites/{site}/custom-domain', [SmartOrderingController::class, 'customDomain'])->whereNumber('site')->name('custom-domain');
        Route::post('/sites/{site}/draft', [SmartOrderingController::class, 'draft'])->whereNumber('site')->name('draft');
        Route::put('/revisions/{revision}', [SmartOrderingController::class, 'updateDraft'])->whereNumber('revision')->name('revisions.update');
        Route::post('/revisions/{revision}/publish', [SmartOrderingController::class, 'publish'])->whereNumber('revision')->name('revisions.publish');
    });
