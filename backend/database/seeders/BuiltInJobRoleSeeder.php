<?php

namespace Database\Seeders;

use App\Services\BuiltInJobRoleCatalog;
use Illuminate\Database\Seeder;

final class BuiltInJobRoleSeeder extends Seeder
{
    public function run(): void
    {
        app(BuiltInJobRoleCatalog::class)->reconcile();
    }
}
