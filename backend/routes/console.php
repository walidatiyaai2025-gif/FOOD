<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('foodex:version', function (): void {
    $this->info(trim((string) @file_get_contents(base_path('../VERSION'))));
});
