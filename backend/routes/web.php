<?php

use App\Http\Controllers\Admin\AdminShellController;
use App\Http\Controllers\Admin\AppVersionController;
use App\Http\Controllers\Admin\B2bWorkspaceController;
use App\Http\Controllers\Admin\B2cWorkspaceController;
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
    ->middleware('management.dashboard')
    ->group(function (): void {
        Route::get('/', [AdminShellController::class, 'index'])->name('index');
        Route::get('/b2b/dashboard', [B2bWorkspaceController::class, 'show'])->defaults('module', 'dashboard')->name('b2b.dashboard');
        Route::get('/b2b/{module}', [B2bWorkspaceController::class, 'show'])->name('b2b.module');
        Route::get('/b2c/dashboard', [B2cWorkspaceController::class, 'show'])->defaults('module', 'dashboard')->name('b2c.dashboard');
        Route::get('/b2c/{module}', [B2cWorkspaceController::class, 'show'])->name('b2c.module');
        Route::get('/settings/app-versions', [AppVersionController::class, 'index'])->name('app-versions.index');
        Route::post('/settings/app-versions', [AppVersionController::class, 'store'])->name('app-versions.store');
        Route::get('/settings/system-update', [SystemUpdateController::class, 'index'])->name('system-update.index');
        Route::post('/settings/system-update', [SystemUpdateController::class, 'store'])->name('system-update.store');
        Route::get('/settings/translations', [TranslationController::class, 'index'])->name('translations.index');
        Route::patch('/settings/translations/{translation}', [TranslationController::class, 'update'])->name('translations.update');
        Route::post('/settings/translations/{translation}/reset', [TranslationController::class, 'reset'])->name('translations.reset');
    });
