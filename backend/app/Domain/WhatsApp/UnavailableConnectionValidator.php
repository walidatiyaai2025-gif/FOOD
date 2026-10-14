<?php

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppConnectionProfile;

final class UnavailableConnectionValidator implements ConnectionValidator
{
    public function validate(WhatsAppConnectionProfile $profile): ConnectionValidationResult
    {
        return new ConnectionValidationResult(
            valid: false,
            health: ConnectionHealthStatus::PROVIDER_UNAVAILABLE,
            accountIdentity: null,
            phoneIdentity: null,
            providerAvailable: false,
            capabilities: [],
            diagnosticCode: 'provider_adapter_unavailable',
            message: 'Provider validation adapter is not installed.',
            checkedAt: new \DateTimeImmutable,
        );
    }
}
