<?php

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppConnectionProfile;
use DateTimeImmutable;
use Throwable;

final class ProviderConnectionValidator implements ConnectionValidator
{
    public function __construct(private readonly WhatsAppProviderRegistry $providers) {}

    public function validate(WhatsAppConnectionProfile $profile): ConnectionValidationResult
    {
        try {
            return $this->providers->forProfile($profile)->validate($profile);
        } catch (Throwable) {
            return new ConnectionValidationResult(
                valid: false,
                health: ConnectionHealthStatus::PROVIDER_UNAVAILABLE,
                accountIdentity: null,
                phoneIdentity: null,
                providerAvailable: false,
                capabilities: [],
                diagnosticCode: 'provider_unavailable',
                message: 'WhatsApp provider validation is unavailable.',
                checkedAt: new DateTimeImmutable,
            );
        }
    }
}
