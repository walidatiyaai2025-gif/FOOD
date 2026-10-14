<?php

namespace App\Domain\WhatsApp;

enum Lifecycle: string
{
    case DISABLED = 'DISABLED';
    case INTERNAL_TEST = 'INTERNAL_TEST';
    case PILOT = 'PILOT';
    case GA_READY = 'GA_READY';
    case ENABLED = 'ENABLED';
}
