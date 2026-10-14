<?php

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppConnectionProfile;

interface WhatsAppProvider
{
    public function validate(WhatsAppConnectionProfile $profile): ConnectionValidationResult;

    public function send(WhatsAppConnectionProfile $profile, OutboundMessage $message): ProviderSendResult;

    /** @param array<string, mixed> $payload
     * @return list<ProviderWebhookEvent>
     */
    public function normalizeWebhook(WhatsAppConnectionProfile $profile, array $payload): array;
}
