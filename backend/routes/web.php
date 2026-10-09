<?php

use App\Http\Controllers\Admin\AdministrationHubController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\Admin\AdminShellController;
use App\Http\Controllers\Admin\AppPreviewConfigurationController;
use App\Http\Controllers\Admin\AppPreviewController;
use App\Http\Controllers\Admin\AppPreviewInvalidationController;
use App\Http\Controllers\Admin\AppVersionController;
use App\Http\Controllers\Admin\AssistantController;
use App\Http\Controllers\Admin\AssistantSettingsController;
use App\Http\Controllers\Admin\B2bWorkspaceController;
use App\Http\Controllers\Admin\B2cWorkspaceController;
use App\Http\Controllers\Admin\BusinessManagementController;
use App\Http\Controllers\Admin\CatalogManagementController;
use App\Http\Controllers\Admin\CommercialDashboardController;
use App\Http\Controllers\Admin\Customer360Controller;
use App\Http\Controllers\Admin\DriverLiveTrackingDashboardController;
use App\Http\Controllers\Admin\FieldOperationsController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\LiveAdController;
use App\Http\Controllers\Admin\LookupManagementController;
use App\Http\Controllers\Admin\MobileAppDownloadController;
use App\Http\Controllers\Admin\MobileSettingsController;
use App\Http\Controllers\Admin\OrderOperationsController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RetailStoreProvisioningController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SmsSettingsController;
use App\Http\Controllers\Admin\StorefrontDraftEditorController;
use App\Http\Controllers\Admin\StoreSubmissionController;
use App\Http\Controllers\Admin\SystemInspectorController;
use App\Http\Controllers\Admin\SystemLookupController;
use App\Http\Controllers\Admin\SystemUpdateController;
use App\Http\Controllers\Admin\TranslationController;
use App\Http\Controllers\Admin\VanFinanceSupportController;
use App\Http\Controllers\Api\V1\DriverLiveTrackingController;
use App\Http\Controllers\Installer\InstallerController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', fn () => redirect()->route('admin.b2c.login'));

Route::get('/downloads/apps/{app}/latest.apk', [MobileAppDownloadController::class, 'latest'])
    ->whereIn('app', ['customer', 'driver', 'van'])
    ->name('public.mobile-apps.latest');
Route::get('/downloads/apps/{app}/{version}.apk', [MobileAppDownloadController::class, 'versioned'])
    ->whereIn('app', ['customer', 'driver', 'van'])
    ->where('version', '\\d+\\.\\d+\\.\\d+')
    ->name('public.mobile-apps.versioned');

Route::view('/privacy', 'public.legal', [
    'title' => 'FOODEX Privacy Policy',
    'content' => '<div class="card"><p>FOODEX processes account, order, delivery, device and support data only as needed to operate the service, secure accounts, fulfill transactions and meet legal or accounting obligations.</p><p>Store submission declarations must match the actual production build and configured integrations. Contact Support for privacy questions or deletion status.</p></div>',
])->name('public.privacy');
Route::view('/terms', 'public.legal', [
    'title' => 'FOODEX Terms of Service',
    'content' => '<div class="card"><p>Use of FOODEX is subject to the commercial, payment, delivery and account rules presented in the service. Operational Driver/Van accounts may be managed by the associated organization and are not treated as ordinary consumer accounts.</p></div>',
])->name('public.terms');
Route::view('/support', 'public.legal', [
    'title' => 'FOODEX Support',
    'content' => '<div class="card"><p>For account, order, delivery, privacy or store-review support, use the support contact configured for the production FOODEX release. This page is intentionally public so external store reviewers can reach the support surface.</p></div>',
])->name('public.support');
Route::view('/account-deletion', 'public.legal', [
    'title' => 'FOODEX Account Deletion',
    'content' => '<div class="card"><p>Customer accounts can request deletion from the authenticated app. Identity verification is required. Active orders or outstanding financial obligations may delay anonymization. Required order, invoice, payment, tax and audit records are retained where legally or operationally required.</p><p>Driver/Van operational accounts follow managed deactivation and retention rules and are not automatically destroyed as consumer accounts.</p></div>',
])->name('public.account-deletion');

Route::withoutMiddleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
])->group(function (): void {
    Route::get('/install', [InstallerController::class, 'show'])->name('install.index');
    Route::post('/install/step/{step}', [InstallerController::class, 'process'])
        ->whereNumber('step')
        ->name('install.step');
});

