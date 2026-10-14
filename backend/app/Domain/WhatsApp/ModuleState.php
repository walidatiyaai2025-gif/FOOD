<?php

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppModuleState;
use Throwable;

final readonly class ModuleState
{
    /** @param array<string, bool> $capabilities
     * @param  list<string>  $testNumbers
     */
    public function __construct(
        public Lifecycle $lifecycle = Lifecycle::DISABLED,
        public bool $emergencyStop = true,
        public array $capabilities = [],
        public array $testNumbers = [],
        public int $revision = 0,
        public bool $available = false,
    ) {}

    public static function read(): self
    {
        try {
            return self::fromModel(WhatsAppModuleState::query()->find(1));
        } catch (Throwable) {
            // Optional schema/configuration must never become a core FOOD dependency.
            return new self;
        }
    }

    public static function fromModel(?WhatsAppModuleState $model): self
    {
        if ($model === null) {
            return new self;
        }

        $lifecycle = Lifecycle::tryFrom((string) $model->getAttribute('lifecycle'));
        $capabilities = $model->getAttribute('capabilities');
        $numbers = $model->getAttribute('test_numbers');
        $stop = $model->getRawOriginal('emergency_stop');
        if ($lifecycle === null || ! is_array($capabilities) || ! is_array($numbers)
            || ! in_array($stop, [0, 1, '0', '1', false, true], true)) {
            return new self;
        }
        foreach ($capabilities as $key => $value) {
            if (! is_string($key) || Capability::tryFrom($key) === null || ! is_bool($value)) {
                return new self;
            }
        }
        foreach ($numbers as $number) {
            if (! is_string($number) || ! self::validNumber($number)) {
                return new self;
            }
        }

        return new self($lifecycle, (bool) $stop, $capabilities, array_values($numbers), (int) $model->getAttribute('revision'), true);
    }

    public static function validNumber(string $number): bool
    {
        return preg_match('/^\+[1-9][0-9]{7,14}$/D', $number) === 1;
    }

    public function has(Capability $capability): bool
    {
        return $this->available && ($this->capabilities[$capability->value] ?? false) === true;
    }
}
