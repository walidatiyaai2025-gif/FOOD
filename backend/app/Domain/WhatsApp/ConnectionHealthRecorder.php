<?php

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppConnectionProfile;

final class ConnectionHealthRecorder
{
    public function webhookSucceeded(WhatsAppConnectionProfile $profile): void
    {
        $profile->forceFill(['last_webhook_at' => now()])->save();
    }

    public function outboundSucceeded(WhatsAppConnectionProfile $profile): void
    {
        $profile->forceFill(['last_outbound_at' => now(), 'last_provider_error' => null])->save();
    }

    public function providerError(WhatsAppConnectionProfile $profile, string $safeMessage): void
    {
        $secret = $profile->credential_secret;
        if (is_string($secret) && $secret !== '') {
            $safeMessage = str_replace($secret, '[REDACTED]', $safeMessage);
        }
        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $safeMessage) ?? '';
        $profile->forceFill([
            'health_status' => ConnectionHealthStatus::DEGRADED,
            'status' => $profile->is_primary ? ConnectionProfileStatus::DEGRADED : $profile->status,
            'last_provider_error' => mb_substr(trim($message), 0, 500),
        ])->save();
    }
}
