<?php

namespace App\Domain\Installer;

final class InstallerChecklist
{
    public static function steps(): array
    {
        return [
            'Welcome',
            'Server Requirements',
            'File/Folder Permissions',
            'Database Configuration',
            'Test Database Connection',
            'Platform Information',
            'Create Super Admin',
            'Storage Configuration',
            'Cache/Queue Configuration',
            'Mail Configuration',
            'Run Migrations',
            'Seed Required Core Data',
            'Generate Application Keys',
            'Health Check',
            'Finish',
        ];
    }
}
