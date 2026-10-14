<?php

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppModuleState;
use Illuminate\Support\Facades\DB;

/** Mandatory final boundary for every future queue/listener/scheduler/provider send. */
final class OutboundExecution
{
    public function __construct(private readonly RuntimePolicy $policy) {}

    public function run(string $recipient, Capability $purpose, callable $send): mixed
    {
        if (! $this->policy->allowsOutbound($recipient, $purpose)) {
            throw new OutboundPaused('whatsapp.outbound_paused');
        }

        // Serialize send admission with global stop/state mutations. A committed
        // stop blocks every subsequent send, including already queued work.
        return DB::transaction(function () use ($recipient, $purpose, $send): mixed {
            $row = WhatsAppModuleState::query()->lockForUpdate()->find(1);
            if (! $this->policy->allowsOutbound($recipient, $purpose, ModuleState::fromModel($row))) {
                throw new OutboundPaused('whatsapp.outbound_paused');
            }

            return $send();
        });
    }
}