Route::prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/csrf-token', [AdminLoginController::class, 'csrfToken'])
            ->middleware('throttle:60,1')
            ->name('csrf-token');
        Route::get('/b2b/login', [AdminLoginController::class, 'show'])
            ->defaults('channel', 'b2b')
            ->name('b2b.login');
        Route::post('/b2b/login', [AdminLoginController::class, 'store'])
            ->defaults('channel', 'b2b')
            ->middleware('throttle:login')
            ->name('b2b.login.store');
        Route::get('/b2c/login', [AdminLoginController::class, 'show'])
            ->defaults('channel', 'b2c')
            ->name('b2c.login');
        Route::post('/b2c/login', [AdminLoginController::class, 'store'])
            ->defaults('channel', 'b2c')
            ->middleware('throttle:login')
            ->name('b2c.login.store');
        Route::post('/logout', [AdminLoginController::class, 'destroy'])
            ->middleware('auth')
            ->name('logout');
    });

Route::prefix('admin')
    ->name('admin.')
    ->middleware('management.dashboard')
    ->group(function (): void {
        Route::get('/', [AdminShellController::class, 'index'])->name('index');
        Route::get('/administration', AdministrationHubController::class)->name('administration.index');
        Route::get('/van-finance-support', VanFinanceSupportController::class)->name('van-finance-support.index');
        Route::prefix('assistant')
            ->name('assistant.')
            ->middleware('throttle:assistant')
            ->group(function (): void {
                Route::get('/bootstrap', [AssistantController::class, 'bootstrap'])->name('bootstrap');
                Route::get('/conversations', [AssistantController::class, 'index'])->name('conversations.index');
                Route::post('/conversations', [AssistantController::class, 'storeConversation'])->name('conversations.store');
                Route::get('/conversations/{conversationId}/messages', [AssistantController::class, 'messages'])->whereUuid('conversationId')->name('conversations.messages.index');
                Route::post('/conversations/{conversationId}/messages', [AssistantController::class, 'storeMessage'])->whereUuid('conversationId')->name('conversations.messages.store');
                Route::post('/conversations/{conversationId}/clear', [AssistantController::class, 'clear'])->whereUuid('conversationId')->name('conversations.clear');
                Route::delete('/conversations/{conversationId}', [AssistantController::class, 'destroy'])->whereUuid('conversationId')->name('conversations.destroy');
            });
        Route::get('/profile', [AdminProfileController::class, 'index'])->name('profile.index');
        Route::get('/app-preview', [AppPreviewController::class, 'index'])->name('app-preview.index');
        Route::get('/app-preview/targets', [AppPreviewController::class, 'targets'])->name('app-preview.targets');
        Route::get('/app-preview/storefront-configuration', AppPreviewConfigurationController::class)->name('app-preview.storefront-configuration');
        Route::get('/app-preview/events', AppPreviewInvalidationController::class)->name('app-preview.events');
        Route::post('/app-preview/sessions', [AppPreviewController::class, 'storeSession'])->name('app-preview.sessions.store');
        Route::delete('/app-preview/sessions/{sessionId}', [AppPreviewController::class, 'destroySession'])
            ->whereUuid('sessionId')
            ->name('app-preview.sessions.destroy');
        Route::prefix('field-operations')->name('field-operations.')->group(function (): void {
            Route::get('/', [FieldOperationsController::class, 'overview'])->name('overview');
            Route::get('/fleet-map', [FieldOperationsController::class, 'fleet'])->name('fleet');
            Route::get('/fleet-map/feed', [FieldOperationsController::class, 'fleetFeed'])->name('fleet.feed');
            Route::get('/vans', [FieldOperationsController::class, 'vans'])->name('vans');
            Route::get('/vans/{van}', [FieldOperationsController::class, 'showVan'])->whereNumber('van')->name('vans.show');
            Route::post('/vans', [FieldOperationsController::class, 'storeVan'])->name('vans.store');
            Route::post('/vans/{van}/suspend', [FieldOperationsController::class, 'suspendVan'])->whereNumber('van')->name('vans.suspend');
            Route::get('/assignments', [FieldOperationsController::class, 'assignments'])->name('assignments');
            Route::post('/vans/{van}/assignments', [FieldOperationsController::class, 'storeAssignment'])->whereNumber('van')->name('assignments.store');
            Route::get('/customers', [FieldOperationsController::class, 'customers'])->name('customers');
            Route::get('/visits', [FieldOperationsController::class, 'visits'])->name('visits');
            Route::post('/visits', [FieldOperationsController::class, 'storeVisit'])->name('visits.store');
            Route::post('/visits/{visit}/transition', [FieldOperationsController::class, 'transitionVisit'])->whereNumber('visit')->name('visits.transition');
            Route::get('/territories', [FieldOperationsController::class, 'territories'])->name('territories');
            Route::post('/geography', [FieldOperationsController::class, 'storeGeography'])->name('geography.store');
            Route::post('/territories', [FieldOperationsController::class, 'storeTerritory'])->name('territories.store');
            Route::post('/territories/{territory}/geometry', [FieldOperationsController::class, 'storeGeometry'])->whereNumber('territory')->name('territories.geometry.store');
            Route::get('/address-quality', [FieldOperationsController::class, 'addressQuality'])->name('address-quality');
            Route::post('/address-quality/{review}/{action}', [FieldOperationsController::class, 'addressAction'])
                ->whereNumber('review')->whereIn('action', ['confirm', 'reject', 'reopen'])->name('address-quality.action');
            Route::get('/routing-policies', [FieldOperationsController::class, 'routingPolicies'])->name('routing');
            Route::post('/routing-policies', [FieldOperationsController::class, 'storeRoutingPolicy'])->name('routing.store');
            Route::post('/routing-policies/{routingPolicy}/{action}', [FieldOperationsController::class, 'routingAction'])
                ->whereNumber('routingPolicy')->whereIn('action', ['publish', 'simulate', 'rollback'])->name('routing.action');
            Route::get('/finance', [FieldOperationsController::class, 'finance'])->name('finance');
            Route::post('/finance/remittances/{remittance}/{action}', [FieldOperationsController::class, 'reviewRemittance'])
                ->whereNumber('remittance')->whereIn('action', ['approve', 'reject', 'reconcile'])->name('finance.remittances.review');
        });
        Route::get('/driver-live-tracking', [DriverLiveTrackingDashboardController::class, 'index'])->name('driver-live-tracking.index');
        Route::get('/driver-live-tracking/feed', [DriverLiveTrackingController::class, 'feed'])->name('driver-live-tracking.feed');
        Route::get('/driver-live-tracking/assignments/{assignment}/evidence', [DriverLiveTrackingDashboardController::class, 'evidence'])
            ->whereNumber('assignment')
            ->name('driver-live-tracking.evidence');
        Route::get('/driver-live-tracking/assignments/{assignment}/proofs/{proof}', [DriverLiveTrackingDashboardController::class, 'proof'])
            ->whereNumber('assignment')
            ->whereNumber('proof')
            ->name('driver-live-tracking.proofs.show');
        Route::get('/customer-360', [Customer360Controller::class, 'index'])->name('customer-360.index');
        Route::get('/customer-360/{platformCustomer}', [Customer360Controller::class, 'show'])->whereNumber('platformCustomer')->name('customer-360.show');
        Route::post('/customer-360/{platformCustomer}/finance-entries', [Customer360Controller::class, 'storeFinanceEntry'])->whereNumber('platformCustomer')->name('customer-360.finance-entries.store');
        Route::post('/customer-360/{platformCustomer}/invoices/{invoice}/settle', [Customer360Controller::class, 'settleInvoice'])->whereNumber('platformCustomer')->whereNumber('invoice')->name('customer-360.invoices.settle');
        Route::post('/customer-360/{platformCustomer}/finance-entries/{ledgerEntry}/reverse', [Customer360Controller::class, 'reverseFinanceEntry'])->whereNumber('platformCustomer')->whereNumber('ledgerEntry')->name('customer-360.finance-entries.reverse');
        Route::get('/customer-360/{platformCustomer}/statement/export', [Customer360Controller::class, 'statementExport'])->whereNumber('platformCustomer')->name('customer-360.statement.export');
        Route::patch('/customer-360/{platformCustomer}/credit-limit', [Customer360Controller::class, 'updateCreditLimit'])->whereNumber('platformCustomer')->name('customer-360.credit-limit.update');
        Route::get('/customer-360/{invalidCustomerReference}', [Customer360Controller::class, 'invalidReference'])
            ->where('invalidCustomerReference', '[^0-9]+')
            ->name('customer-360.invalid-reference');
        Route::post('/customer-360/{platformCustomer}/addresses', [Customer360Controller::class, 'storeAddress'])->whereNumber('platformCustomer')->name('customer-360.addresses.store');
        Route::patch('/customer-360/{platformCustomer}/addresses/{address}', [Customer360Controller::class, 'updateAddress'])->whereNumber('platformCustomer')->whereNumber('address')->name('customer-360.addresses.update');
        Route::post('/customer-360/{platformCustomer}/addresses/{address}/default', [Customer360Controller::class, 'setDefaultAddress'])->whereNumber('platformCustomer')->whereNumber('address')->name('customer-360.addresses.default');
        Route::delete('/customer-360/{platformCustomer}/addresses/{address}', [Customer360Controller::class, 'destroyAddress'])->whereNumber('platformCustomer')->whereNumber('address')->name('customer-360.addresses.destroy');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
        Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download'])->whereNumber('invoice')->name('invoices.download');
        Route::post('/invoices/{invoice}/settle', [InvoiceController::class, 'settle'])->whereNumber('invoice')->name('invoices.settle');
        Route::post('/invoices/{invoice}/void-reissue', [InvoiceController::class, 'reissue'])->whereNumber('invoice')->name('invoices.reissue');
        Route::patch('/profile/password', [AdminProfileController::class, 'updatePassword'])->name('profile.password');
        Route::patch('/profile/locale', [AdminProfileController::class, 'updateLocale'])->name('profile.locale');
        Route::get('/b2b/dashboard', [B2bWorkspaceController::class, 'show'])->defaults('module', 'dashboard')->name('b2b.dashboard');
        Route::post('/b2b/orders/quote', [B2bWorkspaceController::class, 'quoteOrder'])->name('b2b.orders.quote');
        Route::post('/b2b/orders', [B2bWorkspaceController::class, 'storeOrder'])->name('b2b.orders.store');
        Route::patch('/b2b/orders/{order}', [B2bWorkspaceController::class, 'updateOrder'])->whereNumber('order')->name('b2b.orders.update');
        Route::post('/b2b/orders/{order}/status', [B2bWorkspaceController::class, 'transitionOrder'])->whereNumber('order')->name('b2b.orders.status');
        Route::post('/b2b/drivers/assign', [B2bWorkspaceController::class, 'assignDriver'])->name('b2b.drivers.assign');
        Route::patch('/b2b/orders/{order}/driver', [B2bWorkspaceController::class, 'reassignDriver'])->whereNumber('order')->name('b2b.orders.driver.reassign');
        Route::delete('/b2b/orders/{order}/driver', [B2bWorkspaceController::class, 'unassignDriver'])->whereNumber('order')->name('b2b.orders.driver.unassign');
        Route::post('/b2b/pricing', [B2bWorkspaceController::class, 'savePriceRule'])->name('b2b.pricing.save');
        Route::post('/b2b/clients', [B2bWorkspaceController::class, 'storeClient'])->name('b2b.clients.store');
        Route::patch('/b2b/clients/{account}/status', [B2bWorkspaceController::class, 'updateClientStatus'])->name('b2b.clients.status');
        Route::post('/b2b/categories', [B2bWorkspaceController::class, 'storeCategory'])->name('b2b.categories.store');
        Route::post('/b2b/products', [B2bWorkspaceController::class, 'storeProduct'])->name('b2b.products.store');
        Route::patch('/b2b/products/{product}', [B2bWorkspaceController::class, 'updateProduct'])->name('b2b.products.update');
        Route::post('/b2b/warehouses', [B2bWorkspaceController::class, 'storeWarehouse'])->name('b2b.warehouses.store');
        Route::post('/b2b/inventory', [B2bWorkspaceController::class, 'ensureInventory'])->name('b2b.inventory.ensure');
        Route::patch('/b2b/inventory/{inventory}/adjust', [B2bWorkspaceController::class, 'adjustInventory'])->whereNumber('inventory')->name('b2b.inventory.adjust');
        Route::post('/b2b/drivers', [B2bWorkspaceController::class, 'storeDriver'])->name('b2b.drivers.store');
        Route::patch('/b2b/drivers/{driver}/password', [B2bWorkspaceController::class, 'resetDriverPassword'])->whereNumber('driver')->name('b2b.drivers.password');
        Route::put('/b2b/settings', [B2bWorkspaceController::class, 'saveSetting'])->name('b2b.settings.save');
        Route::put('/b2b/storefront/settings', [StorefrontDraftEditorController::class, 'wholesaleSettings'])->name('b2b.storefront.settings');
        Route::post('/b2b/storefront/sections', [StorefrontDraftEditorController::class, 'wholesaleSectionStore'])->name('b2b.storefront.sections.store');
        Route::patch('/b2b/storefront/sections/{section}', [StorefrontDraftEditorController::class, 'wholesaleSectionUpdate'])->name('b2b.storefront.sections.update');
        Route::delete('/b2b/storefront/sections/{section}', [StorefrontDraftEditorController::class, 'wholesaleSectionDestroy'])->name('b2b.storefront.sections.destroy');
        Route::post('/b2b/storefront/banners', [StorefrontDraftEditorController::class, 'wholesaleBannerStore'])->name('b2b.storefront.banners.store');
        Route::patch('/b2b/storefront/banners/{banner}', [StorefrontDraftEditorController::class, 'wholesaleBannerUpdate'])->name('b2b.storefront.banners.update');
        Route::delete('/b2b/storefront/banners/{banner}', [StorefrontDraftEditorController::class, 'wholesaleBannerDestroy'])->name('b2b.storefront.banners.destroy');
        Route::post('/b2b/storefront/publish', [StorefrontDraftEditorController::class, 'wholesalePublish'])->name('b2b.storefront.publish');
        Route::post('/b2b/storefront/discard', [StorefrontDraftEditorController::class, 'wholesaleDiscard'])->name('b2b.storefront.discard');
        Route::post('/b2b/finance/remittances/{remittance}/{action}', [B2bWorkspaceController::class, 'reviewRemittance'])
            ->whereNumber('remittance')
            ->whereIn('action', ['approve', 'reject', 'reconcile'])
            ->name('b2b.finance.remittances.review');
        Route::get('/b2b/pricing-approvals', [B2bWorkspaceController::class, 'show'])->defaults('module', 'pricing')->name('b2b.pricing-approvals');
        Route::get('/b2b/settings-permissions', [B2bWorkspaceController::class, 'show'])->defaults('module', 'settings')->name('b2b.settings-permissions');
        Route::get('/b2b/{module}', [B2bWorkspaceController::class, 'show'])->name('b2b.module');
        Route::get('/b2c/dashboard', [B2cWorkspaceController::class, 'show'])->defaults('module', 'dashboard')->name('b2c.dashboard');
        Route::post('/b2c/merchant-intelligence/suggested-cart', [B2cWorkspaceController::class, 'applySuggestedWholesaleCart'])->name('b2c.merchant-intelligence.cart');
        Route::get('/b2c/commercial/sales-control', [CommercialDashboardController::class, 'salesControl'])->name('commercial.sales-control');
        Route::put('/b2c/commercial/sales-control/{product}', [CommercialDashboardController::class, 'saveSalesControl'])->whereNumber('product')->name('commercial.sales-control.save');
        Route::put('/b2c/commercial/feature-flags', [CommercialDashboardController::class, 'saveFeatureFlags'])->name('commercial.feature-flags.save');
        Route::get('/b2c/commercial/flash-offers', [CommercialDashboardController::class, 'flashOffers'])->name('commercial.flash-offers');
        Route::get('/b2c/commercial/flash-offers/{offer}/preview', [CommercialDashboardController::class, 'flashPreview'])->whereNumber('offer')->name('commercial.flash-offers.preview');
        Route::get('/b2c/commercial/flash-offers/{offer}/analytics', [CommercialDashboardController::class, 'flashAnalytics'])->whereNumber('offer')->name('commercial.flash-offers.analytics');
        Route::post('/b2c/commercial/flash-offers', [CommercialDashboardController::class, 'saveFlashOffer'])->name('commercial.flash-offers.save');
        Route::post('/b2c/commercial/flash-offers/{offer}/action', [CommercialDashboardController::class, 'flashAction'])->whereNumber('offer')->name('commercial.flash-offers.action');
        Route::post('/b2c/orders/quote', [B2cWorkspaceController::class, 'quoteOrder'])->name('b2c.orders.quote');
        Route::post('/b2c/orders', [B2cWorkspaceController::class, 'storeOrder'])->name('b2c.orders.store');
        Route::patch('/b2c/orders/{order}', [B2cWorkspaceController::class, 'updateOrder'])->whereNumber('order')->name('b2c.orders.update');
        Route::post('/b2c/orders/{order}/status', [B2cWorkspaceController::class, 'transitionOrder'])->whereNumber('order')->name('b2c.orders.status');
        Route::post('/b2c/wholesale-purchases/{order}/available-for-sale', [B2cWorkspaceController::class, 'makeWholesalePurchaseAvailable'])->whereNumber('order')->name('b2c.wholesale-purchases.available-for-sale');
        Route::post('/b2c/drivers/assign', [B2cWorkspaceController::class, 'assignDriver'])->name('b2c.drivers.assign');
        Route::patch('/b2c/orders/{order}/driver', [B2cWorkspaceController::class, 'reassignDriver'])->whereNumber('order')->name('b2c.orders.driver.reassign');
        Route::delete('/b2c/orders/{order}/driver', [B2cWorkspaceController::class, 'unassignDriver'])->whereNumber('order')->name('b2c.orders.driver.unassign');
        Route::patch('/b2c/drivers/{driver}/password', [B2cWorkspaceController::class, 'resetDriverPassword'])->whereNumber('driver')->name('b2c.drivers.password');
        Route::post('/b2c/warehouses', [B2cWorkspaceController::class, 'storeWarehouse'])->name('b2c.warehouses.store');
        Route::post('/b2c/inventory', [B2cWorkspaceController::class, 'ensureInventory'])->name('b2c.inventory.ensure');
        Route::post('/b2c/inventory/{inventory}/adjust', [B2cWorkspaceController::class, 'adjustInventory'])->whereNumber('inventory')->name('b2c.inventory.adjust');
        Route::post('/b2c/inventory/transfer', [B2cWorkspaceController::class, 'transferInventory'])->name('b2c.inventory.transfer');
        Route::put('/b2c/settings', [B2cWorkspaceController::class, 'saveSetting'])->name('b2c.settings.save');
        Route::get('/b2c/storefront-preview', [B2cWorkspaceController::class, 'show'])->defaults('module', 'storefront')->name('b2c.storefront-preview');
        Route::put('/b2c/storefront/settings', [StorefrontDraftEditorController::class, 'retailSettings'])->name('b2c.storefront.settings');
        Route::post('/b2c/storefront/sections', [StorefrontDraftEditorController::class, 'retailSectionStore'])->name('b2c.storefront.sections.store');
        Route::patch('/b2c/storefront/sections/{section}', [StorefrontDraftEditorController::class, 'retailSectionUpdate'])->name('b2c.storefront.sections.update');
        Route::delete('/b2c/storefront/sections/{section}', [StorefrontDraftEditorController::class, 'retailSectionDestroy'])->name('b2c.storefront.sections.destroy');
        Route::post('/b2c/storefront/service-zones', [StorefrontDraftEditorController::class, 'retailZoneStore'])->name('b2c.storefront.zones.store');
        Route::delete('/b2c/storefront/service-zones/{zone}', [StorefrontDraftEditorController::class, 'retailZoneDestroy'])->name('b2c.storefront.zones.destroy');
        Route::post('/b2c/storefront/publish', [StorefrontDraftEditorController::class, 'retailPublish'])->name('b2c.storefront.publish');
        Route::post('/b2c/storefront/discard', [StorefrontDraftEditorController::class, 'retailDiscard'])->name('b2c.storefront.discard');
        Route::get('/b2c/{module}', [B2cWorkspaceController::class, 'show'])->name('b2c.module');
        Route::get('/catalog', [CatalogManagementController::class, 'index'])->name('catalog.index');
        Route::get('/catalog/categories', [CatalogManagementController::class, 'categories'])->name('catalog.categories.index');
        Route::get('/catalog/import/sample', [CatalogManagementController::class, 'downloadImportSample'])->name('catalog.import.sample');
        Route::post('/catalog/import/preview', [CatalogManagementController::class, 'previewImport'])->name('catalog.import.preview');
        Route::post('/catalog/import/commit', [CatalogManagementController::class, 'commitImport'])->name('catalog.import.commit');
        Route::get('/lookups', [LookupManagementController::class, 'index'])->name('lookups.index');
        Route::post('/lookups/{type}', [LookupManagementController::class, 'store'])->whereIn('type', ['brands', 'units'])->name('lookups.store');
        Route::patch('/lookups/{type}/{lookup}', [LookupManagementController::class, 'update'])->whereIn('type', ['brands', 'units'])->name('lookups.update');
        Route::patch('/lookups/{type}/{lookup}/status', [LookupManagementController::class, 'toggle'])->whereIn('type', ['brands', 'units'])->name('lookups.toggle');
        Route::delete('/lookups/{type}/{lookup}', [LookupManagementController::class, 'destroy'])->whereIn('type', ['brands', 'units'])->name('lookups.destroy');
        Route::get('/business', [BusinessManagementController::class, 'index'])->name('business.index');
        Route::post('/business/warehouses', [BusinessManagementController::class, 'storeWarehouse'])->name('business.warehouses.store');
        Route::patch('/business/warehouses/{warehouse}', [BusinessManagementController::class, 'updateWarehouse'])->name('business.warehouses.update');
        Route::post('/business/inventory', [BusinessManagementController::class, 'ensureInventory'])->name('business.inventory.ensure');
        Route::patch('/business/inventory/{inventory}', [BusinessManagementController::class, 'adjustInventory'])->name('business.inventory.adjust');
        Route::post('/business/customers', [BusinessManagementController::class, 'storeCustomer'])->name('business.customers.store');
        Route::patch('/business/customers/{customer}', [BusinessManagementController::class, 'updateCustomer'])->name('business.customers.update');
        Route::delete('/business/customers/{customer}', [BusinessManagementController::class, 'destroyCustomer'])->name('business.customers.destroy');
        Route::post('/business/promotions', [BusinessManagementController::class, 'storePromotion'])->name('business.promotions.store');
        Route::patch('/business/promotions/{promotion}', [BusinessManagementController::class, 'updatePromotion'])->name('business.promotions.update');
        Route::delete('/business/promotions/{promotion}', [BusinessManagementController::class, 'destroyPromotion'])->name('business.promotions.destroy');
        Route::post('/business/banners', [StorefrontDraftEditorController::class, 'retailBannerStore'])->name('business.banners.store');
        Route::patch('/business/banners/{banner}', [StorefrontDraftEditorController::class, 'retailBannerUpdate'])->name('business.banners.update');
        Route::delete('/business/banners/{banner}', [StorefrontDraftEditorController::class, 'retailBannerDestroy'])->name('business.banners.destroy');
        Route::post('/business/drivers', [BusinessManagementController::class, 'storeDriver'])->name('business.drivers.store');
        Route::patch('/business/drivers/{driver}', [BusinessManagementController::class, 'updateDriver'])->name('business.drivers.update');
        Route::delete('/business/drivers/{driver}', [BusinessManagementController::class, 'destroyDriver'])->name('business.drivers.destroy');
        Route::post('/catalog/products', [CatalogManagementController::class, 'storeProduct'])->name('catalog.products.store');
        Route::patch('/catalog/products/{product}', [CatalogManagementController::class, 'updateProduct'])->name('catalog.products.update');
        Route::patch('/catalog/products/{product}/images/{image}', [CatalogManagementController::class, 'updateProductImage'])->name('catalog.products.images.update');
        Route::delete('/catalog/products/{product}/images/{image}', [CatalogManagementController::class, 'destroyProductImage'])->name('catalog.products.images.destroy');
        Route::delete('/catalog/products/{product}', [CatalogManagementController::class, 'destroyProduct'])->name('catalog.products.destroy');
        Route::post('/catalog/products/{product}/store', [CatalogManagementController::class, 'assignProduct'])->name('catalog.products.assign');
        Route::post('/catalog/categories', [CatalogManagementController::class, 'storeCategory'])->name('catalog.categories.store');
        Route::patch('/catalog/categories/{category}', [CatalogManagementController::class, 'updateCategory'])->name('catalog.categories.update');
        Route::delete('/catalog/categories/{category}', [CatalogManagementController::class, 'destroyCategory'])->name('catalog.categories.destroy');
        Route::post('/catalog/brands', [CatalogManagementController::class, 'storeBrand'])->name('catalog.brands.store');
        Route::delete('/catalog/brands/{brand}', [CatalogManagementController::class, 'destroyBrand'])->name('catalog.brands.destroy');
        Route::post('/catalog/units', [CatalogManagementController::class, 'storeUnit'])->name('catalog.units.store');
        Route::get('/retail-stores', [RetailStoreProvisioningController::class, 'index'])->name('retail-stores.index');
        Route::post('/retail-stores', [RetailStoreProvisioningController::class, 'store'])->name('retail-stores.store');
        Route::patch('/retail-stores/{store}', [RetailStoreProvisioningController::class, 'update'])->name('retail-stores.update');
        Route::post('/retail-stores/{store}/roles', [RetailStoreProvisioningController::class, 'assignRole'])->name('retail-stores.roles.assign');
        Route::delete('/retail-stores/{store}/roles/{assignment}', [RetailStoreProvisioningController::class, 'removeRole'])->name('retail-stores.roles.remove');
        Route::post('/retail-stores/{store}/inspect', [RetailStoreProvisioningController::class, 'inspect'])->name('retail-stores.inspect');
        Route::get('/live-ads', [LiveAdController::class, 'index'])->name('live-ads.index');
        Route::post('/live-ads', [LiveAdController::class, 'store'])->name('live-ads.store');
        Route::patch('/live-ads/{liveAd}', [LiveAdController::class, 'update'])->name('live-ads.update');
        Route::patch('/live-ads/{liveAd}/toggle', [LiveAdController::class, 'toggle'])->name('live-ads.toggle');
        Route::delete('/live-ads/{liveAd}', [LiveAdController::class, 'destroy'])->name('live-ads.destroy');
        Route::get('/operations/lookups', [SystemLookupController::class, 'index'])->name('operations.lookups.index');
        Route::post('/operations/lookups/{type}', [SystemLookupController::class, 'store'])->name('operations.lookups.store');
        Route::patch('/operations/lookups/{type}/{lookup}', [SystemLookupController::class, 'update'])->whereNumber('lookup')->name('operations.lookups.update');
        Route::get('/operations/orders', [OrderOperationsController::class, 'index'])->name('operations.orders.index');
        Route::post('/operations/orders/quote', [OrderOperationsController::class, 'quoteNewOrder'])->name('operations.orders.quote');
        Route::post('/operations/orders', [OrderOperationsController::class, 'storeNewOrder'])->name('operations.orders.store');
        Route::post('/operations/orders/{order}/remind-driver', [OrderOperationsController::class, 'remindDriver'])->whereNumber('order')->name('operations.orders.remind');
        Route::post('/operations/orders/{order}/status', [OrderOperationsController::class, 'transition'])->whereNumber('order')->name('operations.orders.transition');
        Route::patch('/operations/orders/{order}/dispatch', [OrderOperationsController::class, 'dispatch'])->whereNumber('order')->name('operations.orders.dispatch');
        Route::delete('/operations/orders/{order}/dispatch', [OrderOperationsController::class, 'clearDispatch'])->whereNumber('order')->name('operations.orders.dispatch.clear');
        Route::patch('/operations/orders/{order}/driver', [OrderOperationsController::class, 'reassign'])->whereNumber('order')->name('operations.orders.reassign');
        Route::delete('/operations/orders/{order}/driver', [OrderOperationsController::class, 'unassign'])->whereNumber('order')->name('operations.orders.unassign');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/inspector', [SystemInspectorController::class, 'index'])->name('inspector.index');
        Route::post('/inspector/client-events', [SystemInspectorController::class, 'clientEvent'])->name('inspector.client-events');
        Route::get('/inspector/export', [SystemInspectorController::class, 'export'])->name('inspector.export');
        Route::post('/inspector/storage-link', [SystemInspectorController::class, 'repairStorageLink'])->name('inspector.storage-link');
        Route::get('/security', [SecurityController::class, 'index'])->name('security.index');
        Route::get('/security/demo-data', [SecurityController::class, 'demoData'])->name('security.demo-data.index');
        Route::post('/security/demo-data', [SecurityController::class, 'seedDemoData'])->name('security.demo-data.seed');
        Route::delete('/security/demo-data', [SecurityController::class, 'clearDemoData'])->name('security.demo-data.clear');
        Route::patch('/security/users/{user}/status', [SecurityController::class, 'updateUserStatus'])->name('security.users.status');
        Route::put('/security/users/{user}/roles', [SecurityController::class, 'updateUserRoles'])->name('security.users.roles');
        Route::post('/security/roles', [SecurityController::class, 'storeRole'])->name('security.roles.store');
        Route::patch('/security/roles/{role}', [SecurityController::class, 'updateRole'])->name('security.roles.update');
        Route::post('/security/roles/{role}/clone', [SecurityController::class, 'cloneRole'])->name('security.roles.clone');
        Route::delete('/security/roles/{role}', [SecurityController::class, 'destroyRole'])->name('security.roles.destroy');
        Route::get('/apps/customer/download', [MobileAppDownloadController::class, 'customer'])->name('mobile-apps.customer.download');
        Route::get('/apps/driver/download', [MobileAppDownloadController::class, 'driver'])->name('mobile-apps.driver.download');
        Route::get('/apps/van/download', [MobileAppDownloadController::class, 'van'])->name('mobile-apps.van.download');
        Route::get('/settings/assistant', [AssistantSettingsController::class, 'index'])->name('assistant-settings.index');
        Route::put('/settings/assistant', [AssistantSettingsController::class, 'update'])->name('assistant-settings.update');
        Route::get('/settings/app-versions', [AppVersionController::class, 'index'])->name('app-versions.index');
        Route::post('/settings/app-versions', [AppVersionController::class, 'store'])->name('app-versions.store');
        Route::get('/settings/sms', [SmsSettingsController::class, 'index'])->name('sms-settings.index');
        Route::put('/settings/sms', [SmsSettingsController::class, 'update'])->name('sms-settings.update');
        Route::post('/settings/sms/test', [SmsSettingsController::class, 'test'])->middleware('throttle:10,1')->name('sms-settings.test');
        Route::get('/settings/mobile', [MobileSettingsController::class, 'index'])->name('mobile-settings.index');
        Route::put('/settings/mobile/app', [MobileSettingsController::class, 'updateApp'])->name('mobile-settings.app');
        Route::put('/settings/mobile/driver-location-policy', [MobileSettingsController::class, 'updateDriverLocationPolicy'])->name('mobile-settings.driver-location-policy');
        Route::put('/settings/mobile/push', [MobileSettingsController::class, 'updateProvider'])->name('mobile-settings.push');
        Route::post('/settings/mobile/push/test-connection', [MobileSettingsController::class, 'testProvider'])->name('mobile-settings.push.test');
        Route::post('/settings/mobile/test-push', [MobileSettingsController::class, 'testPush'])->name('mobile-settings.test');
        Route::put('/settings/mobile/submission', [StoreSubmissionController::class, 'updateSubmission'])->name('mobile-settings.submission');
        Route::put('/settings/mobile/reviewer', [StoreSubmissionController::class, 'upsertReviewer'])->name('mobile-settings.reviewer');
        Route::post('/settings/mobile/reviewer/{reviewer}/rotate', [StoreSubmissionController::class, 'rotateReviewer'])->whereNumber('reviewer')->name('mobile-settings.reviewer.rotate');
        Route::post('/settings/mobile/reviewer/{reviewer}/test', [StoreSubmissionController::class, 'testReviewer'])->whereNumber('reviewer')->name('mobile-settings.reviewer.test');
        Route::get('/settings/system-update', [SystemUpdateController::class, 'index'])->name('system-update.index');
        Route::post('/settings/system-update', [SystemUpdateController::class, 'store'])->name('system-update.store');
        Route::get('/settings/translations', [TranslationController::class, 'index'])->name('translations.index');
        Route::patch('/settings/translations/{translation}', [TranslationController::class, 'update'])->name('translations.update');
        Route::post('/settings/translations/{translation}/reset', [TranslationController::class, 'reset'])->name('translations.reset');
    });
