<?php

use App\Http\Controllers\Api\V1\AdminReportController;
use App\Http\Controllers\Api\V1\AppVersionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\B2bAccountController;
use App\Http\Controllers\Api\V1\B2bFinanceController;
use App\Http\Controllers\Api\V1\B2bPricingController;
use App\Http\Controllers\Api\V1\B2bReportController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\CustomerProfileController;
use App\Http\Controllers\Api\V1\DriverAssignmentController;
use App\Http\Controllers\Api\V1\GuestCartController;
use App\Http\Controllers\Api\V1\GuestCatalogController;
use App\Http\Controllers\Api\V1\GuestStoreController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\ManagementReportController;
use App\Http\Controllers\Api\V1\MobileRuntimeController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PushDeviceController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\TranslationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::get('/version', fn () => response()->json([
        'api' => 'v1',
        'platform_version' => trim((string) @file_get_contents(base_path('../VERSION'))),
    ]));

    Route::get('/app-version', AppVersionController::class);
    Route::get('/mobile/runtime', MobileRuntimeController::class);
    Route::get('/translations/{locale}', TranslationController::class)->whereIn('locale', ['ar', 'en']);

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    Route::get('/stores', [GuestStoreController::class, 'index']);
    Route::get('/stores/{store}/categories', [GuestCatalogController::class, 'categories']);
    Route::get('/stores/{store}/products', [GuestCatalogController::class, 'products']);
    Route::get('/stores/{store}/offers', [GuestCatalogController::class, 'offers']);
    Route::get('/products/{product}', [GuestCatalogController::class, 'product']);

    Route::get('/cart', [GuestCartController::class, 'show']);
    Route::post('/cart/items', [GuestCartController::class, 'addItem']);
    Route::patch('/cart/items/{item}', [GuestCartController::class, 'updateItem']);
    Route::delete('/cart/items/{item}', [GuestCartController::class, 'removeItem']);

    Route::middleware(['auth:sanctum', 'active.user'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
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
        Route::get('/b2b/invoices', [B2bFinanceController::class, 'invoices']);
        Route::get('/b2b/invoices/{invoice}', [B2bFinanceController::class, 'invoice']);
        Route::get('/b2b/account-statement', [B2bFinanceController::class, 'statement']);

        Route::get('/profile', [CustomerProfileController::class, 'show']);
        Route::patch('/profile', [CustomerProfileController::class, 'update']);
        Route::get('/profile/addresses', [CustomerProfileController::class, 'addresses']);
        Route::post('/profile/addresses', [CustomerProfileController::class, 'storeAddress']);
        Route::patch('/profile/addresses/{address}', [CustomerProfileController::class, 'updateAddress']);
        Route::delete('/profile/addresses/{address}', [CustomerProfileController::class, 'destroyAddress']);
        Route::get('/profile/favorites', [CustomerProfileController::class, 'favorites']);
        Route::post('/profile/favorites/{product}', [CustomerProfileController::class, 'addFavorite']);
        Route::delete('/profile/favorites/{product}', [CustomerProfileController::class, 'removeFavorite']);

        Route::post('/checkout', CheckoutController::class);

        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::get('/b2b/orders', [OrderController::class, 'index']);
        Route::get('/b2b/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/status', [OrderController::class, 'transition']);
        Route::post('/admin/deliveries/assign', [DriverAssignmentController::class, 'assign']);
        Route::get('/driver/assignments', [DriverAssignmentController::class, 'index']);
        Route::post('/driver/assignments/{assignment}/status', [DriverAssignmentController::class, 'transition']);
    });
});
