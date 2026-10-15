<?php

use App\Http\Controllers\Admin\SmartOrderingController;
use App\Http\Controllers\Admin\SmartOrderingCustomerGrowthController;
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
        Route::post('/sites/{site}/custom-domain/verify', [SmartOrderingController::class, 'verifyCustomDomain'])->whereNumber('site')->name('custom-domain.verify');
        Route::post('/sites/{site}/draft', [SmartOrderingController::class, 'draft'])->whereNumber('site')->name('draft');
        Route::put('/revisions/{revision}', [SmartOrderingController::class, 'updateDraft'])->whereNumber('revision')->name('revisions.update');
        Route::post('/revisions/{revision}/publish', [SmartOrderingController::class, 'publish'])->whereNumber('revision')->name('revisions.publish');

        Route::get('/sites/{site}/customers/{customer}', [SmartOrderingCustomerGrowthController::class, 'profile'])
            ->whereNumber('site')->whereNumber('customer')->name('customers.show');
        Route::get('/sites/{site}/customers/{customer}/orders', [SmartOrderingCustomerGrowthController::class, 'history'])
            ->whereNumber('site')->whereNumber('customer')->name('customers.orders');
        Route::post('/sites/{site}/customers/{customer}/orders/{order}/repeat', [SmartOrderingCustomerGrowthController::class, 'repeat'])
            ->whereNumber('site')->whereNumber('customer')->whereNumber('order')->name('customers.repeat');
        Route::get('/sites/{site}/customers/{customer}/loyalty', [SmartOrderingCustomerGrowthController::class, 'loyaltyBalance'])
            ->whereNumber('site')->whereNumber('customer')->name('customers.loyalty');
        Route::post('/sites/{site}/customers/{customer}/loyalty/adjust', [SmartOrderingCustomerGrowthController::class, 'loyaltyAdjust'])
            ->whereNumber('site')->whereNumber('customer')->name('customers.loyalty.adjust');
        Route::put('/sites/{site}/loyalty/rule', [SmartOrderingCustomerGrowthController::class, 'loyaltyRule'])
            ->whereNumber('site')->name('loyalty.rule');
        Route::get('/sites/{site}/loyalty/rewards', [SmartOrderingCustomerGrowthController::class, 'loyaltyRewards'])
            ->whereNumber('site')->name('loyalty.rewards');
        Route::post('/sites/{site}/loyalty/rewards', [SmartOrderingCustomerGrowthController::class, 'loyaltyRewardStore'])
            ->whereNumber('site')->name('loyalty.rewards.store');
        Route::put('/sites/{site}/loyalty/rewards/{reward}', [SmartOrderingCustomerGrowthController::class, 'loyaltyRewardUpdate'])
            ->whereNumber('site')->whereUuid('reward')->name('loyalty.rewards.update');
        Route::put('/sites/{site}/reviews/settings', [SmartOrderingCustomerGrowthController::class, 'reviewSettings'])
            ->whereNumber('site')->name('reviews.settings');
        Route::get('/sites/{site}/service-recovery', [SmartOrderingCustomerGrowthController::class, 'recoveryCases'])
            ->whereNumber('site')->name('service-recovery.index');
        Route::post('/sites/{site}/service-recovery/{case}/resolve', [SmartOrderingCustomerGrowthController::class, 'resolveRecovery'])
            ->whereNumber('site')->whereUuid('case')->name('service-recovery.resolve');
    });
