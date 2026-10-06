<?php

use App\Http\Controllers\Api\V1\AddressQualityController;
use App\Http\Controllers\Api\V1\AdminReportController;
use App\Http\Controllers\Api\V1\AppPreviewInvalidationController;
use App\Http\Controllers\Api\V1\AppPreviewSessionController;
use App\Http\Controllers\Api\V1\AppVersionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\B2bAccountController;
use App\Http\Controllers\Api\V1\B2bFinanceController;
use App\Http\Controllers\Api\V1\B2bPricingController;
use App\Http\Controllers\Api\V1\B2bReportController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\CustomerInvoiceController;
use App\Http\Controllers\Api\V1\CustomerProfileController;
use App\Http\Controllers\Api\V1\DriverAssignmentController;
use App\Http\Controllers\Api\V1\DriverLiveTrackingController;
use App\Http\Controllers\Api\V1\DriverLocationController;
use App\Http\Controllers\Api\V1\FleetLocationController;
use App\Http\Controllers\Api\V1\GuestCartController;
use App\Http\Controllers\Api\V1\GuestCatalogController;
use App\Http\Controllers\Api\V1\GuestStoreController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\LiveAdController;
use App\Http\Controllers\Api\V1\LookupOptionsController;
use App\Http\Controllers\Api\V1\ManagementReportController;
use App\Http\Controllers\Api\V1\MobileRuntimeController;
use App\Http\Controllers\Api\V1\MobileSystemInspectorEventController;
use App\Http\Controllers\Api\V1\NotificationCampaignPopupController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PlatformMarketplaceController;
use App\Http\Controllers\Api\V1\PushDeviceController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\RetailCheckoutOptionsController;
use App\Http\Controllers\Api\V1\RetailWholesaleProductMappingController;
use App\Http\Controllers\Api\V1\RoutingPolicyController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\StorefrontController;
use App\Http\Controllers\Api\V1\StorefrontRevisionController;
use App\Http\Controllers\Api\V1\TerritoryController;
use App\Http\Controllers\Api\V1\TranslationController;
use App\Http\Controllers\Api\V1\VanCollectionController;
use App\Http\Controllers\Api\V1\VanRegistryController;
use App\Http\Controllers\Api\V1\VanVisitController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::get('/version', fn () => response()->json([
        'api' => 'v1',
        'platform_version' => trim((string) @file_get_contents(base_path('../VERSION'))),
    ]));

    Route::get('/app-version', AppVersionController::class);
    Route::post('/app-preview/resolve', [AppPreviewSessionController::class, 'resolve'])
        ->middleware('throttle:60,1');
    Route::get('/app-preview/events', AppPreviewInvalidationController::class)
        ->middleware('throttle:60,1');
    Route::get('/app-preview/storefront-configuration', [StorefrontRevisionController::class, 'resolveCurrentPreviewConfiguration'])
        ->middleware('throttle:60,1');
    Route::post('/app-preview/storefront-revisions/{revision}/resolve', [StorefrontRevisionController::class, 'resolvePreview'])
        ->whereUuid('revision')
        ->middleware('throttle:60,1');
    Route::get('/mobile/runtime', MobileRuntimeController::class);
    Route::get('/translations/{locale}', TranslationController::class)->whereIn('locale', ['ar', 'en']);
    Route::get('/lookups/{type}', LookupOptionsController::class)->whereIn('type', [
        'payment-operation-types',
        'payment-methods',
        'pricing-tiers',
        'order-statuses',
        'failed-delivery-reasons',
    ]);

    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:login');
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');
    Route::post('/auth/mobile-trial', [AuthController::class, 'mobileTrialLogin'])
        ->middleware('throttle:login');

    Route::get('/marketplace', [StorefrontController::class, 'marketplace']);
    Route::get('/platform/storefront', [PlatformMarketplaceController::class, 'home']);
    Route::get('/platform/products/{product}', [PlatformMarketplaceController::class, 'product'])->whereNumber('product');
    Route::get('/wholesale/stores/{store}/storefront', [StorefrontController::class, 'showWholesalePublic']);
    Route::get('/stores', [GuestStoreController::class, 'index']);
    Route::get('/stores/{store}/categories', [GuestCatalogController::class, 'categories']);
    Route::get('/stores/{store}/products', [GuestCatalogController::class, 'products']);
    Route::get('/stores/{store}/offers', [GuestCatalogController::class, 'offers']);
    Route::get('/stores/{store}/banners', [GuestCatalogController::class, 'banners']);
    Route::get('/stores/{store}/storefront', [StorefrontController::class, 'show']);
    Route::get('/products/{product}', [GuestCatalogController::class, 'product']);
    Route::get('/live-ads', [LiveAdController::class, 'index']);
    Route::get('/notification-campaign-popups', [NotificationCampaignPopupController::class, 'index'])
        ->middleware('throttle:60,1');
    Route::post('/notification-campaign-popups/{campaign}/events', [NotificationCampaignPopupController::class, 'event'])
        ->whereNumber('campaign')
        ->middleware('throttle:120,1');
    Route::post('/push/devices/guest', [PushDeviceController::class, 'storeGuest']);

    Route::get('/cart', [GuestCartController::class, 'show']);
    Route::post('/cart/items', [GuestCartController::class, 'addItem']);
    Route::patch('/cart/items/{item}', [GuestCartController::class, 'updateItem']);
    Route::delete('/cart/items/{item}', [GuestCartController::class, 'removeItem']);

    Route::prefix('app-preview/driver')
        ->middleware('preview.driver')
        ->group(function (): void {
            Route::get('/assignments', [DriverAssignmentController::class, 'index']);
            Route::get('/assignments/{assignment}', [DriverAssignmentController::class, 'show'])
                ->whereNumber('assignment');
            Route::get('/lookups/{type}', LookupOptionsController::class)
                ->whereIn('type', ['failed-delivery-reasons']);
        });

    Route::prefix('app-preview/customer')
        ->middleware('preview.customer:b2c')
        ->group(function (): void {
            Route::get('/cart', [GuestCartController::class, 'show']);
            Route::get('/profile', [CustomerProfileController::class, 'show']);
            Route::get('/profile/addresses', [CustomerProfileController::class, 'addresses']);
            Route::get('/profile/favorites', [CustomerProfileController::class, 'favorites']);
            Route::get('/orders', [OrderController::class, 'index']);
            Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
            Route::get('/invoices', [CustomerInvoiceController::class, 'index']);
            Route::get('/invoices/{invoice}', [CustomerInvoiceController::class, 'show'])->whereNumber('invoice');
            Route::get('/notification-campaign-popups', [NotificationCampaignPopupController::class, 'index']);
            Route::get('/store-selector', [StorefrontController::class, 'selector']);
            Route::get('/stores/{store}/storefront', [StorefrontController::class, 'show'])->whereNumber('store');
        });

    Route::prefix('b2b/app-preview/customer')
        ->middleware('preview.customer:b2b')
        ->group(function (): void {
            Route::get('/cart', [GuestCartController::class, 'show']);
            Route::get('/profile', [CustomerProfileController::class, 'show']);
            Route::get('/profile/addresses', [CustomerProfileController::class, 'addresses']);
            Route::get('/orders', [OrderController::class, 'index']);
            Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
            Route::get('/account-summary', [B2bFinanceController::class, 'summary']);
            Route::get('/invoices', [B2bFinanceController::class, 'invoices']);
            Route::get('/invoices/{invoice}', [B2bFinanceController::class, 'invoice'])->whereNumber('invoice');
            Route::get('/account-statement', [B2bFinanceController::class, 'statement']);
            Route::get('/account-statement/export', [B2bFinanceController::class, 'statementExport']);
            Route::get('/notification-campaign-popups', [NotificationCampaignPopupController::class, 'index']);
            Route::get('/store-selector', [StorefrontController::class, 'selector']);
            Route::get('/stores/{store}/storefront', [StorefrontController::class, 'showWholesale'])->whereNumber('store');
            Route::get('/checkout/options', [StorefrontController::class, 'b2bCheckoutOptions']);
            Route::get('/dashboard', [B2bReportController::class, 'dashboard']);
            Route::get('/reports/purchases', [B2bReportController::class, 'purchases']);
            Route::get('/products/top', [B2bReportController::class, 'topProducts']);
            Route::get('/products', [B2bPricingController::class, 'products']);
            Route::get('/products/{product}', [B2bPricingController::class, 'product'])->whereNumber('product');
        });

    Route::middleware(['auth:sanctum', 'active.user'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/runtime-inspector/events', MobileSystemInspectorEventController::class)
            ->middleware('throttle:60,1')
            ->name('api.runtime-inspector.events');

        Route::prefix('van')->group(function (): void {
            Route::get('/customers', [VanVisitController::class, 'customers']);
            Route::get('/customers/{type}/{customer}', [VanVisitController::class, 'customer'])
                ->whereIn('type', ['b2b', 'b2c'])
                ->whereNumber('customer');
            Route::get('/customers/{type}/{customer}/collection-context', [VanCollectionController::class, 'customerContext'])
                ->whereIn('type', ['b2b', 'b2c'])
                ->whereNumber('customer');
            Route::post('/customers/{type}/{customer}/collect', [VanCollectionController::class, 'collect'])
                ->whereIn('type', ['b2b', 'b2c'])
                ->whereNumber('customer');
            Route::get('/wallet', [VanCollectionController::class, 'wallet']);
            Route::post('/remittances', [VanCollectionController::class, 'remit']);
            Route::get('/visits', [VanVisitController::class, 'visits']);
            Route::post('/visits', [VanVisitController::class, 'store']);
            Route::post('/visits/{visit}/transition', [VanVisitController::class, 'transition'])
                ->whereNumber('visit');
            Route::get('/no-order-reasons', [VanVisitController::class, 'noOrderReasons']);
        });
        Route::prefix('/admin/field-operations')->group(function (): void {
            Route::post('/geography', [TerritoryController::class, 'storeGeography']);
            Route::post('/territories', [TerritoryController::class, 'storeTerritory']);
            Route::post('/territories/{territory}/geometry', [TerritoryController::class, 'storeGeometry'])
                ->whereNumber('territory');
            Route::post('/territory-resolution', [TerritoryController::class, 'resolve']);
        });
        Route::post('/admin/app-preview/sessions', [AppPreviewSessionController::class, 'store']);
        Route::delete('/admin/app-preview/sessions/{session}', [AppPreviewSessionController::class, 'destroy'])
            ->whereNumber('session');
        Route::get('/admin/app-preview/storefront-revisions', [StorefrontRevisionController::class, 'index']);
        Route::post('/admin/app-preview/storefront-revisions/draft', [StorefrontRevisionController::class, 'draft']);
        Route::get('/admin/app-preview/storefront-revisions/{revision}', [StorefrontRevisionController::class, 'show'])->whereUuid('revision');
        Route::patch('/admin/app-preview/storefront-revisions/{revision}', [StorefrontRevisionController::class, 'update'])->whereUuid('revision');
        Route::post('/admin/app-preview/storefront-revisions/{revision}/publish', [StorefrontRevisionController::class, 'publish'])->whereUuid('revision');
        Route::post('/admin/app-preview/storefront-revisions/{revision}/rollback', [StorefrontRevisionController::class, 'rollback'])->whereUuid('revision');
        Route::get('/store-selector', [StorefrontController::class, 'selector']);
        Route::get('/b2b/stores/{store}/storefront', [StorefrontController::class, 'showWholesale']);
        Route::get('/b2b/checkout/options', [StorefrontController::class, 'b2bCheckoutOptions']);
        Route::get('/b2b/product-mappings', [RetailWholesaleProductMappingController::class, 'index']);
        Route::put('/b2b/product-mappings/{sourceProduct}', [RetailWholesaleProductMappingController::class, 'upsert']);
        Route::post('/push/devices', [PushDeviceController::class, 'store']);
        Route::delete('/push/devices/{device}', [PushDeviceController::class, 'destroy']);
        Route::get('/admin/security/permissions', [SecurityController::class, 'permissions']);
        Route::get('/admin/security/roles', [SecurityController::class, 'roles']);
        Route::post('/admin/security/roles', [SecurityController::class, 'storeRole']);
        Route::patch('/admin/security/roles/{role}', [SecurityController::class, 'updateRole']);
        Route::post('/admin/security/roles/{role}/clone', [SecurityController::class, 'cloneRole']);
        Route::delete('/admin/security/roles/{role}', [SecurityController::class, 'destroyRole']);
        Route::get('/admin/security/users', [SecurityController::class, 'users']);
        Route::get('/admin/security/users/{user}', [SecurityController::class, 'showUser']);
        Route::put('/admin/security/users/{user}/roles', [SecurityController::class, 'updateUserRoles']);
        Route::patch('/admin/security/users/{user}/status', [SecurityController::class, 'updateUserStatus']);
        Route::get('/admin/reports/dashboard', [AdminReportController::class, 'dashboard']);
        Route::get('/admin/field-operations/address-quality', [AddressQualityController::class, 'index']);
        Route::get('/admin/field-operations/address-quality/{review}', [AddressQualityController::class, 'show'])->whereNumber('review');
        Route::post('/admin/field-operations/address-quality/{review}/confirm', [AddressQualityController::class, 'confirm'])->whereNumber('review');
        Route::post('/admin/field-operations/address-quality/{review}/reject', [AddressQualityController::class, 'reject'])->whereNumber('review');
        Route::post('/admin/field-operations/address-quality/{review}/reopen', [AddressQualityController::class, 'reopen'])->whereNumber('review');
        Route::get('/admin/driver-live-tracking/feed', [DriverLiveTrackingController::class, 'feed']);
        Route::get('/admin/field-operations/fleet/feed', [FleetLocationController::class, 'feed']);
        Route::post('/admin/field-operations/fleet/van-heartbeat', [FleetLocationController::class, 'vanHeartbeat'])
            ->middleware('throttle:120,1');
        Route::prefix('admin/field-operations/routing-policies')->group(function (): void {
            Route::post('/', [RoutingPolicyController::class, 'store']);
            Route::post('/{routingPolicy}/publish', [RoutingPolicyController::class, 'publish'])->whereNumber('routingPolicy');
            Route::post('/{routingPolicy}/simulate', [RoutingPolicyController::class, 'simulate'])->whereNumber('routingPolicy');
            Route::post('/{routingPolicy}/rollback', [RoutingPolicyController::class, 'rollback'])->whereNumber('routingPolicy');
        });
        Route::post('/admin/field-operations/vans', [VanRegistryController::class, 'store']);
        Route::post('/admin/field-operations/vans/{van}/assignments', [VanRegistryController::class, 'assign'])->whereNumber('van');
        Route::post('/admin/field-operations/vans/{van}/suspend', [VanRegistryController::class, 'suspend'])->whereNumber('van');
        Route::get('/admin/reports/{report}', [ManagementReportController::class, 'show'])
            ->whereIn('report', ['orders', 'products', 'customers', 'operations']);
        Route::get('/admin/b2b/accounts', [B2bAccountController::class, 'index']);
        Route::post('/admin/b2b/accounts', [B2bAccountController::class, 'store']);
        Route::patch('/admin/b2b/accounts/{account}/status', [B2bAccountController::class, 'updateStatus']);
        Route::get('/admin/inventory', [InventoryController::class, 'index']);
        Route::post('/admin/inventory/{inventory}/adjust', [InventoryController::class, 'adjust']);
        Route::get('/admin/b2b/prices', [B2bPricingController::class, 'index']);
        Route::put('/admin/b2b/prices', [B2bPricingController::class, 'upsert']);
        Route::get('/b2b/products', [B2bPricingController::class, 'products']);
        Route::get('/b2b/dashboard', [B2bReportController::class, 'dashboard']);
        Route::get('/b2b/reports/purchases', [B2bReportController::class, 'purchases']);
        Route::get('/b2b/products/top', [B2bReportController::class, 'topProducts']);
        Route::get('/b2b/products/{product}', [B2bPricingController::class, 'product']);
        Route::get('/b2b/account-summary', [B2bFinanceController::class, 'summary']);
        Route::get('/b2b/invoices', [B2bFinanceController::class, 'invoices']);
        Route::get('/b2b/invoices/{invoice}', [B2bFinanceController::class, 'invoice'])->whereNumber('invoice');
        Route::get('/b2b/invoices/{invoice}/download', [B2bFinanceController::class, 'invoiceDownload'])->whereNumber('invoice');
        Route::get('/b2b/account-statement', [B2bFinanceController::class, 'statement']);
        Route::get('/b2b/account-statement/export', [B2bFinanceController::class, 'statementExport']);

        Route::get('/profile', [CustomerProfileController::class, 'show']);
        Route::patch('/profile', [CustomerProfileController::class, 'update']);
        Route::get('/profile/addresses', [CustomerProfileController::class, 'addresses']);
        Route::get('/profile/addresses/{address}', [CustomerProfileController::class, 'showAddress'])->whereNumber('address');
        Route::post('/profile/addresses', [CustomerProfileController::class, 'storeAddress']);
        Route::patch('/profile/addresses/{address}', [CustomerProfileController::class, 'updateAddress']);
        Route::post('/profile/addresses/{address}/default', [CustomerProfileController::class, 'setDefaultAddress']);
        Route::delete('/profile/addresses/{address}', [CustomerProfileController::class, 'destroyAddress']);
        Route::get('/profile/favorites', [CustomerProfileController::class, 'favorites']);
        Route::post('/profile/favorites/{product}', [CustomerProfileController::class, 'addFavorite']);
        Route::delete('/profile/favorites/{product}', [CustomerProfileController::class, 'removeFavorite']);

        Route::post('/quote', QuoteController::class);
        Route::get('/checkout/options', RetailCheckoutOptionsController::class);
        Route::post('/checkout', CheckoutController::class);

        Route::get('/invoices', [CustomerInvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}', [CustomerInvoiceController::class, 'show'])->whereNumber('invoice');
        Route::get('/invoices/{invoice}/download', [CustomerInvoiceController::class, 'download'])->whereNumber('invoice');

        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::get('/b2b/orders', [OrderController::class, 'index']);
        Route::get('/b2b/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/status', [OrderController::class, 'transition']);
        Route::post('/admin/deliveries/assign', [DriverAssignmentController::class, 'assign']);
        Route::delete('/admin/deliveries/orders/{order}', [DriverAssignmentController::class, 'unassign'])->whereNumber('order');
        Route::post('/driver/location/heartbeat', [DriverLocationController::class, 'heartbeat'])
            ->middleware('throttle:120,1');

        Route::middleware('driver.location.fresh')->group(function (): void {
            Route::get('/driver/assignments', [DriverAssignmentController::class, 'index']);
            Route::get('/driver/assignments/{assignment}', [DriverAssignmentController::class, 'show'])->whereNumber('assignment');
            Route::get('/driver/assignments/{assignment}/invoice/download', [DriverAssignmentController::class, 'downloadInvoice'])->whereNumber('assignment');
            Route::post('/driver/assignments/{assignment}/status', [DriverAssignmentController::class, 'transition'])->whereNumber('assignment');
        });
    });
});
