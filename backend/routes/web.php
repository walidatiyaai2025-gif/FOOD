<?php

use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminShellController;
use App\Http\Controllers\Admin\AppVersionController;
use App\Http\Controllers\Admin\B2bWorkspaceController;
use App\Http\Controllers\Admin\B2cWorkspaceController;
use App\Http\Controllers\Admin\BusinessManagementController;
use App\Http\Controllers\Admin\CatalogManagementController;
use App\Http\Controllers\Admin\LookupManagementController;
use App\Http\Controllers\Admin\MobileSettingsController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RetailStoreProvisioningController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SystemUpdateController;
use App\Http\Controllers\Admin\TranslationController;
use App\Http\Controllers\Installer\InstallerController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', fn () => redirect()->route('admin.b2c.login'));

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
        Route::get('/b2b/dashboard', [B2bWorkspaceController::class, 'show'])->defaults('module', 'dashboard')->name('b2b.dashboard');
        Route::post('/b2b/orders', [B2bWorkspaceController::class, 'storeOrder'])->name('b2b.orders.store');
        Route::patch('/b2b/orders/{order}', [B2bWorkspaceController::class, 'updateOrder'])->whereNumber('order')->name('b2b.orders.update');
        Route::post('/b2b/orders/{order}/status', [B2bWorkspaceController::class, 'transitionOrder'])->whereNumber('order')->name('b2b.orders.status');
        Route::post('/b2b/drivers/assign', [B2bWorkspaceController::class, 'assignDriver'])->name('b2b.drivers.assign');
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
        Route::put('/b2b/settings', [B2bWorkspaceController::class, 'saveSetting'])->name('b2b.settings.save');
        Route::get('/b2b/pricing-approvals', [B2bWorkspaceController::class, 'show'])->defaults('module', 'pricing')->name('b2b.pricing-approvals');
        Route::get('/b2b/settings-permissions', [B2bWorkspaceController::class, 'show'])->defaults('module', 'settings')->name('b2b.settings-permissions');
        Route::get('/b2b/{module}', [B2bWorkspaceController::class, 'show'])->name('b2b.module');
        Route::get('/b2c/dashboard', [B2cWorkspaceController::class, 'show'])->defaults('module', 'dashboard')->name('b2c.dashboard');
        Route::post('/b2c/orders', [B2cWorkspaceController::class, 'storeOrder'])->name('b2c.orders.store');
        Route::patch('/b2c/orders/{order}', [B2cWorkspaceController::class, 'updateOrder'])->whereNumber('order')->name('b2c.orders.update');
        Route::post('/b2c/orders/{order}/status', [B2cWorkspaceController::class, 'transitionOrder'])->whereNumber('order')->name('b2c.orders.status');
        Route::post('/b2c/drivers/assign', [B2cWorkspaceController::class, 'assignDriver'])->name('b2c.drivers.assign');
        Route::post('/b2c/inventory/{inventory}/adjust', [B2cWorkspaceController::class, 'adjustInventory'])->whereNumber('inventory')->name('b2c.inventory.adjust');
        Route::put('/b2c/settings', [B2cWorkspaceController::class, 'saveSetting'])->name('b2c.settings.save');
        Route::get('/b2c/storefront-preview', [B2cWorkspaceController::class, 'show'])->defaults('module', 'storefront')->name('b2c.storefront-preview');
        Route::get('/b2c/{module}', [B2cWorkspaceController::class, 'show'])->name('b2c.module');
        Route::get('/catalog', [CatalogManagementController::class, 'index'])->name('catalog.index');
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
        Route::post('/business/banners', [BusinessManagementController::class, 'storeBanner'])->name('business.banners.store');
        Route::patch('/business/banners/{banner}', [BusinessManagementController::class, 'updateBanner'])->name('business.banners.update');
        Route::delete('/business/banners/{banner}', [BusinessManagementController::class, 'destroyBanner'])->name('business.banners.destroy');
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
        Route::post('/catalog/stores', [CatalogManagementController::class, 'storeStore'])->name('catalog.stores.store');
        Route::patch('/catalog/stores/{store}', [CatalogManagementController::class, 'updateStore'])->name('catalog.stores.update');
        Route::get('/retail-stores', [RetailStoreProvisioningController::class, 'index'])->name('retail-stores.index');
        Route::post('/retail-stores', [RetailStoreProvisioningController::class, 'store'])->name('retail-stores.store');
        Route::patch('/retail-stores/{store}', [RetailStoreProvisioningController::class, 'update'])->name('retail-stores.update');
        Route::post('/retail-stores/{store}/roles', [RetailStoreProvisioningController::class, 'assignRole'])->name('retail-stores.roles.assign');
        Route::delete('/retail-stores/{store}/roles/{assignment}', [RetailStoreProvisioningController::class, 'removeRole'])->name('retail-stores.roles.remove');
        Route::post('/retail-stores/{store}/inspect', [RetailStoreProvisioningController::class, 'inspect'])->name('retail-stores.inspect');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/security', [SecurityController::class, 'index'])->name('security.index');
        Route::delete('/security/demo-data', [SecurityController::class, 'clearDemoData'])->name('security.demo-data.clear');
        Route::patch('/security/users/{user}/status', [SecurityController::class, 'updateUserStatus'])->name('security.users.status');
        Route::put('/security/users/{user}/roles', [SecurityController::class, 'updateUserRoles'])->name('security.users.roles');
        Route::post('/security/roles', [SecurityController::class, 'storeRole'])->name('security.roles.store');
        Route::patch('/security/roles/{role}', [SecurityController::class, 'updateRole'])->name('security.roles.update');
        Route::post('/security/roles/{role}/clone', [SecurityController::class, 'cloneRole'])->name('security.roles.clone');
        Route::delete('/security/roles/{role}', [SecurityController::class, 'destroyRole'])->name('security.roles.destroy');
        Route::get('/settings/app-versions', [AppVersionController::class, 'index'])->name('app-versions.index');
        Route::post('/settings/app-versions', [AppVersionController::class, 'store'])->name('app-versions.store');
        Route::get('/settings/mobile', [MobileSettingsController::class, 'index'])->name('mobile-settings.index');
        Route::put('/settings/mobile/app', [MobileSettingsController::class, 'updateApp'])->name('mobile-settings.app');
        Route::put('/settings/mobile/push', [MobileSettingsController::class, 'updateProvider'])->name('mobile-settings.push');
        Route::post('/settings/mobile/test-push', [MobileSettingsController::class, 'testPush'])->name('mobile-settings.test');
        Route::get('/settings/system-update', [SystemUpdateController::class, 'index'])->name('system-update.index');
        Route::post('/settings/system-update', [SystemUpdateController::class, 'store'])->name('system-update.store');
        Route::get('/settings/translations', [TranslationController::class, 'index'])->name('translations.index');
        Route::patch('/settings/translations/{translation}', [TranslationController::class, 'update'])->name('translations.update');
        Route::post('/settings/translations/{translation}/reset', [TranslationController::class, 'reset'])->name('translations.reset');
    });
