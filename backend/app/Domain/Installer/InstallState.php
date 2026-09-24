<?php

namespace App\Domain\Installer;

final class InstallState
{
    public function lockPath(): string
    {
        return base_path((string) config('foodex.install_lock', 'storage/app/system/installed.lock'));
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockPath());
    }
}
