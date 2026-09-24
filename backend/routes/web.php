<?php

use App\Domain\Installer\InstallerChecklist;
use App\Domain\Installer\InstallState;
use App\Http\Controllers\Admin\AdminShellController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'FOODEX',
    'phase' => 'bootstrap',
    'version' => trim((string) @file_get_contents(base_path('../VERSION'))),
]));

Route::get('/install', function (InstallState $state) {
    abort_if($state->isInstalled(), 404);

    return view('install.index', ['steps' => InstallerChecklist::steps()]);
})->name('install.index');

Route::prefix('admin')
    ->name('admin.')
    ->middleware('management.dashboard')
    ->group(function (): void {
        Route::get('/', [AdminShellController::class, 'index'])->name('index');
        Route::get('/b2b/dashboard', [AdminShellController::class, 'b2b'])->name('b2b.dashboard');
        Route::get('/b2c/dashboard', [AdminShellController::class, 'b2c'])->name('b2c.dashboard');
    });
