<?php

namespace App\Domain\SmartOrdering;

enum RuntimeState: string
{
    case DISABLED = 'DISABLED';
    case INTERNAL_TEST = 'INTERNAL_TEST';
    case PILOT = 'PILOT';
    case ENABLED = 'ENABLED';
    case EMERGENCY_STOP = 'EMERGENCY_STOP';
}
