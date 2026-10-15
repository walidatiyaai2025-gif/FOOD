<?php

namespace App\Enterprise\Health;

enum ModuleHealthStatus: string
{
    case NOT_INSTALLED = 'NOT_INSTALLED';
    case DISABLED = 'DISABLED';
    case HEALTHY = 'HEALTHY';
    case DEGRADED = 'DEGRADED';
    case ERROR = 'ERROR';
    case MAINTENANCE = 'MAINTENANCE';
}
