<?php

namespace App\Providers;

use App\Domain\WhatsApp\CertificationAuthority;
use App\Domain\WhatsApp\UncertifiedProgram;
use Illuminate\Support\ServiceProvider;

final class WhatsAppFoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No database queries, credentials, transports, listeners or scheduled work at boot.
        $this->app->bind(CertificationAuthority::class, UncertifiedProgram::class);\n        $this->app->bind(ConnectionValidator::class, UnavailableConnectionValidator::class);
    }
}
