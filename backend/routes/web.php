<?php

use App\Http\Controllers\Admin\AdminShellController;
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
        Route::get('/b2b/dashboard', [AdminShellController::class, 'b2b'])->name('b2b.dashboard');
        Route::get('/b2c/dashboard', [AdminShellController::class, 'b2c'])->name('b2c.dashboard');
    });
