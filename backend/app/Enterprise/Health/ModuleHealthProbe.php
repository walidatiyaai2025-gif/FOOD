<?php

namespace App\Enterprise\Health;

interface ModuleHealthProbe
{
    public function check(): ModuleHealthCheck;
}
