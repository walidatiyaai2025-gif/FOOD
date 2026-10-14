<?php

namespace App\Providers;

use App\Domain\WhatsApp\CertificationAuthority;
use App\Domain\WhatsApp\ConnectionValidator;
use App\Domain\WhatsApp\ProviderConnectionValidator;
use App\Domain\WhatsApp\RepositoryCertificationAuthority;
use Illuminate\Support\ServiceProvider;

final class WhatsAppFoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No database queries, credentials, transports, listeners or scheduled work at boot.
        $this->app->bind(CertificationAuthority::class, RepositoryCertificationAuthority::class);
        $this->app->bind(ConnectionValidator::class, ProviderConnectionValidator::class);
    }
}
