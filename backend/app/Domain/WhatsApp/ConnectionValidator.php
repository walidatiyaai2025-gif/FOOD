<?php

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppConnectionProfile;

interface ConnectionValidator
{
    /**
     * Validate one durable profile without sending a production message.
     *
     * #1402 replaces the default unavailable implementation with the real
     * provider adapter. Implementations must never leak credentials in results.
     */
    public function validate(WhatsAppConnectionProfile $profile): ConnectionValidationResult;
}
