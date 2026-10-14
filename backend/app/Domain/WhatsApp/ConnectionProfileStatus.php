<?php

namespace App\Domain\WhatsApp;

enum ConnectionProfileStatus: string
{
    case DRAFT = 'DRAFT';
    case VALIDATING = 'VALIDATING';
    case READY = 'READY';
    case ACTIVE = 'ACTIVE';
    case RECEIVE_ONLY = 'RECEIVE_ONLY';
    case INACTIVE = 'INACTIVE';
    case INVALID = 'INVALID';
    case DEGRADED = 'DEGRADED';
}
