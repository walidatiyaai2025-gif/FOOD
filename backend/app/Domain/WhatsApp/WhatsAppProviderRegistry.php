<?php

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppConnectionProfile;

final class WhatsAppProviderRegistry
{
    public function __construct(private readonly MetaCloudWhatsAppProvider $meta) {}

    public function forProfile(WhatsAppConnectionProfile $profile): WhatsAppProvider
    {
        return match (strtolower((string) $profile->provider_type)) {
            'meta_cloud', 'meta', 'whatsapp_cloud' => $this->meta,
            default => throw new ProviderTransportException('Unsupported WhatsApp provider.', false),
        };
    }
}
