<?php

use App\Http\Controllers\Admin\RestaurantGrowthController;
use App\Http\Controllers\Admin\RestaurantIntegrationController;
use App\Http\Controllers\Admin\RestaurantOnboardingController;
use App\Http\Controllers\Admin\SmartOrderingController;
use App\Http\Controllers\Admin\SmartOrderingCustomerGrowthController;
use App\Http\Controllers\Admin\SmartOrderingMenuAdminController;
use App\Http\Controllers\Admin\SmartOrderingSupplyController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/smart-ordering')
    ->name('admin.smart-ordering.')
    ->middleware('management.dashboard')
    ->group(function (): void {
        Route::get('/settings', [SmartOrderingController::class, 'index'])->name('index');
        Route::put('/sites/{site}/runtime', [SmartOrderingController::class, 'runtime'])->whereNumber('site')->name('runtime');
        Route::put('/sites/{site}/entitlements', [SmartOrderingController::class, 'entitlements'])->whereNumber('site')->name('entitlements');
        Route::put('/sites/{site}/slug', [SmartOrderingController::class, 'slug'])->whereNumber('site')->name('slug');
        Route::put('/sites/{site}/custom-domain', [SmartOrderingController::class, 'customDomain'])->whereNumber('site')->name('custom-domain');
        Route::post('/sites/{site}/custom-domain/verify', [SmartOrderingController::class, 'verifyCustomDomain'])->whereNumber('site')->name('custom-domain.verify');
        Route::post('/sites/{site}/draft', [SmartOrderingController::class, 'draft'])->whereNumber('site')->name('draft');
        Route::put('/revisions/{revision}', [SmartOrderingController::class, 'updateDraft'])->whereNumber('revision')->name('revisions.update');
        Route::put('/revisions/{revision}/content', [SmartOrderingController::class, 'updateContent'])->whereNumber('revision')->name('revisions.content');
        Route::post('/revisions/{revision}/publish', [SmartOrderingController::class, 'publish'])->whereNumber('revision')->name('revisions.publish');
        Route::put('/sites/{site}/onboarding/profile', [RestaurantOnboardingController::class, 'profile'])->whereNumber('site')->name('onboarding.profile');
        Route::post('/sites/{site}/onboarding/import', [RestaurantOnboardingController::class, 'import'])->whereNumber('site')->name('onboarding.import');
        Route::get('/sites/{site}/onboarding/template.csv', [RestaurantOnboardingController::class, 'template'])->whereNumber('site')->name('onboarding.template');
        Route::post('/sites/{site}/onboarding/launch', [RestaurantOnboardingController::class, 'launch'])->whereNumber('site')->name('onboarding.launch');

        Route::get('/menu', [SmartOrderingMenuAdminController::class, 'index'])->name('menu.index');
        Route::get('/menu/products', [SmartOrderingMenuAdminController::class, 'products'])->name('menu.products');
        Route::post('/menu/menus', [SmartOrderingMenuAdminController::class, 'storeMenu'])->name('menu.menus.store');
        Route::put('/menu/menus/{menu}', [SmartOrderingMenuAdminController::class, 'updateMenu'])->whereNumber('menu')->name('menu.menus.update');
        Route::delete('/menu/menus/{menu}', [SmartOrderingMenuAdminController::class, 'deleteMenu'])->whereNumber('menu')->name('menu.menus.destroy');
        Route::post('/menu/menus/{menu}/categories', [SmartOrderingMenuAdminController::class, 'storeCategory'])->whereNumber('menu')->name('menu.categories.store');
        Route::put('/menu/categories/{category}', [SmartOrderingMenuAdminController::class, 'updateCategory'])->whereNumber('category')->name('menu.categories.update');
        Route::delete('/menu/categories/{category}', [SmartOrderingMenuAdminController::class, 'deleteCategory'])->whereNumber('category')->name('menu.categories.destroy');
        Route::put('/menu/categories/{category}/branch', [SmartOrderingMenuAdminController::class, 'categoryBranch'])->whereNumber('category')->name('menu.categories.branch');
        Route::post('/menu/categories/{category}/items', [SmartOrderingMenuAdminController::class, 'storeItem'])->whereNumber('category')->name('menu.items.store');
        Route::put('/menu/items/{item}', [SmartOrderingMenuAdminController::class, 'updateItem'])->whereNumber('item')->name('menu.items.update');
        Route::delete('/menu/items/{item}', [SmartOrderingMenuAdminController::class, 'deleteItem'])->whereNumber('item')->name('menu.items.destroy');
        Route::put('/menu/items/{item}/branch', [SmartOrderingMenuAdminController::class, 'itemBranch'])->whereNumber('item')->name('menu.items.branch');
        Route::post('/menu/items/{item}/groups', [SmartOrderingMenuAdminController::class, 'storeGroup'])->whereNumber('item')->name('menu.groups.store');
        Route::put('/menu/groups/{group}', [SmartOrderingMenuAdminController::class, 'updateGroup'])->whereNumber('group')->name('menu.groups.update');
        Route::delete('/menu/groups/{group}', [SmartOrderingMenuAdminController::class, 'deleteGroup'])->whereNumber('group')->name('menu.groups.destroy');
        Route::post('/menu/groups/{group}/options', [SmartOrderingMenuAdminController::class, 'storeOption'])->whereNumber('group')->name('menu.options.store');
        Route::put('/menu/options/{option}', [SmartOrderingMenuAdminController::class, 'updateOption'])->whereNumber('option')->name('menu.options.update');
        Route::delete('/menu/options/{option}', [SmartOrderingMenuAdminController::class, 'deleteOption'])->whereNumber('option')->name('menu.options.destroy');
        Route::put('/menu/options/{option}/branch', [SmartOrderingMenuAdminController::class, 'optionBranch'])->whereNumber('option')->name('menu.options.branch');
        Route::put('/menu/bulk-availability', [SmartOrderingMenuAdminController::class, 'bulkAvailability'])->name('menu.bulk-availability');
        Route::post('/menu/revisions/{revision}/publish', [SmartOrderingMenuAdminController::class, 'publish'])->whereNumber('revision')->name('menu.publish');
        Route::post('/menu/unpublish', [SmartOrderingMenuAdminController::class, 'unpublish'])->name('menu.unpublish');
        Route::post('/menu/revisions/{revision}/schedule', [SmartOrderingMenuAdminController::class, 'schedulePublish'])->whereNumber('revision')->name('menu.schedule');

        Route::get('/sites/{site}/integrations', [RestaurantIntegrationController::class, 'index'])
            ->whereNumber('site')->name('integrations.index');
        Route::post('/sites/{site}/integrations', [RestaurantIntegrationController::class, 'install'])
            ->whereNumber('site')->name('integrations.install');
        Route::patch('/sites/{site}/integrations/{integration}/status', [RestaurantIntegrationController::class, 'status'])
            ->whereNumber('site')->whereUuid('integration')->name('integrations.status');
        Route::post('/sites/{site}/integrations/api-clients', [RestaurantIntegrationController::class, 'apiClient'])
            ->whereNumber('site')->name('integrations.api-clients.store');
        Route::patch('/sites/{site}/integrations/api-clients/{client}/revoke', [RestaurantIntegrationController::class, 'revokeApiClient'])
            ->whereNumber('site')->whereUuid('client')->name('integrations.api-clients.revoke');
        Route::post('/sites/{site}/integrations/webhooks', [RestaurantIntegrationController::class, 'webhook'])
            ->whereNumber('site')->name('integrations.webhooks.store');
        Route::patch('/sites/{site}/integrations/webhooks/{webhook}/status', [RestaurantIntegrationController::class, 'webhookStatus'])
            ->whereNumber('site')->whereUuid('webhook')->name('integrations.webhooks.status');
        Route::post('/sites/{site}/integrations/events/{event}/retry', [RestaurantIntegrationController::class, 'retryEvent'])
            ->whereNumber('site')->whereNumber('event')->name('integrations.events.retry');
        Route::post('/sites/{site}/integrations/deliveries/{delivery}/replay', [RestaurantIntegrationController::class, 'replayDelivery'])
            ->whereNumber('site')->whereNumber('delivery')->name('integrations.deliveries.replay');
        Route::get('/sites/{site}/integrations/export', [RestaurantIntegrationController::class, 'export'])
            ->whereNumber('site')->name('integrations.export');

        Route::get('/supply', [SmartOrderingSupplyController::class, 'entry'])->name('supply.entry');
        Route::get('/sites/{site}/supply', [SmartOrderingSupplyController::class, 'index'])
            ->whereNumber('site')->name('supply.index');
        Route::post('/sites/{site}/supply/mappings', [SmartOrderingSupplyController::class, 'storeMapping'])
            ->whereNumber('site')->name('supply.mappings.store');
        Route::post('/sites/{site}/supply/mappings/{mapping}/deactivate', [SmartOrderingSupplyController::class, 'deactivateMapping'])
            ->whereNumber('site')->whereUuid('mapping')->name('supply.mappings.deactivate');
        Route::put('/sites/{site}/supply/stock', [SmartOrderingSupplyController::class, 'stock'])
            ->whereNumber('site')->name('supply.stock');
        Route::post('/sites/{site}/supply/waste', [SmartOrderingSupplyController::class, 'waste'])
            ->whereNumber('site')->name('supply.waste');
        Route::post('/sites/{site}/supply/draft', [SmartOrderingSupplyController::class, 'draft'])
            ->whereNumber('site')->name('supply.draft');
        Route::post('/sites/{site}/supply/recommendations/{product}/dismiss', [SmartOrderingSupplyController::class, 'dismiss'])
            ->whereNumber('site')->whereNumber('product')->name('supply.dismiss');

        Route::get('/sites/{site}/growth', [RestaurantGrowthController::class, 'index'])
            ->whereNumber('site')->name('growth.index');
        Route::post('/sites/{site}/growth/gift-cards', [RestaurantGrowthController::class, 'issueGiftCard'])
            ->whereNumber('site')->name('growth.gift-cards.issue');
        Route::post('/sites/{site}/growth/gift-cards/redeem', [RestaurantGrowthController::class, 'redeemGiftCard'])
            ->whereNumber('site')->name('growth.gift-cards.redeem');
        Route::patch('/sites/{site}/growth/gift-cards/{giftCard}/status', [RestaurantGrowthController::class, 'giftCardStatus'])
            ->whereNumber('site')->whereUuid('giftCard')->name('growth.gift-cards.status');
        Route::post('/sites/{site}/growth/journeys', [RestaurantGrowthController::class, 'journey'])
            ->whereNumber('site')->name('growth.journeys.store');
        Route::post('/sites/{site}/growth/policies', [RestaurantGrowthController::class, 'policy'])
            ->whereNumber('site')->name('growth.policies.store');
        Route::post('/sites/{site}/growth/exports', [RestaurantGrowthController::class, 'export'])
            ->whereNumber('site')->name('growth.exports.store');
        Route::get('/sites/{site}/growth/accounting.csv', [RestaurantGrowthController::class, 'accountingExport'])
            ->whereNumber('site')->name('growth.accounting.download');
        Route::get('/sites/{site}/growth/exports/{export}', [RestaurantGrowthController::class, 'download'])
            ->whereNumber('site')->whereUuid('export')->name('growth.exports.download');

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
