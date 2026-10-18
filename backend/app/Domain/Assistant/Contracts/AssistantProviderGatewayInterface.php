<?php

namespace App\Domain\Assistant\Contracts;

use App\Domain\Assistant\Data\AssistantProviderCapabilities;
use App\Domain\Assistant\Data\AssistantProviderHealth;
use App\Domain\Assistant\Data\AssistantProviderRequest;
use App\Domain\Assistant\Data\AssistantProviderResult;

interface AssistantProviderGatewayInterface
{
    public function capabilities(): AssistantProviderCapabilities;

    public function complete(AssistantProviderRequest $request): AssistantProviderResult;

    public function health(): AssistantProviderHealth;
}
