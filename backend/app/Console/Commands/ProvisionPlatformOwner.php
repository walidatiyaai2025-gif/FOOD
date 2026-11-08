<?php

namespace App\Console\Commands;

use App\Services\PlatformOwnerProvisioner;
use Illuminate\Console\Command;
use Throwable;

final class ProvisionPlatformOwner extends Command
{
    protected $signature = 'foodex:platform-owner:provision';

    protected $description = 'Provision the internal platform owner from ephemeral deployment environment secrets';

    public function handle(PlatformOwnerProvisioner $provisioner): int
    {
        // Deployment/installer automation supplies these values to this process.
        // Never pass a password as a CLI argument or print it in CI logs.
        $email = (string) (getenv('FOODEX_INTERNAL_OWNER_EMAIL') ?: '');
        $password = (string) (getenv('FOODEX_INTERNAL_OWNER_PASSWORD') ?: '');
        if ($email === '' || $password === '') {
            $this->error('Owner deployment credentials are not supplied.');

            return self::FAILURE;
        }

        try {
            $provisioner->provision($email, $password);
        } catch (Throwable) {
            $this->error('Owner provisioning was rejected; review the secure deployment configuration.');

            return self::FAILURE;
        } finally {
            putenv('FOODEX_INTERNAL_OWNER_PASSWORD');
            unset($_ENV['FOODEX_INTERNAL_OWNER_PASSWORD'], $_SERVER['FOODEX_INTERNAL_OWNER_PASSWORD']);
        }

        $this->info('Internal platform owner provisioned. Remove bootstrap secrets from deployment environment.');

        return self::SUCCESS;
    }
}
