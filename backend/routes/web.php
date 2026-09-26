<?php

use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminShellController;
use App\Http\Controllers\Admin\AppVersionController;
use App\Http\Controllers\Admin\B2bWorkspaceController;
use App\Http\Controllers\Admin\B2cWorkspaceController;
use App\Http\Controllers\Admin\MobileSettingsController;
use App\Http\Controllers\Admin\ReportController;
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

Route::get('/', fn () => response()->json([
    'name' => 'FOODEX',
    'phase' => 'bootstrap',
    'version' => trim((string) @file_get_contents(base_path('../VERSION'))),
]));

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
        Route::get('/b2b/{module}', [B2bWorkspaceController::class, 'show'])->name('b2b.module');
        Route::get('/b2c/dashboard', [B2cWorkspaceController::class, 'show'])->defaults('module', 'dashboard')->name('b2c.dashboard');
        Route::get('/b2c/{module}', [B2cWorkspaceController::class, 'show'])->name('b2c.module');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/security', [SecurityController::class, 'index'])->name('security.index');
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
