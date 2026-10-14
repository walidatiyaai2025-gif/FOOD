<?php

namespace App\Domain\WhatsApp;

enum ConnectionHealthStatus: string
{
    case NEVER_TESTED = 'NEVER_TESTED';
    case READY = 'READY';
    case DEGRADED = 'DEGRADED';
    case INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    case WEBHOOK_UNCONFIRMED = 'WEBHOOK_UNCONFIRMED';
    case PROVIDER_UNAVAILABLE = 'PROVIDER_UNAVAILABLE';
    case INVALID = 'INVALID';
}
