<?php

use App\Domain\Installer\InstallerChecklist;
use App\Domain\Installer\InstallState;
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
