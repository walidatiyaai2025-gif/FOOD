<?php

namespace App\Models;

use App\Domain\WhatsApp\ConnectionHealthStatus;
use App\Domain\WhatsApp\ConnectionProfileStatus;
use Illuminate\Database\Eloquent\Model;

final class WhatsAppConnectionProfile extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['credential_secret'];

    protected function casts(): array
    {
        return [
            'credential_secret' => 'encrypted',
            'provider_metadata' => 'array',
            'webhook_metadata' => 'array',
            'validation_metadata' => 'array',
            'status' => ConnectionProfileStatus::class,
            'health_status' => ConnectionHealthStatus::class,
            'is_primary' => 'boolean',
            'receive_only' => 'boolean',
            'configuration_revision' => 'integer',
            'last_checked_at' => 'datetime',
            'last_validated_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'last_outbound_at' => 'datetime',
        ];
    }

    public function secretConfigured(): bool
    {
        $raw = $this->getRawOriginal('credential_secret');

        return is_string($raw) && $raw !== '';
    }

    public function maskedSecret(): ?string
    {
        return $this->secretConfigured() ? '••••••••' : null;
    }

    /** @return array<string, mixed> */
    public function safeSnapshot(): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'display_phone_number' => $this->display_phone_number,
            'normalized_phone_number' => $this->normalized_phone_number,
            'provider_type' => $this->provider_type,
            'provider_phone_number_id' => $this->provider_phone_number_id,
            'provider_account_id' => $this->provider_account_id,
            'provider_app_id' => $this->provider_app_id,
            'provider_metadata' => $this->provider_metadata,
            'webhook_metadata' => $this->webhook_metadata,
            'status' => $this->status->value,
            'health_status' => $this->health_status->value,
            'is_primary' => $this->is_primary,
            'receive_only' => $this->receive_only,
            'configuration_revision' => $this->configuration_revision,
            'credential_configured' => $this->secretConfigured(),
        ];
    }
}
