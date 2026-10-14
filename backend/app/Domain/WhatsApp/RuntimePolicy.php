<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use Throwable;

final class RuntimePolicy
{
    public function __construct(private readonly CertificationAuthority $certification) {}

    public function state(): ModuleState
    {
        return ModuleState::read();
    }

    public function evidenceReference(): ?string
    {
        try {
            $reference = $this->certification->validEvidenceReference();

            return is_string($reference) && trim($reference) !== '' ? $reference : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function isOperational(?ModuleState $state = null): bool
    {
        $state ??= $this->state();

        return $state->available && ($state->lifecycle === Lifecycle::INTERNAL_TEST
            || ($state->lifecycle === Lifecycle::ENABLED && $this->evidenceReference() !== null));
    }

    public function isEmergencyStopActive(): bool
    {
        return $this->state()->emergencyStop;
    }

    public function isTestNumberAllowed(string $number, ?ModuleState $state = null): bool
    {
        $state ??= $this->state();

        return ModuleState::validNumber($number) && in_array($number, $state->testNumbers, true);
    }

    public function allowsInbound(): bool
    {
        $state = $this->state();

        return $this->isOperational($state) && $state->has(Capability::INBOUND);
    }

    public function allowsAgentInbox(): bool
    {
        $state = $this->state();

        return $this->isOperational($state) && $state->has(Capability::AGENT_INBOX);
    }

    public function allowsOutbound(string $number, Capability $purpose = Capability::OUTBOUND, ?ModuleState $state = null): bool
    {
        $state ??= $this->state();
        if (! $purpose->isOutbound() || ! $this->isOperational($state) || $state->emergencyStop
            || ! $state->has(Capability::OUTBOUND) || ! $state->has($purpose) || ! ModuleState::validNumber($number)) {
            return false;
        }

        return $state->lifecycle === Lifecycle::ENABLED || $this->isTestNumberAllowed($number, $state);
    }

    public function allowsCampaigns(string $number): bool
    {
        return $this->allowsOutbound($number, Capability::CAMPAIGNS);
    }

    public function allowsCampaignManagement(): bool
    {
        $state = $this->state();

        return $this->isOperational($state)
            && ! $state->emergencyStop
            && $state->has(Capability::OUTBOUND)
            && $state->has(Capability::CAMPAIGNS);
    }

    public function allowsTransactionalNotifications(string $number): bool
    {
        return $this->allowsOutbound($number, Capability::TRANSACTIONAL);
    }

    public function permitsActivation(User $actor, ?ModuleState $state = null): bool
    {
        $state ??= $this->state();

        return $actor->is_active && $actor->hasRole('SUPER_ADMIN') && $actor->hasPermission('whatsapp.activate')
            && $state->available && $state->lifecycle === Lifecycle::GA_READY
            && ! $state->emergencyStop && $this->evidenceReference() !== null;
    }
}
