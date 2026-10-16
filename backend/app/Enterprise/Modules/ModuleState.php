<?php

namespace App\Enterprise\Modules;

enum ModuleState: string
{
    case NOT_INSTALLED = 'NOT_INSTALLED';
    case INSTALLED = 'INSTALLED';
    case DISABLED = 'DISABLED';
    case ENABLING = 'ENABLING';
    case ENABLED = 'ENABLED';
    case SUSPENDED = 'SUSPENDED';
    case MAINTENANCE = 'MAINTENANCE';
    case ERROR = 'ERROR';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::NOT_INSTALLED => [self::INSTALLED],
            self::INSTALLED => [self::DISABLED, self::NOT_INSTALLED],
            self::DISABLED => [self::ENABLING, self::NOT_INSTALLED],
            self::ENABLING => [self::ENABLED, self::DISABLED, self::ERROR],
            self::ENABLED => [self::DISABLED, self::SUSPENDED, self::MAINTENANCE, self::ERROR],
            self::SUSPENDED => [self::ENABLED, self::DISABLED, self::MAINTENANCE, self::ERROR],
            self::MAINTENANCE => [self::ENABLED, self::SUSPENDED, self::DISABLED, self::ERROR],
            self::ERROR => [self::DISABLED, self::ENABLING, self::MAINTENANCE],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isOperational(): bool
    {
        return $this === self::ENABLED;
    }

    public function isActiveDependencyConsumer(): bool
    {
        return in_array($this, [
            self::ENABLING,
            self::ENABLED,
            self::SUSPENDED,
            self::MAINTENANCE,
        ], true);
    }
}
