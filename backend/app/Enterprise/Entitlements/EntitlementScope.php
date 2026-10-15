<?php

namespace App\Enterprise\Entitlements;

use InvalidArgumentException;

final readonly class EntitlementScope
{
    public const PLATFORM = 'platform';

    public const ORGANIZATION = 'organization';

    public const COMPANY = 'company';

    public const LEGAL_ENTITY = 'legal_entity';

    public const BUSINESS_UNIT = 'business_unit';

    public const STORE = 'store';

    private const TYPES = [
        self::PLATFORM,
        self::ORGANIZATION,
        self::COMPANY,
        self::LEGAL_ENTITY,
        self::BUSINESS_UNIT,
        self::STORE,
    ];

    public function __construct(
        public string $type,
        public ?int $id = null,
    ) {
        if (in_array($this->type, self::TYPES, true) === false) {
            throw new InvalidArgumentException("Unsupported entitlement scope [{$this->type}].");
        }

        if ($this->type === self::PLATFORM && $this->id !== null) {
            throw new InvalidArgumentException('Platform entitlement scope cannot have an ID.');
        }

        if ($this->type !== self::PLATFORM && ($this->id === null || $this->id < 1)) {
            throw new InvalidArgumentException("Entitlement scope [{$this->type}] requires a positive ID.");
        }
    }

    public static function platform(): self
    {
        return new self(self::PLATFORM);
    }

    public function key(): string
    {
        return $this->type.':'.($this->id ?? 0);
    }
}
